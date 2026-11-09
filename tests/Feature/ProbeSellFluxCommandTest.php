<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProbeSellFluxCommandTest extends TestCase
{
    use RefreshDatabase;

    private const LEAD_NAME = 'Nome Pessoal Sigiloso';

    private const LEAD_EMAIL = 'pessoa.sigilosa@example.test';

    private const SELLER_NAME = 'Atendente Sigilosa';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMockingConsoleOutput();
    }

    private function unit(?string $token = 'token-de-teste'): Unidade
    {
        $unit = Unidade::query()->create(['nome' => 'Centro']);
        $unit->forceFill(['sellflux_token' => $token])->save();

        return $unit;
    }

    private function leadWithoutSeller(Unidade $unit): Lead
    {
        return Lead::query()->create([
            'unidade_id' => $unit->id,
            'data' => now(),
            'responsavel' => 'Responsável',
            'telefone' => '(11) 99999-0000',
            'status_id' => LeadStatus::query()->firstOrCreate(['nome' => 'Novo'], ['ativo' => true])->id,
        ]);
    }

    private function fakeSellFlux(): void
    {
        Http::fake([
            '*/api/v1/contact/leads/321/links*' => Http::response(['attendance' => []]),
            '*/api/v1/contact/leads*' => Http::response([
                'data' => [[
                    'id' => 321,
                    'name' => self::LEAD_NAME,
                    'email' => self::LEAD_EMAIL,
                    'phone' => '+5511999990000',
                ]],
            ]),
            '*/api/v1/lead/project*' => Http::response(['data' => []]),
            '*/api/v1/contact/chats*' => Http::response([
                'data' => [[
                    'id' => 55,
                    'status' => 2,
                    'attendant_kind' => 'user',
                    'user_attending' => ['id' => 7, 'name' => self::SELLER_NAME],
                ]],
            ]),
        ]);
    }

    public function test_it_finds_the_lead_by_phone_and_prints_no_personal_data(): void
    {
        $this->fakeSellFlux();
        $unit = $this->unit();
        $this->leadWithoutSeller($unit);

        $exitCode = Artisan::call('sellflux:probe', ['unidade' => $unit->id]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('ddi_ddd_numero', $output);
        $this->assertStringContainsString('Chats com atendente humano', $output);
        $this->assertStringContainsString('"attendant_kind": "user"', $output);

        foreach ([self::LEAD_NAME, self::LEAD_EMAIL, self::SELLER_NAME, '11999990000', '5511999990000', 'token-de-teste'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/contact/chats')
            && $request['lead_id'] === 321
            && ($request->data()['attendant'] ?? null) === 'user');
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_it_ignores_results_with_a_different_phone(): void
    {
        Http::fake([
            '*/api/v1/contact/leads*' => Http::response(['data' => [['id' => 9, 'phone' => '11888887777']]]),
            '*/api/v1/lead/project*' => Http::response(['data' => []]),
        ]);
        $unit = $this->unit();
        $this->leadWithoutSeller($unit);

        Artisan::call('sellflux:probe', ['unidade' => $unit->id]);

        $this->assertStringContainsString('Nenhum resultado com o mesmo telefone', Artisan::output());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/contact/chats'));
    }

    public function test_it_reports_a_unit_without_token(): void
    {
        Http::fake();
        $unit = Unidade::query()->create(['nome' => 'Centro']);

        $this->assertSame(1, Artisan::call('sellflux:probe', ['unidade' => $unit->id]));
        Http::assertNothingSent();
    }

    public function test_failures_do_not_expose_the_searched_phone(): void
    {
        Http::fake(['*' => Http::response([], 401)]);
        $unit = $this->unit();
        $this->leadWithoutSeller($unit);

        $this->assertSame(0, Artisan::call('sellflux:probe', ['unidade' => $unit->id]));

        $output = Artisan::output();
        $this->assertStringContainsString('401', $output);
        $this->assertStringNotContainsString('999990000', $output);
    }
}
