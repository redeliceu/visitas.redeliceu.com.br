<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\LeadHistory;
use App\Models\LeadStatus;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use App\Models\Visita;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Follow-up notes: D-028 and D-029.
 */
class LeadFollowUpTest extends TestCase
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
        [$this->alice, $this->aliceSeller] = $this->seller('Alice');
        [$this->bruno, $this->brunoSeller] = $this->seller('Bruno');
    }

    public function test_seller_registers_a_follow_up_that_updates_the_lead_return(): void
    {
        $lead = $this->lead($this->aliceSeller, ['retorno_data' => '2026-11-01 00:00:00']);

        $this->actingAs($this->alice)
            ->post(route('leads.follow-ups.store', $lead), [
                'texto' => 'Família pediu proposta de desconto.',
                'proximo_retorno' => '2026-11-12',
            ])
            ->assertSessionHasNoErrors();

        $note = LeadFollowUp::query()->sole();
        $this->assertSame($this->aliceSeller->id, $note->vendedor_id);
        $this->assertSame($this->alice->id, $note->user_id);
        $this->assertSame('2026-11-12', $note->proximo_retorno?->toDateString());
        $this->assertSame('2026-11-12', CarbonImmutable::parse($lead->fresh()->retorno_data)->toDateString());
        $this->assertSame([[
            'field' => 'Data de retorno',
            'old' => '2026-11-01 00:00:00',
            'new' => '2026-11-12 00:00:00',
        ]], LeadHistory::query()->where('lead_id', $lead->id)->sole()->changes);
    }

    public function test_follow_up_without_next_return_keeps_the_lead_return(): void
    {
        $lead = $this->lead($this->aliceSeller, ['retorno_data' => '2026-11-01 00:00:00']);

        $this->actingAs($this->alice)
            ->post(route('leads.follow-ups.store', $lead), ['texto' => 'Sem resposta no WhatsApp.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-11-01', CarbonImmutable::parse($lead->fresh()->retorno_data)->toDateString());
        $this->assertDatabaseCount('lead_histories', 0);
    }

    public function test_text_is_required_and_limited_to_2000_characters(): void
    {
        $lead = $this->lead($this->aliceSeller);

        $this->actingAs($this->alice)
            ->post(route('leads.follow-ups.store', $lead), ['texto' => str_repeat('a', 2001)])
            ->assertSessionHasErrors('texto');
        $this->post(route('leads.follow-ups.store', $lead), ['texto' => ''])
            ->assertSessionHasErrors('texto');

        $this->assertDatabaseCount('lead_follow_ups', 0);
    }

    public function test_queue_lead_note_by_seller_claims_it_and_by_gestor_has_no_seller(): void
    {
        $sellerQueue = $this->lead(null);
        $gestorQueue = $this->lead(null);
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $gestor->unidades()->attach($this->unit);

        $this->actingAs($this->alice)
            ->post(route('leads.follow-ups.store', $sellerQueue), ['texto' => 'Primeiro contato.'])
            ->assertSessionHasNoErrors();
        $this->actingAs($gestor)
            ->post(route('leads.follow-ups.store', $gestorQueue), ['texto' => 'Lead aguardando vendedor.'])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->aliceSeller->id, $sellerQueue->fresh()->vendedor_id);
        $this->assertSame($this->aliceSeller->id, $sellerQueue->followUps()->sole()->vendedor_id);
        $this->assertNull($gestorQueue->fresh()->vendedor_id);
        $this->assertNull($gestorQueue->followUps()->sole()->vendedor_id);
        $this->assertSame($gestor->id, $gestorQueue->followUps()->sole()->user_id);
    }

    public function test_seller_cannot_write_on_another_sellers_lead(): void
    {
        $lead = $this->lead($this->brunoSeller);

        $this->actingAs($this->alice)
            ->post(route('leads.follow-ups.store', $lead), ['texto' => 'Tentativa.'])
            ->assertNotFound();

        $this->assertDatabaseCount('lead_follow_ups', 0);
    }

    public function test_only_author_gestor_and_master_edit_or_delete_and_delete_is_soft(): void
    {
        $lead = $this->lead($this->aliceSeller);
        $note = $this->note($lead, $this->alice, $this->aliceSeller, 'Texto original.');

        // After a transfer Bruno reads Alice's note but cannot change it.
        $lead->update(['vendedor_id' => $this->brunoSeller->id]);
        $this->actingAs($this->bruno)
            ->put(route('follow-ups.update', $note), ['texto' => 'Alterado por Bruno.'])
            ->assertForbidden();
        $this->delete(route('follow-ups.destroy', $note))->assertForbidden();

        // Alice no longer sees the lead after the transfer.
        $this->actingAs($this->alice)
            ->put(route('follow-ups.update', $note), ['texto' => 'Alterado por Alice.'])
            ->assertNotFound();

        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $gestor->unidades()->attach($this->unit);
        $this->actingAs($gestor)
            ->put(route('follow-ups.update', $note), ['texto' => 'Revisado pelo gestor.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Revisado pelo gestor.', $note->fresh()->texto);

        $this->delete(route('follow-ups.destroy', $note))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('lead_follow_ups', ['id' => $note->id]);
    }

    public function test_author_edits_own_note(): void
    {
        $lead = $this->lead($this->aliceSeller);
        $note = $this->note($lead, $this->alice, $this->aliceSeller, 'Texto original.');

        $this->actingAs($this->alice)
            ->put(route('follow-ups.update', $note), ['texto' => 'Texto corrigido.', 'proximo_retorno' => '2026-11-20'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Texto corrigido.', $note->fresh()->texto);
        $this->assertSame('2026-11-20', CarbonImmutable::parse($lead->fresh()->retorno_data)->toDateString());
    }

    public function test_panel_lists_notes_newest_first_and_counts_the_initial_observation(): void
    {
        $lead = $this->lead($this->aliceSeller, ['observacoes' => 'Interesse no integral.']);
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00'));
        $this->note($lead, $this->alice, $this->aliceSeller, 'Primeira ligação.');
        $this->travelTo(CarbonImmutable::parse('2026-11-06 10:00:00'));
        $this->note($lead, $this->alice, $this->aliceSeller, 'Segunda ligação.');
        $deleted = $this->note($lead, $this->alice, $this->aliceSeller, 'Removida.');
        $deleted->delete();
        $bare = $this->lead($this->aliceSeller);

        $this->actingAs($this->alice)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('painel-vendedor')
                ->where('followUps.leads', function ($leads) use ($lead, $bare): bool {
                    $byId = collect($leads)->keyBy('id');
                    $main = $byId[$lead->id];

                    return $main['follow_ups_count'] === 3
                        && $main['initial_note']['texto'] === 'Interesse no integral.'
                        && collect($main['follow_ups'])->pluck('texto')->all() === ['Segunda ligação.', 'Primeira ligação.']
                        && $main['follow_ups'][0]['can_manage'] === true
                        && $byId[$bare->id]['follow_ups_count'] === 0
                        && $byId[$bare->id]['initial_note'] === null;
                }));
    }

    public function test_panel_groups_pending_follow_ups_into_waiting_tabs(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00'));
        $idle = $this->lead($this->aliceSeller);
        $idleButNoted = $this->lead($this->aliceSeller);
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00:00'));
        $this->note($idleButNoted, $this->alice, $this->aliceSeller, 'Contato recente.');

        $this->travelTo(CarbonImmutable::parse('2026-11-06 09:00:00'));
        $overdue = $this->lead($this->aliceSeller, ['retorno_data' => '2026-11-03 00:00:00']);
        $today = $this->lead($this->aliceSeller, ['retorno_data' => '2026-11-06 00:00:00']);
        $soon = $this->lead($this->aliceSeller, ['retorno_data' => '2026-11-13 00:00:00']);
        $later = $this->lead($this->aliceSeller, ['retorno_data' => '2026-11-14 00:00:00']);

        $this->actingAs($this->alice)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('followUps.counts', [
                    'todos' => 6,
                    'vencidos' => 1,
                    'hoje' => 1,
                    'proximos' => 1,
                    'parados' => 1,
                ])
                ->where('followUps.overdue', 1)
                ->where('followUps.idle', 1)
                ->where('followUps.leads', function ($leads) use ($idle, $idleButNoted, $overdue, $today, $soon, $later): bool {
                    $buckets = collect($leads)->pluck('bucket', 'id');

                    return $buckets[$overdue->id] === 'vencidos'
                        && $buckets[$today->id] === 'hoje'
                        && $buckets[$soon->id] === 'proximos'
                        && $buckets[$later->id] === null
                        && $buckets[$idle->id] === 'parados'
                        // A follow-up 2 days ago counts as movement (D-030).
                        && $buckets[$idleButNoted->id] === null;
                }));
    }

    public function test_panel_schedules_a_visit_and_stays_on_the_page(): void
    {
        $scheduled = LeadStatus::query()->create(['nome' => 'Agendado', 'ordem' => 2]);
        $lead = $this->lead($this->aliceSeller);

        // From the seller panel (D-032): back to the panel instead of Agendamentos.
        $this->actingAs($this->alice)
            ->from(route('home'))
            ->post(route('leads.schedule', $lead), [
                'data' => '2026-11-10',
                'horario' => '14:30',
                'permanecer' => true,
            ])
            ->assertRedirect(route('home'));

        $appointment = $lead->agendamentos()->sole();
        $this->assertSame($this->aliceSeller->id, $appointment->vendedor_id);
        $this->assertSame('Agendado', $appointment->status);
        $this->assertSame($scheduled->id, $lead->fresh()->status_id);

        // The Leads screen keeps its original behaviour.
        $this->from(route('leads.index'))
            ->post(route('leads.schedule', $lead), ['data' => '2026-11-11', 'horario' => '09:00'])
            ->assertRedirect(route('agendamentos.index'));
    }

    public function test_panel_agenda_visits_carry_the_phone_for_search(): void
    {
        Visita::query()->create([
            'unidade_id' => $this->unit->id,
            'vendedor_id' => $this->aliceSeller->id,
            'data' => now()->toDateString(),
            'horario' => '10:00',
            'responsavel' => 'Família Visitante',
            'telefone' => '(11) 98765-4321',
            'status' => 'Aguardando',
        ]);

        // D-033: the panel search matches agenda items by phone.
        $this->actingAs($this->alice)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('agenda.visits.0.telefone', '(11) 98765-4321')
                ->where('agenda.visits.0.responsavel', 'Família Visitante'));
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lead(?Vendedor $seller, array $attributes = []): Lead
    {
        return Lead::query()->create([
            'unidade_id' => $this->unit->id,
            'vendedor_id' => $seller?->id,
            'data' => now(),
            'responsavel' => 'Família '.uniqid(),
            'status_id' => $this->status->id,
            ...$attributes,
        ]);
    }

    private function note(Lead $lead, User $author, ?Vendedor $seller, string $text): LeadFollowUp
    {
        return LeadFollowUp::query()->create([
            'lead_id' => $lead->id,
            'vendedor_id' => $seller?->id,
            'user_id' => $author->id,
            'texto' => $text,
        ]);
    }
}
