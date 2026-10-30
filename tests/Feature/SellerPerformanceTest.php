<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SellerPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_funnel_reconstructs_lead_status_at_the_selected_date(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_ATENDIMENTO]);
        $user->unidades()->attach($unit);
        $seller = Vendedor::query()->create([
            'unidade_id' => $unit->id,
            'user_id' => $user->id,
            'nome' => $user->name,
        ]);
        $newStatus = LeadStatus::query()->create(['nome' => 'Novo', 'ordem' => 1]);
        $scheduledStatus = LeadStatus::query()->create(['nome' => 'Agendado', 'ordem' => 2]);
        $enrolledStatus = LeadStatus::query()->create(['nome' => 'Matriculado', 'ordem' => 3]);
        DB::table('lead_status_tracking')
            ->where('id', 1)
            ->update(['started_at' => '2026-10-20 00:00:00']);

        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00'));
        $lead = Lead::query()->create([
            'unidade_id' => $unit->id,
            'vendedor_id' => $seller->id,
            'responsavel' => 'Família em atendimento',
            'status_id' => $newStatus->id,
        ]);

        $this->actingAs($user)
            ->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00'));
        $lead->update(['status_id' => $scheduledStatus->id]);

        $this->travelTo(CarbonImmutable::parse('2026-10-22 11:00:00'));
        $lead->update(['status_id' => $enrolledStatus->id]);

        $this->get(route('painel-vendas', ['snapshot_date' => '2026-10-19']))
            ->assertSessionHasErrors('snapshot_date');

        $this->get(route('painel-vendas', ['snapshot_date' => '2026-10-20']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('snapshotDate', '2026-10-20')
                ->where('historyStartDate', '2026-10-20')
                ->where("sellers.0.status_counts.{$newStatus->id}", 1)
                ->where("sellers.0.status_counts.{$scheduledStatus->id}", 0)
                ->where("sellers.0.status_counts.{$enrolledStatus->id}", 0));

        $this->get(route('painel-vendas', ['snapshot_date' => '2026-10-21']))
            ->assertInertia(fn (Assert $page) => $page
                ->where("sellers.0.status_counts.{$newStatus->id}", 0)
                ->where("sellers.0.status_counts.{$scheduledStatus->id}", 1)
                ->where("sellers.0.status_counts.{$enrolledStatus->id}", 0));

        $this->get(route('painel-vendas', ['snapshot_date' => '2026-10-22']))
            ->assertInertia(fn (Assert $page) => $page
                ->where("sellers.0.status_counts.{$newStatus->id}", 0)
                ->where("sellers.0.status_counts.{$scheduledStatus->id}", 0)
                ->where("sellers.0.status_counts.{$enrolledStatus->id}", 1));

        $this->assertDatabaseCount('lead_status_histories', 3);
        $this->assertDatabaseHas('lead_status_histories', [
            'lead_id' => $lead->id,
            'from_status_id' => $newStatus->id,
            'to_status_id' => $scheduledStatus->id,
            'changed_by' => $user->id,
            'changed_at' => '2026-10-21 10:00:00',
        ]);
    }

    public function test_attendance_user_sees_only_own_seller_performance_and_lead_statuses(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_ATENDIMENTO]);
        $user->unidades()->attach($unit);
        $ownSeller = Vendedor::query()->create([
            'unidade_id' => $unit->id,
            'user_id' => $user->id,
            'nome' => $user->name,
        ]);
        Vendedor::query()->create([
            'unidade_id' => $unit->id,
            'nome' => 'Outro vendedor',
        ]);
        $newStatus = LeadStatus::query()->create(['nome' => 'Novo', 'ordem' => 1]);
        $followUpStatus = LeadStatus::query()->create(['nome' => 'Follow-up', 'ordem' => 2]);
        Lead::query()->create([
            'unidade_id' => $unit->id,
            'vendedor_id' => $ownSeller->id,
            'responsavel' => 'Família em atendimento',
            'status_id' => $followUpStatus->id,
            'data' => now()->toDateString(),
        ]);
        Lead::query()->create([
            'unidade_id' => $unit->id,
            'responsavel' => 'Lead sem atribuição',
            'status_id' => $newStatus->id,
            'data' => now()->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('painel-vendas'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('painel-vendas')
                ->where('metrics.leads', 1)
                ->has('sellers', 1)
                ->where('sellers.0.id', $ownSeller->id)
                ->where("sellers.0.status_counts.{$followUpStatus->id}", 1)
                ->where("sellers.0.status_counts.{$newStatus->id}", 0));

        $this->actingAs($user)
            ->get(route('vendedores.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('vendedores/index')
                ->has('sellers', 1)
                ->where('sellers.0.user_id', $user->id));
    }

    public function test_manager_can_view_sellers_and_funnels_only_for_assigned_unit(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $manager = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $manager->unidades()->attach($unit);
        $visibleSeller = Vendedor::query()->create([
            'unidade_id' => $unit->id,
            'nome' => 'Vendedor Norte',
        ]);
        Vendedor::query()->create([
            'unidade_id' => $otherUnit->id,
            'nome' => 'Vendedor Sul',
        ]);

        $this->actingAs($manager)
            ->get(route('vendedores.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('vendedores/index')
                ->has('sellers', 1)
                ->where('sellers.0.id', $visibleSeller->id));

        $this->actingAs($manager)
            ->get(route('painel-vendas', ['unidade_id' => $otherUnit->id]))
            ->assertNotFound();
    }

    public function test_master_can_view_team_sellers_across_units(): void
    {
        $master = User::factory()->create(['role' => User::ROLE_MASTER]);
        $firstUnit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $secondUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        Vendedor::query()->create([
            'unidade_id' => $firstUnit->id,
            'nome' => 'Vendedor Norte',
        ]);
        Vendedor::query()->create([
            'unidade_id' => $secondUnit->id,
            'nome' => 'Vendedor Sul',
        ]);

        $this->actingAs($master)
            ->get(route('painel-vendas'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('painel-vendas')
                ->has('sellers', 2));
    }
}
