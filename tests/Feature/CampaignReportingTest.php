<?php

namespace Tests\Feature;

use App\Models\Agendamento;
use App\Models\Campanha;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Matricula;
use App\Models\Turma;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CampaignReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_and_seller_goals_are_unit_scoped_and_updateable(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $seller = Vendedor::query()->create([
            'unidade_id' => $unit->id,
            'nome' => 'Vendedor Norte',
        ]);
        $otherSeller = Vendedor::query()->create([
            'unidade_id' => $otherUnit->id,
            'nome' => 'Vendedor Sul',
        ]);

        $this->actingAs($user)
            ->post(route('campanhas.store'), [
                'unidade_id' => $unit->id,
                'inicio' => '2026-10-01',
                'fim' => '2026-10-31',
                'meta' => 80,
            ])
            ->assertSessionHasNoErrors();
        $campaign = Campanha::query()->sole();

        $this->post(route('campanhas.metas.store', $campaign), [
            'vendedor_id' => $seller->id,
            'meta' => 20,
        ])->assertSessionHasNoErrors();

        $this->post(route('campanhas.metas.store', $campaign), [
            'vendedor_id' => $otherSeller->id,
            'meta' => 30,
        ])->assertSessionHasErrors('vendedor_id');

        $this->get(route('campanhas.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('campanhas/index')
                ->has('units', 1)
                ->where('units.0.campaign.meta', 80)
                ->where('units.0.sellers.0.meta', 20));

        $this->get(route('campanhas.index', ['unidade_id' => $otherUnit->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('metas_vendedores', [
            'campanha_id' => $campaign->id,
            'vendedor_id' => $seller->id,
            'meta' => 20,
        ]);
    }

    public function test_reports_count_each_funnel_stage_and_deduplicate_linked_enrollments(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $seller = Vendedor::query()->create([
            'unidade_id' => $unit->id,
            'nome' => 'Vendedor Norte',
        ]);
        $leadStatus = LeadStatus::query()->create(['nome' => 'Matriculado']);
        $lead = Lead::query()->create([
            'unidade_id' => $unit->id,
            'vendedor_id' => $seller->id,
            'data' => '2026-10-29 10:00:00',
            'responsavel' => 'Família Norte',
            'status_id' => $leadStatus->id,
        ]);
        Agendamento::query()->create([
            'unidade_id' => $unit->id,
            'lead_id' => $lead->id,
            'vendedor_id' => $seller->id,
            'data' => '2026-10-29',
            'horario' => '10:00',
            'responsavel' => 'Família Norte',
            'status' => 'Compareceu',
        ]);
        $visit = Visita::query()->create([
            'unidade_id' => $unit->id,
            'lead_id' => $lead->id,
            'vendedor_id' => $seller->id,
            'data' => '2026-10-29',
            'horario' => '10:30',
            'responsavel' => 'Família Norte',
            'status' => 'Matriculado',
        ]);
        $class = Turma::query()->create([
            'unidade_id' => $unit->id,
            'nome' => '1º ano',
            'periodo' => 'Manhã',
            'capacidade' => 20,
            'sala' => '1',
        ]);
        Matricula::query()->create([
            'unidade_id' => $unit->id,
            'lead_id' => $lead->id,
            'visita_id' => $visit->id,
            'turma_id' => $class->id,
            'data' => '2026-10-29 10:45:00',
            'status' => 'Ativa',
        ]);
        Lead::query()->create([
            'unidade_id' => $otherUnit->id,
            'data' => '2026-10-29 10:00:00',
            'responsavel' => 'Família externa',
        ]);

        $query = [
            'inicio' => '2026-10-29',
            'fim' => '2026-10-29',
        ];
        $this->actingAs($user)
            ->get(route('relatorios.index', $query))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('relatorios/index')
                ->where('report.all.leads', 1)
                ->where('report.all.appointments', 1)
                ->where('report.all.visits', 1)
                ->where('report.all.sales', 1)
                ->where('report.bySeller.0.seller_id', $seller->id));

        $this->get(route('relatorios.csv', $query))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertSee('Unidade Norte');

        $this->get(route('relatorios.pdf', $query))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertSee('%PDF-1.4', false);
    }

    public function test_dashboard_and_reports_do_not_expose_unassigned_units(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        Lead::query()->create([
            'unidade_id' => $unit->id,
            'data' => '2026-10-29 10:00:00',
            'responsavel' => 'Visível',
        ]);
        Lead::query()->create([
            'unidade_id' => $otherUnit->id,
            'data' => '2026-10-29 10:00:00',
            'responsavel' => 'Restrito',
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->where('metrics.leads', 1)
                ->has('unitRows', 1));

        $this->get(route('relatorios.index', [
            'unidade_id' => $otherUnit->id,
            'inicio' => '2026-10-29',
            'fim' => '2026-10-29',
        ]))->assertNotFound();
    }

    public function test_attendance_role_can_view_campaign_metrics_but_cannot_change_goals(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_ATENDIMENTO]);
        $user->unidades()->attach($unit);
        $campaign = Campanha::query()->create([
            'unidade_id' => $unit->id,
            'inicio' => '2026-10-01',
            'fim' => '2026-10-31',
            'meta' => 100,
        ]);
        $seller = Vendedor::query()->create([
            'unidade_id' => $unit->id,
            'nome' => 'Vendedor Norte',
        ]);

        $this->actingAs($user)
            ->get(route('campanhas.index'))
            ->assertOk();

        $this->post(route('campanhas.metas.store', $campaign), [
            'vendedor_id' => $seller->id,
            'meta' => 10,
        ])->assertForbidden();
    }
}
