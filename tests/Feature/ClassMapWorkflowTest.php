<?php

namespace Tests\Feature;

use App\Models\Matricula;
use App\Models\Turma;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Visita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClassMapWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_map_is_scoped_to_assigned_units_and_class_details_are_protected(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $visibleClass = $this->createClass($unit);
        $hiddenClass = $this->createClass($otherUnit);

        $this->actingAs($user)
            ->get(route('turmas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('turmas/index')
                ->has('classes', 1)
                ->where('classes.0.id', $visibleClass->id));

        $this->get(route('turmas.show', $hiddenClass))->assertNotFound();
        $this->get(route('turmas.index', ['unidade_id' => $otherUnit->id]))
            ->assertNotFound();
    }

    public function test_bulk_room_creation_numbers_rooms_and_prevents_duplicates(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);

        $this->actingAs($user)
            ->post(route('turmas.bulk-store'), [
                'unidade_id' => $unit->id,
                'periodo' => 'Manhã',
                'quantidade' => 3,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('turmas', [
            'unidade_id' => $unit->id,
            'periodo' => 'Manhã',
            'sala' => '1',
            'nome' => 'A definir',
            'capacidade' => 0,
        ]);
        $this->assertDatabaseHas('turmas', ['unidade_id' => $unit->id, 'sala' => '3']);

        $this->post(route('turmas.bulk-store'), [
            'unidade_id' => $unit->id,
            'periodo' => 'Manhã',
            'quantidade' => 2,
            'inicio' => 2,
        ])->assertSessionHasErrors('inicio');

        $this->assertSame(3, Turma::query()->count());
    }

    public function test_class_capacity_cannot_drop_below_active_enrollments_and_history_blocks_deletion(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $class = $this->createClass($unit, ['capacidade' => 1]);
        $visit = Visita::query()->create([
            'unidade_id' => $unit->id,
            'data' => '2026-10-29',
            'responsavel' => 'Família Norte',
            'status' => 'Matriculado',
        ]);
        Matricula::query()->create([
            'unidade_id' => $unit->id,
            'visita_id' => $visit->id,
            'turma_id' => $class->id,
            'data' => now(),
            'status' => 'Ativa',
        ]);

        $this->actingAs($user)
            ->put(route('turmas.update', $class), [
                'unidade_id' => $unit->id,
                'nome' => $class->nome,
                'periodo' => $class->periodo,
                'sala' => $class->sala,
                'capacidade' => 0,
            ])
            ->assertSessionHasErrors('capacidade');

        $this->delete(route('turmas.destroy', $class))
            ->assertSessionHasErrors('turma');
    }

    public function test_class_room_and_bulk_changes_cannot_cross_unit_boundaries(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        $class = $this->createClass($otherUnit);

        $this->actingAs($user)
            ->put(route('turmas.update', $class), [
                'unidade_id' => $otherUnit->id,
                'nome' => 'Tentativa',
                'periodo' => 'Manhã',
                'sala' => '2',
                'capacidade' => 10,
            ])
            ->assertNotFound();

        $this->post(route('turmas.bulk-store'), [
            'unidade_id' => $otherUnit->id,
            'periodo' => 'Manhã',
            'quantidade' => 1,
        ])->assertNotFound();
    }

    public function test_attendance_role_can_view_the_map_but_cannot_manage_classes(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_ATENDIMENTO]);
        $user->unidades()->attach($unit);

        $this->actingAs($user)
            ->get(route('turmas.index'))
            ->assertOk();

        $this->post(route('turmas.store'), [
            'unidade_id' => $unit->id,
            'nome' => '1º ano',
            'periodo' => 'Manhã',
            'sala' => '1',
            'capacidade' => 20,
        ])->assertForbidden();
    }

    private function createClass(Unidade $unit, array $attributes = []): Turma
    {
        return Turma::query()->create($attributes + [
            'unidade_id' => $unit->id,
            'nome' => '1º ano',
            'periodo' => 'Manhã',
            'capacidade' => 25,
            'sala' => '1',
        ]);
    }
}
