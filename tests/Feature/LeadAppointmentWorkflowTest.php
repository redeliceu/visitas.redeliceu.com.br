<?php

namespace Tests\Feature;

use App\Models\Agendamento;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LeadAppointmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_list_is_limited_to_the_users_assigned_units(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $visibleLead = Lead::query()->create([
            'unidade_id' => $unit->id,
            'responsavel' => 'Família Norte',
        ]);
        Lead::query()->create([
            'unidade_id' => $otherUnit->id,
            'responsavel' => 'Família Sul',
        ]);

        $this->actingAs($user)
            ->get(route('leads.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('visitas/leads')
                ->has('leads', 1)
                ->where('leads.0.id', $visibleLead->id));

        $this->actingAs($user)
            ->get(route('leads.index', ['unidade_id' => $otherUnit->id]))
            ->assertNotFound();
    }

    public function test_master_can_view_leads_from_multiple_units(): void
    {
        $master = User::factory()->create(['role' => User::ROLE_MASTER]);
        $firstUnit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $secondUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        Lead::query()->create(['unidade_id' => $firstUnit->id, 'responsavel' => 'Família Norte']);
        Lead::query()->create(['unidade_id' => $secondUnit->id, 'responsavel' => 'Família Sul']);

        $this->actingAs($master)
            ->get(route('leads.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('visitas/leads')
                ->has('leads', 2));
    }

    public function test_lead_creation_rejects_a_seller_from_another_unit(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $seller = Vendedor::query()->create([
            'unidade_id' => $otherUnit->id,
            'nome' => 'Vendedor externo',
        ]);
        $status = LeadStatus::query()->create(['nome' => 'Novo']);

        $this->actingAs($user)
            ->from(route('leads.index'))
            ->post(route('leads.store'), [
                'unidade_id' => $unit->id,
                'vendedor_id' => $seller->id,
                'responsavel' => 'Família de teste',
                'status_id' => $status->id,
            ])
            ->assertSessionHasErrors('vendedor_id');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_scheduling_a_lead_creates_a_scoped_appointment_and_updates_its_status(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $seller = Vendedor::query()->create([
            'unidade_id' => $unit->id,
            'nome' => 'Vendedor Norte',
        ]);
        $newStatus = LeadStatus::query()->create(['nome' => 'Novo']);
        $scheduledStatus = LeadStatus::query()->create(['nome' => 'Agendado']);
        $lead = Lead::query()->create([
            'unidade_id' => $unit->id,
            'vendedor_id' => $seller->id,
            'responsavel' => 'Família Agendada',
            'telefone' => '11999990000',
            'aluno' => 'Estudante',
            'status_id' => $newStatus->id,
        ]);

        $this->actingAs($user)
            ->post(route('leads.schedule', $lead), [
                'data' => '2026-10-30',
                'horario' => '09:30',
            ])
            ->assertRedirect(route('agendamentos.index'));

        $appointment = Agendamento::query()->sole();
        $this->assertSame($unit->id, $appointment->unidade_id);
        $this->assertSame($seller->id, $appointment->vendedor_id);
        $this->assertSame($lead->id, $appointment->lead_id);
        $this->assertSame('Família Agendada', $appointment->responsavel);
        $this->assertSame($scheduledStatus->id, $lead->fresh()->status_id);

        $this->actingAs($user)
            ->get(route('leads.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('visitas/leads')
                ->has('leads', 1)
                ->where('leads.0.id', $lead->id)
                ->where('leads.0.status.nome', 'Agendado')
                ->has('leads.0.status_histories', 2)
                ->where('leads.0.status_histories.1.from_status.nome', 'Novo')
                ->where('leads.0.status_histories.1.to_status.nome', 'Agendado')
                ->where('leads.0.status_histories.1.changed_by.name', $user->name)
                ->where('leads.0.agendamento.lead_id', $lead->id));
    }

    public function test_appointment_list_is_limited_to_the_users_assigned_units(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $visibleAppointment = $this->createAppointment($unit);
        $this->createAppointment($otherUnit);

        $this->actingAs($user)
            ->get(route('agendamentos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('visitas/agendamentos')
                ->has('appointments', 1)
                ->where('appointments.0.id', $visibleAppointment->id));

        $this->actingAs($user)
            ->put(route('agendamentos.update', $visibleAppointment), [
                'unidade_id' => $otherUnit->id,
                'data' => '2026-10-30',
                'horario' => '10:00',
                'responsavel' => 'Alteração externa',
                'status' => 'Agendado',
            ])
            ->assertNotFound();
    }

    public function test_manual_appointments_can_be_created_updated_and_deleted(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $seller = Vendedor::query()->create([
            'unidade_id' => $unit->id,
            'nome' => 'Vendedor Norte',
        ]);
        $data = [
            'unidade_id' => $unit->id,
            'vendedor_id' => $seller->id,
            'data' => '2026-10-30',
            'horario' => '10:00',
            'responsavel' => 'Família cadastrada manualmente',
            'telefone' => '11999990000',
            'aluno' => 'Estudante',
            'status' => 'Agendado',
        ];

        $this->actingAs($user)
            ->from(route('agendamentos.index'))
            ->post(route('agendamentos.store'), $data)
            ->assertSessionHasNoErrors();

        $appointment = Agendamento::query()->sole();
        $this->assertSame($seller->id, $appointment->vendedor_id);

        $this->put(route('agendamentos.update', $appointment), [
            ...$data,
            'status' => 'Compareceu',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Compareceu', $appointment->fresh()->status);

        $this->delete(route('agendamentos.destroy', $appointment))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('agendamentos', ['id' => $appointment->id]);
    }

    public function test_appointment_conversion_creates_a_visit_once_and_updates_linked_statuses(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $scheduledStatus = LeadStatus::query()->create(['nome' => 'Agendado']);
        $attendedStatus = LeadStatus::query()->create(['nome' => 'Compareceu']);
        $lead = Lead::query()->create([
            'unidade_id' => $unit->id,
            'responsavel' => 'Família presente',
            'aluno' => 'Estudante',
            'status_id' => $scheduledStatus->id,
        ]);
        $appointment = Agendamento::query()->create([
            'unidade_id' => $unit->id,
            'lead_id' => $lead->id,
            'data' => '2026-10-30',
            'horario' => '09:30',
            'responsavel' => 'Família presente',
            'aluno' => 'Estudante',
            'status' => 'Agendado',
        ]);

        $this->actingAs($user)
            ->post(route('agendamentos.convert', $appointment))
            ->assertSessionHasNoErrors();

        $appointment->refresh();
        $visit = $appointment->visita;
        $this->assertNotNull($visit);
        $this->assertSame($appointment->id, $visit->agendamento_id);
        $this->assertSame($lead->id, $visit->lead_id);
        $this->assertSame('Compareceu', $appointment->status);
        $this->assertSame($attendedStatus->id, $lead->fresh()->status_id);

        $this->post(route('agendamentos.convert', $appointment))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseCount('visitas', 1);
    }

    public function test_cancelled_appointment_cannot_be_converted(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $appointment = Agendamento::query()->create([
            'unidade_id' => $unit->id,
            'data' => '2026-10-30',
            'horario' => '09:30',
            'responsavel' => 'Família ausente',
            'status' => 'Cancelado',
        ]);

        $this->actingAs($user)
            ->post(route('agendamentos.convert', $appointment))
            ->assertSessionHasErrors('appointment');

        $this->assertDatabaseCount('visitas', 0);
    }

    public function test_deleting_a_lead_appointment_restores_its_previous_status(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $previousStatus = LeadStatus::query()->create(['nome' => 'Em Negociação']);
        $scheduledStatus = LeadStatus::query()->create(['nome' => 'Agendado']);
        $lead = Lead::query()->create([
            'unidade_id' => $unit->id,
            'responsavel' => 'Família reagendada',
            'status_id' => $scheduledStatus->id,
        ]);
        $appointment = Agendamento::query()->create([
            'unidade_id' => $unit->id,
            'lead_id' => $lead->id,
            'lead_status_anterior_id' => $previousStatus->id,
            'data' => '2026-10-30',
            'horario' => '11:00',
            'responsavel' => 'Família reagendada',
            'status' => 'Agendado',
        ]);

        $this->actingAs($user)
            ->delete(route('agendamentos.destroy', $appointment))
            ->assertSessionHasNoErrors();

        $this->assertSame($previousStatus->id, $lead->fresh()->status_id);
        $this->assertDatabaseMissing('agendamentos', ['id' => $appointment->id]);
    }

    private function createAppointment(Unidade $unit): Agendamento
    {
        return Agendamento::query()->create([
            'unidade_id' => $unit->id,
            'data' => '2026-10-30',
            'horario' => '09:00',
            'responsavel' => 'Família agendada',
            'status' => 'Agendado',
        ]);
    }
}
