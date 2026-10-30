<?php

namespace Tests\Feature;

use App\Models\LeadStatus;
use App\Models\Unidade;
use App\Models\Vendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiLeadCreationTest extends TestCase
{
    use RefreshDatabase;

    private const API_TOKEN = 'test-lead-api-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.lead_api.token' => self::API_TOKEN]);
    }

    public function test_lead_can_be_created_with_a_bearer_token(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $seller = Vendedor::query()->create([
            'unidade_id' => $unit->id,
            'nome' => 'Vendedor Norte',
        ]);
        $status = LeadStatus::query()->create(['nome' => 'Novo']);

        $response = $this->withToken(self::API_TOKEN)
            ->postJson(route('api.leads.store'), [
                'unidade_id' => $unit->id,
                'vendedor_id' => $seller->id,
                'responsavel' => 'Família da API',
                'telefone' => '11999990000',
                'email' => 'familia@example.com',
                'aluno' => 'Estudante',
                'canal_mkt' => 'Site',
                'utm_source' => 'busca',
                'origem' => 'Tentativa de sobrescrita',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Lead criado com sucesso.')
            ->assertJsonPath('data.unidade_id', $unit->id)
            ->assertJsonPath('data.status_id', $status->id)
            ->assertJsonPath('data.origem', 'API');

        $this->assertDatabaseHas('leads', [
            'unidade_id' => $unit->id,
            'vendedor_id' => $seller->id,
            'responsavel' => 'Família da API',
            'email' => 'familia@example.com',
            'status_id' => $status->id,
            'origem' => 'API',
        ]);
        $this->assertDatabaseHas('lead_status_histories', [
            'lead_id' => $response->json('data.id'),
            'from_status_id' => null,
            'to_status_id' => $status->id,
            'changed_by' => null,
        ]);
    }

    public function test_api_rejects_missing_or_invalid_bearer_tokens(): void
    {
        $this->postJson(route('api.leads.store'), [])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer');

        $this->withToken('invalid-token')
            ->postJson(route('api.leads.store'), [])
            ->assertUnauthorized();

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_api_returns_service_unavailable_when_token_is_not_configured(): void
    {
        config(['services.lead_api.token' => null]);

        $this->withToken(self::API_TOKEN)
            ->postJson(route('api.leads.store'), [])
            ->assertServiceUnavailable();
    }

    public function test_api_validates_unit_and_seller_belonging_to_that_unit(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $seller = Vendedor::query()->create([
            'unidade_id' => $otherUnit->id,
            'nome' => 'Vendedor Sul',
        ]);

        $this->withToken(self::API_TOKEN)
            ->postJson(route('api.leads.store'), [
                'unidade_id' => $unit->id,
                'vendedor_id' => $seller->id,
                'responsavel' => 'Família da API',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('vendedor_id');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_api_does_not_create_lead_when_initial_status_is_missing(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);

        $this->withToken(self::API_TOKEN)
            ->postJson(route('api.leads.store'), [
                'unidade_id' => $unit->id,
                'responsavel' => 'Família da API',
            ])
            ->assertServiceUnavailable();

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_api_rejects_invalid_lead_data(): void
    {
        Unidade::query()->create(['nome' => 'Unidade Norte']);
        LeadStatus::query()->create(['nome' => 'Novo']);

        $this->withToken(self::API_TOKEN)
            ->postJson(route('api.leads.store'), [
                'unidade_id' => 999,
                'responsavel' => '',
                'email' => 'not-an-email',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unidade_id', 'responsavel', 'email']);

        $this->assertDatabaseCount('leads', 0);
    }
}
