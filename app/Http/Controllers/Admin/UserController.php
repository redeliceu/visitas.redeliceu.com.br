<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users', [
            'users' => User::query()->with(['unidades:id,nome', 'vendedores:id,user_id,unidade_id,nome'])->orderBy('name')->get([
                'id', 'name', 'email', 'role', 'ativo',
            ]),
            'units' => Unidade::query()->where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
            'roles' => [
                ['value' => User::ROLE_GESTOR, 'label' => 'Gestor de unidade'],
                ['value' => User::ROLE_ATENDIMENTO, 'label' => 'Atendimento / Vendedor'],
                ['value' => User::ROLE_MASTER, 'label' => 'Master'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $data['password'] = $request->validate([
            'password' => ['required', 'string', 'min:12'],
        ])['password'];
        DB::transaction(function () use ($data): void {
            $user = User::query()->create(collect($data)->except('unit_ids')->all());
            $user->unidades()->sync($data['unit_ids'] ?? []);
            $this->syncSellers($user, $data['unit_ids'] ?? []);
        });

        return back()->with('success', 'Usuário criado.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validatedData($request, $user);
        $password = $request->input('password');
        if (is_string($password) && $password !== '') {
            $data['password'] = $request->validate([
                'password' => ['required', 'string', 'min:12'],
            ])['password'];
        }

        $this->ensureAnActiveMasterRemains($user, $data['role'], $data['ativo']);
        DB::transaction(function () use ($data, $user): void {
            $user->update(collect($data)->except('unit_ids')->all());
            $user->unidades()->sync($data['unit_ids'] ?? []);
            $this->syncSellers($user, $data['unit_ids'] ?? []);
        });

        return back()->with('success', 'Usuário atualizado.');
    }

    public function setActive(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'ativo' => ['required', 'boolean'],
        ]);

        $this->ensureAnActiveMasterRemains($user, $user->role, $data['ativo']);
        DB::transaction(function () use ($data, $user): void {
            $user->update(['ativo' => $data['ativo']]);
            if ($user->role === User::ROLE_ATENDIMENTO) {
                $user->vendedores()->update(['ativo' => $user->ativo]);
            }
        });

        return back()->with('success', 'Status do usuário atualizado.');
    }

    /**
     * @return array{name: string, email: string, role: string, ativo: bool, unit_ids?: list<int>}
     */
    private function validatedData(Request $request, ?User $user = null): array
    {
        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'role' => ['required', Rule::in(User::ROLES)],
            'ativo' => ['required', 'boolean'],
            'unit_ids' => ['sometimes', 'array'],
            'unit_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('unidades', 'id')->where('ativo', true),
            ],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:12'],
        ]);

        $unitIds = $data['unit_ids'] ?? [];
        if ($data['role'] === User::ROLE_MASTER && $unitIds !== []) {
            throw ValidationException::withMessages([
                'unit_ids' => 'Contas master não devem ser vinculadas a unidades.',
            ]);
        }
        if ($data['role'] !== User::ROLE_MASTER && $unitIds === []) {
            throw ValidationException::withMessages([
                'unit_ids' => 'Selecione ao menos uma unidade para este usuário.',
            ]);
        }

        return $data;
    }

    /**
     * @param  list<int>  $unitIds
     */
    private function syncSellers(User $user, array $unitIds): void
    {
        $unitIds = array_map('intval', $unitIds);
        $sellers = $user->vendedores()->get()->keyBy('unidade_id');

        foreach ($sellers as $seller) {
            if ($user->role !== User::ROLE_ATENDIMENTO || ! in_array($seller->unidade_id, $unitIds, true)) {
                $seller->update(['ativo' => false]);
            }
        }

        if ($user->role !== User::ROLE_ATENDIMENTO) {
            return;
        }

        foreach ($unitIds as $unitId) {
            $seller = $sellers->get($unitId) ?? $user->vendedores()->make([
                'unidade_id' => $unitId,
            ]);
            $seller->fill([
                'nome' => $user->name,
                'ativo' => $user->ativo,
            ])->save();
        }
    }

    private function ensureAnActiveMasterRemains(User $user, string $role, bool $active): void
    {
        if (
            $user->isMaster()
            && $user->ativo
            && ($role !== User::ROLE_MASTER || ! $active)
            && User::query()
                ->where('role', User::ROLE_MASTER)
                ->where('ativo', true)
                ->whereKeyNot($user->getKey())
                ->doesntExist()
        ) {
            throw ValidationException::withMessages([
                'role' => 'Não é possível desativar ou rebaixar o último usuário master ativo.',
            ]);
        }
    }
}
