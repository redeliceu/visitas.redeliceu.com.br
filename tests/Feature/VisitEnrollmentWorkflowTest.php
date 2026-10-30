<?php

namespace Tests\Feature;

use App\Models\Agendamento;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Matricula;
use App\Models\MatriculaAudit;
use App\Models\Turma;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VisitEnrollmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_visit_list_is_limited_to_assigned_units_and_master_can_view_all(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $visibleVisit = $this->createVisit($unit);
        $this->createVisit($otherUnit);

        $this->actingAs($user)
            ->get(route('visitas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('visitas/index')
                ->has('visits', 1)
                ->where('visits.0.id', $visibleVisit->id));

        $this->actingAs($user)
            ->get(route('visitas.index', ['unidade_id' => $otherUnit->id]))
            ->assertNotFound();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_MASTER]))
            ->get(route('visitas.index'))
            ->assertInertia(fn (Assert $page) => $page->has('visits', 2));
    }

    public function test_visit_status_requires_a_same_unit_active_seller_and_conditional_fields(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $visit = $this->createVisit($unit);
        $seller = Vendedor::query()->create([
            'unidade_id' => $unit->id,
            'nome' => 'Vendedor Norte',
        ]);
        $externalSeller = Vendedor::query()->create([
            'unidade_id' => $otherUnit->id,
            'nome' => 'Vendedor Sul',
        ]);

        $this->actingAs($user)
            ->from(route('visitas.index'))
            ->patch(route('visitas.status', $visit), [
                'status' => 'Matriculado',
                'vendedor_id' => $seller->id,
            ])
            ->assertSessionHasErrors('status');

        $this->patch(route('visitas.status', $visit), [
            'status' => 'Perdido',
            'vendedor_id' => $externalSeller->id,
        ])
            ->assertSessionHasErrors(['vendedor_id', 'motivo_perda']);

        $this->patch(route('visitas.status', $visit), [
            'status' => 'Follow-up',
            'vendedor_id' => $seller->id,
        ])->assertSessionHasErrors('retorno_data');

        $this->patch(route('visitas.status', $visit), [
            'status' => 'Perdido',
            'vendedor_id' => $seller->id,
            'motivo_perda' => 'Preço',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Perdido', $visit->fresh()->status);
        $this->assertSame('Preço', $visit->fresh()->motivo_perda);
    }

    public function test_visit_actions_are_limited_to_assigned_units(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $visit = $this->createVisit($otherUnit);

        $this->actingAs($user)
            ->patch(route('visitas.status', $visit), [])
            ->assertNotFound();

        $this->delete(route('visitas.destroy', $visit))
            ->assertNotFound();

        $this->assertDatabaseHas('visitas', ['id' => $visit->id]);
    }

    public function test_cancelled_enrollment_is_reactivated_without_duplicate_history_row(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $class = Turma::query()->create([
            'unidade_id' => $unit->id,
            'nome' => '3º ano',
            'periodo' => 'Manhã',
            'capacidade' => 1,
        ]);
        $visit = $this->createVisit($unit);

        $this->actingAs($user)
            ->post(route('visitas.enroll', $visit), ['turma_id' => $class->id])
            ->assertSessionHasNoErrors();

        $this->post(route('visitas.enrollments.cancel', $visit))
            ->assertSessionHasNoErrors();

        $this->post(route('visitas.enroll', $visit), ['turma_id' => $class->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Matricula::query()->count());
        $this->assertSame('Ativa', Matricula::query()->sole()->status);
        $this->assertDatabaseHas('matricula_audits', [
            'acao' => 'Reativada',
            'status_anterior' => 'Cancelada',
            'status' => 'Ativa',
        ]);
    }

    public function test_deleting_visit_unlinks_associated_appointment_and_restores_its_status(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $visit = $this->createVisit($unit);
        $appointment = Agendamento::query()->create([
            'unidade_id' => $unit->id,
            'visita_id' => $visit->id,
            'data' => '2026-10-30',
            'horario' => '09:30',
            'responsavel' => 'Família Norte',
            'status' => 'Compareceu',
        ]);

        $this->actingAs($user)
            ->delete(route('visitas.destroy', $visit))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('visitas', ['id' => $visit->id]);
        $this->assertDatabaseHas('agendamentos', [
            'id' => $appointment->id,
            'visita_id' => null,
            'status' => 'Agendado',
        ]);
    }

    public function test_enrollment_respects_unit_capacity_updates_lead_and_records_audit(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $class = Turma::query()->create([
            'unidade_id' => $unit->id,
            'nome' => '1º ano',
            'periodo' => 'Manhã',
            'capacidade' => 1,
        ]);
        $matriculatedStatus = LeadStatus::query()->create(['nome' => 'Matriculado']);
        $attendedStatus = LeadStatus::query()->create(['nome' => 'Compareceu']);
        $lead = Lead::query()->create([
            'unidade_id' => $unit->id,
            'responsavel' => 'Família Norte',
            'status_id' => $attendedStatus->id,
        ]);
        $visit = $this->createVisit($unit, $lead);

        $this->actingAs($user)
            ->post(route('visitas.enroll', $visit), ['turma_id' => $class->id])
            ->assertSessionHasNoErrors();

        $enrollment = Matricula::query()->sole();
        $this->assertSame('Ativa', $enrollment->status);
        $this->assertSame('Matriculado', $visit->fresh()->status);
        $this->assertSame($matriculatedStatus->id, $lead->fresh()->status_id);
        $this->assertDatabaseHas('matricula_audits', [
            'matricula_id' => $enrollment->id,
            'user_id' => $user->id,
            'acao' => 'Criada',
            'status' => 'Ativa',
        ]);

        $anotherVisit = $this->createVisit($unit);
        $this->post(route('visitas.enroll', $anotherVisit), [
            'turma_id' => $class->id,
        ])->assertSessionHasErrors('turma_id');
        $this->assertSame(1, Matricula::query()->count());
    }

    public function test_enrollment_cancellation_preserves_history_and_visit_cannot_be_deleted(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $class = Turma::query()->create([
            'unidade_id' => $unit->id,
            'nome' => '2º ano',
            'periodo' => 'Tarde',
            'capacidade' => 10,
        ]);
        $visit = $this->createVisit($unit);
        $enrollment = Matricula::query()->create([
            'unidade_id' => $unit->id,
            'visita_id' => $visit->id,
            'turma_id' => $class->id,
            'data' => now(),
            'status' => 'Ativa',
        ]);

        $this->actingAs($user)
            ->post(route('visitas.enrollments.cancel', $visit))
            ->assertSessionHasNoErrors();

        $this->assertSame('Cancelada', $enrollment->fresh()->status);
        $this->assertSame('Aguardando', $visit->fresh()->status);
        $this->assertDatabaseHas('matricula_audits', [
            'matricula_id' => $enrollment->id,
            'acao' => 'Cancelada',
            'status_anterior' => 'Ativa',
            'status' => 'Cancelada',
        ]);

        $this->delete(route('visitas.destroy', $visit))
            ->assertSessionHasErrors('visit');
        $this->assertDatabaseHas('visitas', ['id' => $visit->id]);
        $this->assertSame(1, MatriculaAudit::query()->count());
    }

    private function createVisit(Unidade $unit, ?Lead $lead = null): Visita
    {
        return Visita::query()->create([
            'unidade_id' => $unit->id,
            'lead_id' => $lead?->id,
            'data' => '2026-10-30',
            'responsavel' => 'Família de teste',
            'status' => 'Aguardando',
            'fonte' => 'Manual',
        ]);
    }
}
