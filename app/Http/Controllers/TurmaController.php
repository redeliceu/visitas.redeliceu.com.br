<?php

namespace App\Http\Controllers;

use App\Models\Turma;
use App\Models\Unidade;
use App\Models\User;
use App\Support\UnitAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TurmaController extends Controller
{
    private const PERIODS = ['Manhã', 'Tarde'];

    public function __construct(private readonly UnitAccess $unitAccess) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $units = $this->unitAccess->availableUnits($user);
        $unitId = $this->unitAccess->selectedUnitId($request, $user, $units);

        $classes = $this->unitAccess->scope(Turma::query(), $user)
            ->when($unitId, fn (Builder $query) => $query->where('unidade_id', $unitId))
            ->with('unidade:id,nome')
            ->withCount([
                'matriculas as active_matriculas_count' => fn (Builder $query) => $query
                    ->where('status', 'Ativa'),
            ])
            ->orderBy('periodo')
            ->orderBy('sala')
            ->orderBy('nome')
            ->get();

        return Inertia::render('turmas/index', [
            'classes' => $classes,
            'units' => $units->map(fn (Unidade $unit) => ['id' => $unit->id, 'nome' => $unit->nome]),
            'selectedUnitId' => $unitId,
            'selectedClass' => null,
            'periods' => self::PERIODS,
        ]);
    }

    public function show(Request $request, Turma $turma): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $turma->unidade_id);
        $units = $this->unitAccess->availableUnits($user);
        $unitId = $this->unitAccess->selectedUnitId($request, $user, $units);

        $classes = $this->unitAccess->scope(Turma::query(), $user)
            ->when($unitId, fn (Builder $query) => $query->where('unidade_id', $unitId))
            ->with('unidade:id,nome')
            ->withCount([
                'matriculas as active_matriculas_count' => fn (Builder $query) => $query
                    ->where('status', 'Ativa'),
            ])
            ->orderBy('periodo')
            ->orderBy('sala')
            ->orderBy('nome')
            ->get();

        $selectedClass = $turma->load([
            'unidade:id,nome',
            'matriculas' => fn ($query) => $query
                ->where('status', 'Ativa')
                ->with('visita:id,aluno,responsavel,serie'),
        ]);

        return Inertia::render('turmas/index', [
            'classes' => $classes,
            'units' => $units->map(fn (Unidade $unit) => ['id' => $unit->id, 'nome' => $unit->nome]),
            'selectedUnitId' => $unitId,
            'selectedClass' => $selectedClass,
            'periods' => self::PERIODS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $unitId = (int) $data['unidade_id'];
        $this->unitAccess->ensureCanAccess($user, $unitId);
        DB::transaction(function () use ($data, $unitId): void {
            Unidade::query()->lockForUpdate()->findOrFail($unitId);
            $this->ensureRoomAvailable($unitId, $data['periodo'], $data['sala'] ?? null);
            Turma::query()->create($data);
        });

        return back()->with('success', 'Turma criada.');
    }

    public function update(Request $request, Turma $turma): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $turma->unidade_id);

        $data = $request->validate($this->rules($turma));
        $this->unitAccess->ensureCanAccess($user, (int) $data['unidade_id']);
        DB::transaction(function () use ($data, $turma): void {
            $unitId = (int) $data['unidade_id'];
            Unidade::query()->lockForUpdate()->findOrFail($unitId);
            $lockedClass = Turma::query()->lockForUpdate()->findOrFail($turma->id);

            if ((int) $data['capacidade'] < $lockedClass->matriculas()->where('status', 'Ativa')->count()) {
                throw ValidationException::withMessages([
                    'capacidade' => 'A capacidade não pode ser menor que as matrículas ativas da turma.',
                ]);
            }

            if ($lockedClass->matriculas()->exists() && $unitId !== $lockedClass->unidade_id) {
                throw ValidationException::withMessages([
                    'unidade_id' => 'Não é possível transferir uma turma com histórico de matrículas.',
                ]);
            }

            $this->ensureRoomAvailable(
                $unitId,
                $data['periodo'],
                $data['sala'] ?? null,
                $lockedClass->id,
            );
            $lockedClass->update($data);
        });

        return back()->with('success', 'Turma atualizada.');
    }

    public function bulkStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'unidade_id' => ['required', 'integer', 'exists:unidades,id'],
            'periodo' => ['required', Rule::in(self::PERIODS)],
            'quantidade' => ['required', 'integer', 'min:1', 'max:100'],
            'inicio' => ['nullable', 'integer', 'min:1', 'max:999999'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $unitId = (int) $data['unidade_id'];
        $this->unitAccess->ensureCanAccess($user, $unitId);

        DB::transaction(function () use ($data, $unitId): void {
            Unidade::query()->lockForUpdate()->findOrFail($unitId);
            $existingRooms = Turma::query()
                ->where('unidade_id', $unitId)
                ->where('periodo', $data['periodo'])
                ->pluck('sala')
                ->filter(fn (?string $room) => $room !== null && preg_match('/^\d+$/', $room) === 1)
                ->map(fn (string $room) => (int) $room);

            $start = isset($data['inicio'])
                ? (int) $data['inicio']
                : ((int) $existingRooms->max() + 1);
            $end = $start + (int) $data['quantidade'] - 1;
            if ($end > 999999) {
                throw ValidationException::withMessages([
                    'inicio' => 'A numeração final não pode exceder 999999.',
                ]);
            }
            $duplicates = $existingRooms->contains(fn (int $room) => $room >= $start && $room <= $end);

            if ($duplicates) {
                throw ValidationException::withMessages([
                    'inicio' => 'A numeração informada já está sendo usada neste período.',
                ]);
            }

            $now = now();
            $rows = [];
            for ($number = $start; $number <= $end; $number++) {
                $rows[] = [
                    'unidade_id' => $unitId,
                    'nome' => 'A definir',
                    'periodo' => $data['periodo'],
                    'capacidade' => 0,
                    'sala' => (string) $number,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            Turma::query()->insert($rows);
        });

        return back()->with('success', $data['quantidade'].' salas criadas.');
    }

    public function destroy(Request $request, Turma $turma): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $turma->unidade_id);

        DB::transaction(function () use ($turma): void {
            $lockedClass = Turma::query()->lockForUpdate()->findOrFail($turma->id);
            if ($lockedClass->matriculas()->exists()) {
                throw ValidationException::withMessages([
                    'turma' => 'Não é possível excluir uma turma com histórico de matrículas.',
                ]);
            }
            $lockedClass->delete();
        });

        return back()->with('success', 'Turma excluída.');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(?Turma $turma = null): array
    {
        return [
            'unidade_id' => [
                'required',
                'integer',
                Rule::exists('unidades', 'id')->where('ativo', true),
                ...($turma && $turma->matriculas()->exists()
                    ? [Rule::in([$turma->unidade_id])]
                    : []),
            ],
            'nome' => ['required', 'string', 'max:255'],
            'periodo' => ['required', Rule::in(self::PERIODS)],
            'sala' => ['nullable', 'string', 'max:100'],
            'capacidade' => ['required', 'integer', 'min:0', 'max:10000'],
        ];
    }

    private function ensureRoomAvailable(int $unitId, string $period, ?string $room, ?int $ignoreId = null): void
    {
        $room = trim((string) $room);
        if ($room === '') {
            return;
        }

        $exists = Turma::query()
            ->where('unidade_id', $unitId)
            ->where('periodo', $period)
            ->where('sala', $room)
            ->when($ignoreId, fn (Builder $query) => $query->where('id', '<>', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'sala' => 'Já existe uma turma com esta sala neste período.',
            ]);
        }
    }
}
