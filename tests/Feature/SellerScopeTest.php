<?php

namespace Tests\Feature;

use App\Models\Agendamento;
use App\Models\Campanha;
use App\Models\Lead;
use App\Models\LeadHistory;
use App\Models\LeadStatus;
use App\Models\MetaVendedor;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Individual seller scope: D-021 to D-026.
 */
class SellerScopeTest extends TestCase
{
    use RefreshDatabase;

    private Unidade $unit;

    private LeadStatus $status;

    private User $alice;

    private User $bruno;

    private Vendedor $aliceSeller;

    private Vendedor $brunoSeller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unit = Unidade::query()->create(['nome' => 'Unidade Centro']);
        $this->status = LeadStatus::query()->create(['nome' => 'Atendendo', 'ordem' => 1]);
        LeadStatus::query()->create(['nome' => 'Agendado', 'ordem' => 2]);
        [$this->alice, $this->aliceSeller] = $this->seller('Alice');
        [$this->bruno, $this->brunoSeller] = $this->seller('Bruno');
    }

    public function test_seller_only_sees_own_leads_and_the_unit_queue(): void
    {
        $own = $this->lead('Família Alice', $this->aliceSeller);
        $other = $this->lead('Família Bruno', $this->brunoSeller);
        $queue = $this->lead('Família na fila', null);

        $this->actingAs($this->alice)
            ->get(route('leads.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('visitas/leads')
                ->where('sellerScoped', true)
                ->where('leads', fn ($leads) => collect($leads)->pluck('id')->sort()->values()->all()
                    === collect([$own->id, $queue->id])->sort()->values()->all()));

        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $gestor->unidades()->attach($this->unit);

        $this->actingAs($gestor)
            ->get(route('leads.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sellerScoped', false)
                ->has('leads', 3));

        $this->assertNotNull($other->id);
    }

    public function test_seller_cannot_act_on_another_sellers_records(): void
    {
        $other = $this->lead('Família Bruno', $this->brunoSeller);
        $appointment = Agendamento::query()->create([
            'unidade_id' => $this->unit->id,
            'lead_id' => $other->id,
            'vendedor_id' => $this->brunoSeller->id,
            'data' => now()->toDateString(),
            'horario' => '10:00',
            'responsavel' => 'Família Bruno',
            'status' => 'Agendado',
        ]);
        $visit = Visita::query()->create([
            'unidade_id' => $this->unit->id,
            'lead_id' => $other->id,
            'vendedor_id' => $this->brunoSeller->id,
            'data' => now()->toDateString(),
            'responsavel' => 'Família Bruno',
            'status' => 'Aguardando',
        ]);

        $this->actingAs($this->alice);
        $this->put(route('leads.update', $other), $this->leadPayload($other))->assertNotFound();
        $this->post(route('leads.schedule', $other), ['data' => '2026-12-01', 'horario' => '09:00'])->assertNotFound();
        $this->post(route('leads.transfer', $other), ['vendedor_id' => $this->aliceSeller->id])->assertNotFound();
        $this->delete(route('agendamentos.destroy', $appointment))->assertNotFound();
        $this->post(route('agendamentos.convert', $appointment))->assertNotFound();
        $this->delete(route('visitas.destroy', $visit))->assertNotFound();

        $this->get(route('agendamentos.index'))
            ->assertInertia(fn (Assert $page) => $page->has('appointments', 0));
        $this->get(route('visitas.index'))
            ->assertInertia(fn (Assert $page) => $page->has('visits', 0));
        $this->assertSame($this->brunoSeller->id, $other->fresh()->vendedor_id);
    }

    public function test_seller_claims_a_queue_lead_and_the_assignment_is_recorded(): void
    {
        $queue = $this->lead('Família na fila', null);

        $this->actingAs($this->alice)
            ->post(route('leads.claim', $queue))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->aliceSeller->id, $queue->fresh()->vendedor_id);
        $history = LeadHistory::query()->where('lead_id', $queue->id)->sole();
        $this->assertSame($this->alice->id, $history->user_id);
        $this->assertSame([['field' => 'Vendedor', 'old' => null, 'new' => 'Alice']], $history->changes);

        $this->actingAs($this->bruno)
            ->post(route('leads.claim', $queue))
            ->assertNotFound();
    }

    public function test_working_on_a_queue_lead_assigns_it_to_the_seller(): void
    {
        $queue = $this->lead('Família na fila', null);

        $this->actingAs($this->alice)
            ->put(route('leads.update', $queue), $this->leadPayload($queue, ['observacoes' => 'Primeiro contato']))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->aliceSeller->id, $queue->fresh()->vendedor_id);
        $this->assertTrue(collect(LeadHistory::query()->where('lead_id', $queue->id)->sole()->changes)
            ->contains(fn (array $change) => $change['field'] === 'Vendedor' && $change['new'] === 'Alice'));

        $otherQueue = $this->lead('Outra família na fila', null);
        $this->actingAs($this->bruno)
            ->post(route('leads.schedule', $otherQueue), ['data' => '2026-12-01', 'horario' => '09:00'])
            ->assertRedirect();

        $this->assertSame($this->brunoSeller->id, $otherQueue->fresh()->vendedor_id);
        $this->assertSame($this->brunoSeller->id, $otherQueue->agendamentos()->sole()->vendedor_id);
    }

    public function test_transfer_is_recorded_and_moves_open_appointments(): void
    {
        $lead = $this->lead('Família Alice', $this->aliceSeller);
        $open = Agendamento::query()->create([
            'unidade_id' => $this->unit->id,
            'lead_id' => $lead->id,
            'vendedor_id' => $this->aliceSeller->id,
            'data' => now()->addDay()->toDateString(),
            'horario' => '10:00',
            'responsavel' => 'Família Alice',
            'status' => 'Agendado',
        ]);
        $done = Agendamento::query()->create([
            'unidade_id' => $this->unit->id,
            'lead_id' => $lead->id,
            'vendedor_id' => $this->aliceSeller->id,
            'data' => now()->subDay()->toDateString(),
            'horario' => '10:00',
            'responsavel' => 'Família Alice',
            'status' => 'Não compareceu',
        ]);

        $this->actingAs($this->alice)
            ->post(route('leads.transfer', $lead), [
                'vendedor_id' => $this->brunoSeller->id,
                'motivo' => 'Família pediu atendimento à tarde',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->brunoSeller->id, $lead->fresh()->vendedor_id);
        $this->assertSame($this->brunoSeller->id, $open->fresh()->vendedor_id);
        $this->assertSame($this->aliceSeller->id, $done->fresh()->vendedor_id);
        $this->assertSame([
            ['field' => 'Vendedor', 'old' => 'Alice', 'new' => 'Bruno'],
            ['field' => 'Motivo da transferência', 'old' => null, 'new' => 'Família pediu atendimento à tarde'],
        ], LeadHistory::query()->where('lead_id', $lead->id)->sole()->changes);

        // The lead left Alice's scope and is now visible to Bruno with the history.
        $this->actingAs($this->alice)
            ->put(route('leads.update', $lead), $this->leadPayload($lead->fresh()))
            ->assertNotFound();
        $this->actingAs($this->bruno)
            ->get(route('leads.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('leads.0.id', $lead->id)
                ->where('leads.0.edit_histories.0.changes.0.new', 'Bruno'));
    }

    public function test_transfer_target_must_be_an_active_seller_of_the_lead_unit(): void
    {
        $lead = $this->lead('Família Alice', $this->aliceSeller);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $foreign = Vendedor::query()->create(['unidade_id' => $otherUnit->id, 'nome' => 'Carla']);

        $this->actingAs($this->alice)
            ->post(route('leads.transfer', $lead), ['vendedor_id' => $foreign->id])
            ->assertSessionHasErrors('vendedor_id');

        $this->assertSame($this->aliceSeller->id, $lead->fresh()->vendedor_id);
    }

    public function test_reports_and_campaigns_only_show_the_sellers_own_numbers(): void
    {
        $this->lead('Família Alice', $this->aliceSeller);
        $this->lead('Família Bruno 1', $this->brunoSeller);
        $this->lead('Família Bruno 2', $this->brunoSeller);
        $campaign = Campanha::query()->create([
            'unidade_id' => $this->unit->id,
            'inicio' => now()->startOfMonth()->toDateString(),
            'fim' => now()->endOfMonth()->toDateString(),
            'meta' => 50,
        ]);
        MetaVendedor::query()->create(['campanha_id' => $campaign->id, 'vendedor_id' => $this->aliceSeller->id, 'meta' => 7]);
        MetaVendedor::query()->create(['campanha_id' => $campaign->id, 'vendedor_id' => $this->brunoSeller->id, 'meta' => 9]);
        $today = now()->toDateString();

        $this->actingAs($this->alice)
            ->get(route('relatorios.index', ['inicio' => $today, 'fim' => $today]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.all.leads', 1)
                ->has('report.bySeller', 1)
                ->where('report.bySeller.0.seller_id', $this->aliceSeller->id));

        $this->get(route('relatorios.csv', ['vendedor_id' => $this->brunoSeller->id]))->assertNotFound();

        $this->get(route('campanhas.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('units.0.campaign.meta', 7)
                ->has('units.0.sellers', 1)
                ->where('units.0.sellers.0.id', $this->aliceSeller->id)
                ->where('units.0.summary.leads', 1));

        $this->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('painel-vendedor')
                ->where('performance.leads', 1)
                ->where('goals.0.goal', 7)
                ->where('followUps.leads.0.responsavel', 'Família Alice')
                ->where('followUps.total', 1)
                ->where('queue.total', 0));
    }

    public function test_master_links_account_to_imported_seller_and_merges_records(): void
    {
        $imported = Vendedor::query()->create(['unidade_id' => $this->unit->id, 'nome' => 'Alice (importada)']);
        $oldLead = $this->lead('Família histórica', $imported);
        $newLead = $this->lead('Família nova', $this->aliceSeller);
        $master = User::factory()->create(['role' => User::ROLE_MASTER]);

        $this->actingAs($this->alice)
            ->post(route('admin.users.link-seller', $this->alice), ['vendedor_id' => $imported->id])
            ->assertForbidden();

        $this->actingAs($master)
            ->post(route('admin.users.link-seller', $this->alice), ['vendedor_id' => $imported->id])
            ->assertSessionHasNoErrors();

        $imported->refresh();
        $this->assertSame($this->alice->id, $imported->user_id);
        $this->assertSame('Alice', $imported->nome);
        $this->assertSame($imported->id, $oldLead->fresh()->vendedor_id);
        $this->assertSame($imported->id, $newLead->fresh()->vendedor_id);
        $this->assertNull($this->aliceSeller->fresh()->user_id);
        $this->assertFalse($this->aliceSeller->fresh()->ativo);

        $this->actingAs($this->alice)
            ->get(route('leads.index'))
            ->assertInertia(fn (Assert $page) => $page->has('leads', 2));

        // Already linked sellers cannot be taken by another account.
        $this->actingAs($master)
            ->post(route('admin.users.link-seller', $this->bruno), ['vendedor_id' => $imported->id])
            ->assertSessionHasErrors('vendedor_id');
    }

    /**
     * @return array{0: User, 1: Vendedor}
     */
    private function seller(string $name): array
    {
        $user = User::factory()->create(['name' => $name, 'role' => User::ROLE_ATENDIMENTO]);
        $user->unidades()->attach($this->unit);
        $seller = Vendedor::query()->create([
            'unidade_id' => $this->unit->id,
            'user_id' => $user->id,
            'nome' => $name,
        ]);

        return [$user, $seller];
    }

    private function lead(string $name, ?Vendedor $seller): Lead
    {
        return Lead::query()->create([
            'unidade_id' => $this->unit->id,
            'vendedor_id' => $seller?->id,
            'data' => now(),
            'responsavel' => $name,
            'status_id' => $this->status->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function leadPayload(Lead $lead, array $overrides = []): array
    {
        return [
            'unidade_id' => $lead->unidade_id,
            'vendedor_id' => $lead->vendedor_id,
            'responsavel' => $lead->responsavel,
            'status_id' => $lead->status_id,
            ...$overrides,
        ];
    }
}
