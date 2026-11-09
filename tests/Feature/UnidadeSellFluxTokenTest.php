<?php

namespace Tests\Feature;

use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnidadeSellFluxTokenTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-teste-sellflux-123';

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->master = User::factory()->create(['role' => User::ROLE_MASTER]);
    }

    private function fakeProject(int $id = 28874): void
    {
        Http::fake(['*/api/v1/assistant/structure' => Http::response(['project_id' => $id])]);
    }

    private function unitWithToken(): Unidade
    {
        $unit = Unidade::query()->create(['nome' => 'Centro']);
        $unit->forceFill([
            'sellflux_token' => self::TOKEN,
            'sellflux_project_id' => '28874',
        ])->save();

        return $unit;
    }

    public function test_master_saves_the_token_encrypted_and_it_is_checked_on_sellflux(): void
    {
        $this->fakeProject();

        $this->actingAs($this->master)->post(route('admin.units.store'), [
            'nome' => 'Centro',
            'sellflux_token' => self::TOKEN,
        ])->assertSessionHasNoErrors()->assertSessionMissing('warning');

        $unit = Unidade::query()->where('nome', 'Centro')->firstOrFail();
        $stored = DB::table('unidades')->where('id', $unit->id)->value('sellflux_token');

        $this->assertNotSame(self::TOKEN, $stored);
        $this->assertStringNotContainsString(self::TOKEN, (string) $stored);
        $this->assertSame(self::TOKEN, Crypt::decryptString((string) $stored));
        $this->assertSame(self::TOKEN, $unit->sellflux_token);
        $this->assertSame('28874', $unit->sellflux_project_id);
        $this->assertSame($this->master->id, (int) $unit->sellflux_token_updated_by);
        $this->assertNotNull($unit->sellflux_token_updated_at);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
    }

    public function test_unit_can_still_be_created_without_a_token(): void
    {
        Http::fake();

        $this->actingAs($this->master)
            ->post(route('admin.units.store'), ['nome' => 'Centro'])
            ->assertSessionHasNoErrors();

        $this->assertFalse(Unidade::query()->where('nome', 'Centro')->firstOrFail()->hasSellfluxToken());
        Http::assertNothingSent();
    }

    public function test_a_token_refused_by_sellflux_is_not_saved(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->actingAs($this->master)->post(route('admin.units.store'), [
            'nome' => 'Centro',
            'sellflux_token' => self::TOKEN,
        ])->assertSessionHasErrors('sellflux_token');

        $this->assertDatabaseMissing('unidades', ['nome' => 'Centro']);
    }

    public function test_a_token_with_spaces_is_rejected_without_calling_sellflux(): void
    {
        Http::fake();

        $this->actingAs($this->master)->post(route('admin.units.store'), [
            'nome' => 'Centro',
            'sellflux_token' => 'Bearer '.self::TOKEN,
        ])->assertSessionHasErrors('sellflux_token');

        Http::assertNothingSent();
    }

    public function test_token_is_saved_with_a_warning_when_sellflux_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error'));

        $this->actingAs($this->master)->post(route('admin.units.store'), [
            'nome' => 'Centro',
            'sellflux_token' => self::TOKEN,
        ])->assertSessionHasNoErrors()->assertSessionHas('warning');

        $unit = Unidade::query()->where('nome', 'Centro')->firstOrFail();

        $this->assertSame(self::TOKEN, $unit->sellflux_token);
        $this->assertNull($unit->sellflux_project_id);
    }

    public function test_empty_field_keeps_the_current_token(): void
    {
        Http::fake();
        $unit = $this->unitWithToken();

        $this->actingAs($this->master)->put(route('admin.units.update', $unit), [
            'nome' => 'Centro Novo',
            'sellflux_token' => '',
            'remover_sellflux_token' => false,
        ])->assertSessionHasNoErrors();

        $unit->refresh();
        $this->assertSame('Centro Novo', $unit->nome);
        $this->assertSame(self::TOKEN, $unit->sellflux_token);
        $this->assertNull($unit->sellflux_token_updated_at);
        Http::assertNothingSent();
    }

    public function test_token_can_be_replaced(): void
    {
        $this->fakeProject(32242);
        $unit = $this->unitWithToken();

        $this->actingAs($this->master)->put(route('admin.units.update', $unit), [
            'nome' => 'Centro',
            'sellflux_token' => 'token-novo-456',
        ])->assertSessionHasNoErrors();

        $unit->refresh();
        $this->assertSame('token-novo-456', $unit->sellflux_token);
        $this->assertSame('32242', $unit->sellflux_project_id);
    }

    public function test_token_is_removed_only_on_explicit_request(): void
    {
        Http::fake();
        $unit = $this->unitWithToken();

        $this->actingAs($this->master)->put(route('admin.units.update', $unit), [
            'nome' => 'Centro',
            'remover_sellflux_token' => true,
        ])->assertSessionHasNoErrors();

        $unit->refresh();
        $this->assertFalse($unit->hasSellfluxToken());
        $this->assertNull($unit->sellflux_project_id);
        $this->assertSame($this->master->id, (int) $unit->sellflux_token_updated_by);
    }

    public function test_failed_validation_does_not_keep_the_token_in_the_session(): void
    {
        Http::fake();
        Unidade::query()->create(['nome' => 'Centro']);

        $response = $this->actingAs($this->master)->post(route('admin.units.store'), [
            'nome' => 'Centro',
            'sellflux_token' => self::TOKEN,
        ]);

        $response->assertSessionHasErrors('nome');
        $this->assertSame('Centro', session('_old_input.nome'));
        $this->assertNull(session('_old_input.sellflux_token'));
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode(session()->all()));
    }

    public function test_pages_never_receive_the_token(): void
    {
        $unit = $this->unitWithToken();
        $ciphertext = (string) DB::table('unidades')->where('id', $unit->id)->value('sellflux_token');

        foreach (['admin.units.index', 'relatorios.index', 'home', 'leads.index', 'vendedores.index'] as $route) {
            $this->actingAs($this->master)->get(route($route))
                ->assertOk()
                ->assertDontSee(self::TOKEN, false)
                ->assertDontSee($ciphertext, false)
                ->assertDontSee('"sellflux_token"', false);
        }
    }

    public function test_units_page_only_says_whether_the_token_is_configured(): void
    {
        $this->unitWithToken();

        $this->actingAs($this->master)->get(route('admin.units.index'))->assertInertia(
            fn (Assert $page) => $page->component('admin/units')
                ->where('units.0.sellflux_configurado', true)
                ->where('units.0.sellflux_projeto', '28874')
                ->missing('units.0.sellflux_token'),
        );
    }

    public function test_serializing_a_unit_hides_the_token(): void
    {
        $this->assertArrayNotHasKey('sellflux_token', $this->unitWithToken()->refresh()->toArray());
    }

    public function test_connection_test_uses_the_saved_token_and_reports_success(): void
    {
        Http::fake([
            '*/api/v1/assistant/structure' => Http::response(['project_id' => 32242]),
            '*/api/v1/contact/leads*' => Http::response(['data' => [['name' => 'Pessoa Sigilosa']]]),
            '*/api/v1/contact/chats*' => Http::response(['data' => []]),
        ]);
        $unit = $this->unitWithToken();

        $this->actingAs($this->master)
            ->post(route('admin.units.sellflux-test', $unit))
            ->assertSessionHas('success', 'Conexão com a SellFlux funcionando: token aceito, leads e atendimentos acessíveis (projeto 32242).')
            ->assertSessionMissing('warning');

        $this->assertSame('32242', $unit->refresh()->sellflux_project_id);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
        $this->assertStringNotContainsString('Pessoa Sigilosa', (string) json_encode(session()->all()));
    }

    public function test_connection_test_reports_a_refused_token_without_removing_it(): void
    {
        Http::fake(['*' => Http::response([], 401)]);
        $unit = $this->unitWithToken();

        $this->actingAs($this->master)
            ->post(route('admin.units.sellflux-test', $unit))
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'recusou o token'))
            ->assertSessionMissing('success');

        $this->assertSame(self::TOKEN, $unit->refresh()->sellflux_token);
    }

    public function test_connection_test_says_which_read_failed(): void
    {
        Http::fake([
            '*/api/v1/assistant/structure' => Http::response(['project_id' => 28874]),
            '*/api/v1/contact/leads*' => Http::response(['data' => []]),
            '*/api/v1/contact/chats*' => Http::response([], 403),
        ]);
        $unit = $this->unitWithToken();

        $this->actingAs($this->master)
            ->post(route('admin.units.sellflux-test', $unit))
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'os atendimentos')
                && str_contains($message, '403'))
            ->assertSessionMissing('success');
    }

    public function test_connection_test_tells_a_server_error_apart_from_a_refused_token(): void
    {
        Http::fake(['*' => Http::response(['msg' => 'Chave não encontrada'], 500)]);
        $unit = $this->unitWithToken();

        $this->actingAs($this->master)
            ->post(route('admin.units.sellflux-test', $unit))
            ->assertSessionHas(
                'warning',
                'O token não foi recusado, mas a SellFlux retornou um erro ao consultar dados do projeto (HTTP 500: Chave não encontrada).',
            );
    }

    public function test_connection_test_reports_when_sellflux_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error'));
        $unit = $this->unitWithToken();

        $this->actingAs($this->master)
            ->post(route('admin.units.sellflux-test', $unit))
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'Não foi possível conectar'));
    }

    public function test_connection_test_handles_missing_or_unreadable_tokens(): void
    {
        Http::fake();
        $withoutToken = Unidade::query()->create(['nome' => 'Sem token']);
        $unreadable = Unidade::query()->create(['nome' => 'Ilegível']);
        DB::table('unidades')->where('id', $unreadable->id)->update(['sellflux_token' => 'valor-invalido']);

        $this->actingAs($this->master)
            ->post(route('admin.units.sellflux-test', $withoutToken))
            ->assertSessionHas('warning', 'Esta unidade não tem token da SellFlux.');

        $this->actingAs($this->master)
            ->post(route('admin.units.sellflux-test', $unreadable))
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'Cadastre-o de novo'));

        Http::assertNothingSent();
    }

    public function test_only_master_can_test_the_connection(): void
    {
        Http::fake();
        $unit = $this->unitWithToken();

        $this->actingAs(User::factory()->create())
            ->post(route('admin.units.sellflux-test', $unit))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_only_master_can_change_the_token(): void
    {
        Http::fake();
        $unit = $this->unitWithToken();
        $seller = User::factory()->create();

        $this->actingAs($seller)->put(route('admin.units.update', $unit), [
            'nome' => 'Centro',
            'sellflux_token' => 'token-de-invasor',
        ])->assertForbidden();

        $this->assertSame(self::TOKEN, $unit->refresh()->sellflux_token);
        Http::assertNothingSent();
    }
}
