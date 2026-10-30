<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use App\Support\UnitAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_sign_in_and_reaches_the_home_page(): void
    {
        $user = User::factory()->create([
            'password' => 'A-secure-password-123',
            'ativo' => true,
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'A-secure-password-123',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->get(route('home'))->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('home')
                ->where('auth.user.id', $user->id),
        );
    }

    public function test_initial_master_can_be_created_through_the_console(): void
    {
        $this->artisan('users:create-master')
            ->expectsQuestion('Nome', 'Gestão Geral')
            ->expectsQuestion('E-mail', 'master@example.test')
            ->expectsQuestion('Senha (mínimo 12 caracteres)', 'A-secure-password-123')
            ->expectsOutput('Usuário master criado.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'master@example.test',
            'role' => User::ROLE_MASTER,
            'ativo' => true,
        ]);
    }

    public function test_inactive_user_cannot_sign_in(): void
    {
        $user = User::factory()->create([
            'password' => 'A-secure-password-123',
            'ativo' => false,
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'A-secure-password-123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_can_update_profile_without_changing_role_or_units(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Perfil']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => 'Nome atualizado',
                'email' => 'updated@example.test',
                'role' => User::ROLE_MASTER,
                'unit_ids' => [],
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Nome atualizado', $user->name);
        $this->assertSame(User::ROLE_GESTOR, $user->role);
        $this->assertSame([$unit->id], $user->unidades()->pluck('unidades.id')->all());
    }

    public function test_attendance_profile_name_changes_are_reflected_in_generated_sellers(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $user = User::factory()->create(['role' => User::ROLE_ATENDIMENTO]);
        $user->unidades()->attach($unit);
        $seller = Vendedor::query()->create([
            'unidade_id' => $unit->id,
            'user_id' => $user->id,
            'nome' => $user->name,
        ]);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => 'Novo nome comercial',
                'email' => $user->email,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Novo nome comercial', $seller->fresh()->nome);
    }

    public function test_password_change_requires_the_current_password(): void
    {
        $user = User::factory()->create([
            'password' => 'A-secure-password-123',
        ]);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'current_password' => 'incorrect-current-password',
                'password' => 'A-different-password-456',
                'password_confirmation' => 'A-different-password-456',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(
            Hash::check(
                'A-secure-password-123',
                $user->fresh()->password,
            ),
        );
    }

    public function test_only_master_can_manage_users_and_units(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $atendimento = User::factory()->create(['role' => User::ROLE_ATENDIMENTO]);

        $this->actingAs($gestor)
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs($atendimento)
            ->get(route('admin.units.index'))
            ->assertForbidden();
    }

    public function test_master_can_create_users_for_multiple_units(): void
    {
        $master = User::factory()->create(['role' => User::ROLE_MASTER]);
        $units = collect([
            Unidade::query()->create(['nome' => 'Unidade Norte']),
            Unidade::query()->create(['nome' => 'Unidade Sul']),
        ]);

        $this->actingAs($master)
            ->post(route('admin.users.store'), [
                'name' => 'Gestor de teste',
                'email' => 'gestor@example.test',
                'password' => 'A-secure-password-123',
                'role' => User::ROLE_GESTOR,
                'ativo' => true,
                'unit_ids' => $units->pluck('id')->all(),
            ])
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'gestor@example.test')->firstOrFail();
        $this->assertSame(
            $units->pluck('id')->all(),
            $user->unidades()->orderBy('unidades.id')->pluck('unidades.id')->all(),
        );
    }

    public function test_attendance_account_automatically_creates_a_seller_for_each_unit(): void
    {
        $master = User::factory()->create(['role' => User::ROLE_MASTER]);
        $units = collect([
            Unidade::query()->create(['nome' => 'Unidade Norte']),
            Unidade::query()->create(['nome' => 'Unidade Sul']),
        ]);

        $this->actingAs($master)
            ->post(route('admin.users.store'), [
                'name' => 'Atendimento Comercial',
                'email' => 'atendimento@example.test',
                'password' => 'A-secure-password-123',
                'role' => User::ROLE_ATENDIMENTO,
                'ativo' => true,
                'unit_ids' => $units->pluck('id')->all(),
            ])
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'atendimento@example.test')->firstOrFail();
        $sellers = $user->vendedores()->orderBy('unidade_id')->get();
        $this->assertCount(2, $sellers);
        $this->assertSame($units->pluck('id')->all(), $sellers->pluck('unidade_id')->all());
        $this->assertSame(['Atendimento Comercial', 'Atendimento Comercial'], $sellers->pluck('nome')->all());

        $this->actingAs($user)
            ->get(route('leads.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('visitas/leads')
                ->where('sellers.0.is_current_user', true)
                ->where('sellers.1.is_current_user', true));
    }

    public function test_attendance_sellers_follow_account_name_units_and_active_state(): void
    {
        $master = User::factory()->create(['role' => User::ROLE_MASTER]);
        $firstUnit = Unidade::query()->create(['nome' => 'Unidade Norte']);
        $secondUnit = Unidade::query()->create(['nome' => 'Unidade Sul']);

        $this->actingAs($master)
            ->post(route('admin.users.store'), [
                'name' => 'Atendimento Norte',
                'email' => 'atendimento@example.test',
                'password' => 'A-secure-password-123',
                'role' => User::ROLE_ATENDIMENTO,
                'ativo' => true,
                'unit_ids' => [$firstUnit->id, $secondUnit->id],
            ])
            ->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'atendimento@example.test')->firstOrFail();
        $firstSeller = $user->vendedores()->where('unidade_id', $firstUnit->id)->firstOrFail();

        $this->actingAs($master)
            ->put(route('admin.users.update', $user), [
                'name' => 'Atendimento Atualizado',
                'email' => $user->email,
                'role' => User::ROLE_ATENDIMENTO,
                'ativo' => true,
                'unit_ids' => [$firstUnit->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Atendimento Atualizado', $firstSeller->fresh()->nome);
        $this->assertTrue($firstSeller->fresh()->ativo);
        $this->assertFalse($user->vendedores()->where('unidade_id', $secondUnit->id)->firstOrFail()->ativo);

        $this->actingAs($master)
            ->patch(route('admin.users.active', $user), ['ativo' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($firstSeller->fresh()->ativo);
    }

    public function test_master_account_cannot_be_removed_as_the_last_active_master(): void
    {
        $master = User::factory()->create(['role' => User::ROLE_MASTER]);

        $this->actingAs($master)
            ->patch(route('admin.users.active', $master), ['ativo' => false])
            ->assertSessionHasErrors('role');

        $this->assertTrue($master->fresh()->ativo);
    }

    public function test_regular_user_queries_are_limited_to_assigned_active_units(): void
    {
        $assignedUnit = Unidade::query()->create(['nome' => 'Unidade A']);
        $otherUnit = Unidade::query()->create(['nome' => 'Unidade B']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($assignedUnit);

        $assignedLead = Lead::query()->create([
            'unidade_id' => $assignedUnit->id,
            'responsavel' => 'Família vinculada',
        ]);
        Lead::query()->create([
            'unidade_id' => $otherUnit->id,
            'responsavel' => 'Outra unidade',
        ]);

        $leads = app(UnitAccess::class)
            ->scope(Lead::query(), $user)
            ->get();

        $this->assertSame([$assignedLead->id], $leads->modelKeys());
    }

    public function test_master_queries_can_span_all_units(): void
    {
        $master = User::factory()->create(['role' => User::ROLE_MASTER]);
        $firstUnit = Unidade::query()->create(['nome' => 'Unidade A']);
        $secondUnit = Unidade::query()->create(['nome' => 'Unidade B']);

        Lead::query()->create(['unidade_id' => $firstUnit->id, 'responsavel' => 'Primeiro']);
        Lead::query()->create(['unidade_id' => $secondUnit->id, 'responsavel' => 'Segundo']);

        $this->assertCount(
            2,
            app(UnitAccess::class)->scope(Lead::query(), $master)->get(),
        );
    }

    public function test_archived_units_are_excluded_from_regular_user_queries(): void
    {
        $unit = Unidade::query()->create(['nome' => 'Unidade Arquivada']);
        $user = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $user->unidades()->attach($unit);
        Lead::query()->create([
            'unidade_id' => $unit->id,
            'responsavel' => 'Registro histórico',
        ]);
        $unit->update(['ativo' => false]);

        $this->assertCount(
            0,
            app(UnitAccess::class)->scope(Lead::query(), $user)->get(),
        );
    }

    public function test_master_can_manage_units(): void
    {
        $master = User::factory()->create(['role' => User::ROLE_MASTER]);

        $this->actingAs($master)
            ->post(route('admin.units.store'), ['nome' => 'Unidade Centro'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('unidades', [
            'nome' => 'Unidade Centro',
            'ativo' => true,
        ]);
    }
}
