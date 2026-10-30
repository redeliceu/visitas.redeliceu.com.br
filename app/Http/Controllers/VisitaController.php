<?php

namespace App\Http\Controllers;

use App\Models\Agendamento;
use App\Models\LeadStatus;
use App\Models\Matricula;
use App\Models\MatriculaAudit;
use App\Models\Turma;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use App\Models\Visita;
use App\Support\UnitAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VisitaController extends Controller
{
    private const STATUSES = [
        'Em Atendimento',
        'Aguardando',
        'Sem Interesse',
        'Perdido',
        'Follow-up',
    ];

    private const LOSS_REASONS = [
        'Preço',
        'Distância',
        'Escolheu outra escola',
        'Desistência',
        'Outros',
    ];

    public function __construct(private readonly UnitAccess $unitAccess) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $units = $this->unitAccess->availableUnits($user);
        $unitId = $this->unitAccess->selectedUnitId($request, $user, $units);

        $visits = $this->unitAccess->scope(Visita::query(), $user)
            ->when($unitId, fn (Builder $query) => $query->where('unidade_id', $unitId))
            ->with([
                'unidade:id,nome',
                'vendedor:id,nome',
                'matriculaAtiva.turma:id,nome,periodo',
                'matriculas:id,visita_id,status,turma_id',
            ])
            ->orderByDesc('data')
            ->orderByDesc('horario')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('visitas/index', [
            'visits' => $visits,
            'units' => $units->map(fn (Unidade $unit) => ['id' => $unit->id, 'nome' => $unit->nome]),
            'selectedUnitId' => $unitId,
            'sellers' => $this->sellersFor($user, $unitId),
            'statuses' => self::STATUSES,
            'lossReasons' => self::LOSS_REASONS,
            'classes' => $this->classesFor($user, $unitId),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedVisitData($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, (int) $data['unidade_id']);

        Visita::query()->create($data + [
            'fonte' => 'Manual',
            'situacao' => 'Aguardando',
            'status' => 'Aguardando',
        ]);

        return back()->with('success', 'Visita cadastrada.');
    }

    public function update(Request $request, Visita $visita): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $visita->unidade_id);

        $data = $this->validatedVisitData($request, $visita);
        $this->unitAccess->ensureCanAccess($user, (int) $data['unidade_id']);
        $visita->update($data);

        return back()->with('success', 'Visita atualizada.');
    }

    public function updateStatus(Request $request, Visita $visita): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $visita->unidade_id);

        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'vendedor_id' => [
                'required',
                'integer',
                Rule::exists('vendedores', 'id')->where(fn ($query) => $query
                    ->where('ativo', true)
                    ->where('unidade_id', $visita->unidade_id)),
            ],
            'motivo_perda' => [
                Rule::requiredIf($request->input('status') === 'Perdido'),
                'nullable',
                'string',
                Rule::in(self::LOSS_REASONS),
            ],
            'retorno_data' => [
                Rule::requiredIf($request->input('status') === 'Follow-up'),
                'nullable',
                'date',
            ],
        ]);

        DB::transaction(function () use ($visita, $data): void {
            $lockedVisit = Visita::query()->lockForUpdate()->findOrFail($visita->id);

            if ($lockedVisit->matriculaAtiva()->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'Cancele a matrícula ativa antes de alterar o status da visita.',
                ]);
            }

            $lockedVisit->update([
                'status' => $data['status'],
                'vendedor_id' => $data['vendedor_id'],
                'motivo_perda' => $data['status'] === 'Perdido' ? $data['motivo_perda'] : null,
                'retorno_data' => $data['status'] === 'Follow-up' ? $data['retorno_data'] : null,
            ]);
        });

        return back()->with('success', 'Status da visita atualizado.');
    }

    public function enroll(Request $request, Visita $visita): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $visita->unidade_id);
        $data = $request->validate([
            'turma_id' => ['required', 'integer', 'exists:turmas,id'],
        ]);

        DB::transaction(function () use ($visita, $data, $user): void {
            $lockedVisit = Visita::query()->lockForUpdate()->findOrFail($visita->id);
            $class = Turma::query()->lockForUpdate()->findOrFail((int) $data['turma_id']);

            if ($class->unidade_id !== $lockedVisit->unidade_id) {
                throw ValidationException::withMessages([
                    'turma_id' => 'Selecione uma turma da mesma unidade da visita.',
                ]);
            }

            if ($class->matriculas()->where('status', 'Ativa')->count() >= $class->capacidade) {
                throw ValidationException::withMessages([
                    'turma_id' => 'Esta turma não possui vagas disponíveis.',
                ]);
            }

            if ($lockedVisit->matriculaAtiva()->exists()) {
                throw ValidationException::withMessages([
                    'turma_id' => 'Esta visita já possui uma matrícula ativa.',
                ]);
            }

            $previousEnrollment = $lockedVisit->matriculas()
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($previousEnrollment) {
                $oldStatus = $previousEnrollment->status;
                $oldClassId = $previousEnrollment->turma_id;
                $previousEnrollment->update([
                    'turma_id' => $class->id,
                    'data' => now(),
                    'status' => 'Ativa',
                ]);
                $enrollment = $previousEnrollment;
                $action = 'Reativada';
            } else {
                $enrollment = Matricula::query()->create([
                    'unidade_id' => $lockedVisit->unidade_id,
                    'lead_id' => $lockedVisit->lead_id,
                    'visita_id' => $lockedVisit->id,
                    'turma_id' => $class->id,
                    'data' => now(),
                    'status' => 'Ativa',
                ]);
                $oldStatus = null;
                $oldClassId = null;
                $action = 'Criada';
            }

            MatriculaAudit::query()->create([
                'matricula_id' => $enrollment->id,
                'user_id' => $user->id,
                'turma_anterior_id' => $oldClassId,
                'turma_id' => $class->id,
                'acao' => $action,
                'status_anterior' => $oldStatus,
                'status' => 'Ativa',
            ]);

            $lockedVisit->update(['status' => 'Matriculado']);
            $this->updateLeadStatus($lockedVisit, 'Matriculado');
        });

        return back()->with('success', 'Matrícula efetivada.');
    }

    public function cancelEnrollment(Request $request, Visita $visita): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $visita->unidade_id);

        DB::transaction(function () use ($visita, $user): void {
            $lockedVisit = Visita::query()->lockForUpdate()->findOrFail($visita->id);
            $enrollment = $lockedVisit->matriculas()
                ->where('status', 'Ativa')
                ->lockForUpdate()
                ->first();

            if (! $enrollment) {
                throw ValidationException::withMessages([
                    'matricula' => 'Não há matrícula ativa para cancelar.',
                ]);
            }

            $enrollment->update(['status' => 'Cancelada']);
            MatriculaAudit::query()->create([
                'matricula_id' => $enrollment->id,
                'user_id' => $user->id,
                'turma_anterior_id' => $enrollment->turma_id,
                'turma_id' => $enrollment->turma_id,
                'acao' => 'Cancelada',
                'status_anterior' => 'Ativa',
                'status' => 'Cancelada',
            ]);

            $lockedVisit->update(['status' => 'Aguardando']);
            $this->updateLeadStatus($lockedVisit, 'Compareceu');
        });

        return back()->with('success', 'Matrícula cancelada; a visita voltou para Aguardando.');
    }

    public function destroy(Request $request, Visita $visita): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $visita->unidade_id);

        $deleted = DB::transaction(function () use ($visita): bool {
            $lockedVisit = Visita::query()->lockForUpdate()->findOrFail($visita->id);

            if ($lockedVisit->matriculas()->exists()) {
                return false;
            }

            Agendamento::query()
                ->where('visita_id', $lockedVisit->id)
                ->lockForUpdate()
                ->get()
                ->each(function (Agendamento $appointment): void {
                    $appointment->update([
                        'visita_id' => null,
                        'status' => 'Agendado',
                    ]);
                    $this->updateLeadStatusForAppointment($appointment, 'Agendado');
                });

            $lockedVisit->delete();

            return true;
        });

        if (! $deleted) {
            return back()->withErrors([
                'visit' => 'Não é possível excluir uma visita com histórico de matrícula.',
            ]);
        }

        return back()->with('success', 'Visita excluída.');
    }

    /**
     * @return Collection<int, Vendedor>
     */
    private function sellersFor(User $user, ?int $unitId): Collection
    {
        return $this->unitAccess->scope(Vendedor::query(), $user)
            ->where('ativo', true)
            ->when($unitId, fn (Builder $query) => $query->where('unidade_id', $unitId))
            ->orderBy('nome')
            ->get(['id', 'unidade_id', 'nome']);
    }

    /**
     * @return Collection<int, Turma>
     */
    private function classesFor(User $user, ?int $unitId): Collection
    {
        return $this->unitAccess->scope(Turma::query(), $user)
            ->when($unitId, fn (Builder $query) => $query->where('unidade_id', $unitId))
            ->with('unidade:id,nome')
            ->withCount([
                'matriculas as active_matriculas_count' => fn (Builder $query) => $query
                    ->where('status', 'Ativa'),
            ])
            ->orderBy('nome')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedVisitData(Request $request, ?Visita $visita = null): array
    {
        $unitId = $request->input('unidade_id');
        $unitRule = ['required', 'integer', Rule::exists('unidades', 'id')->where('ativo', true)];

        if ($visita !== null) {
            $unitRule[] = Rule::in([(string) $visita->unidade_id]);
        }

        return $request->validate([
            'unidade_id' => $unitRule,
            'vendedor_id' => [
                'nullable',
                'integer',
                Rule::exists('vendedores', 'id')->where(fn ($query) => $query
                    ->where('ativo', true)
                    ->where('unidade_id', $unitId)),
            ],
            'data' => ['required', 'date'],
            'horario' => ['nullable', 'date_format:H:i'],
            'responsavel' => ['required', 'string', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:40'],
            'telefone2' => ['nullable', 'string', 'max:40'],
            'aluno' => ['nullable', 'string', 'max:255'],
            'idade' => ['nullable', 'integer', 'min:0', 'max:120'],
            'serie' => ['nullable', 'string', 'max:255'],
            'instituicao' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string', 'max:10000'],
        ]);
    }

    private function updateLeadStatus(Visita $visit, string $status): void
    {
        if ($visit->lead_id === null) {
            return;
        }

        $statusId = LeadStatus::query()->where('nome', $status)->value('id');

        if ($statusId !== null) {
            $lead = $visit->lead()
                ->where('unidade_id', $visit->unidade_id)
                ->first();

            $lead?->update(['status_id' => $statusId]);
        }
    }

    private function updateLeadStatusForAppointment(Agendamento $appointment, string $status): void
    {
        if ($appointment->lead_id === null) {
            return;
        }

        $statusId = LeadStatus::query()->where('nome', $status)->value('id');

        if ($statusId !== null) {
            $lead = $appointment->lead()
                ->where('unidade_id', $appointment->unidade_id)
                ->first();

            $lead?->update(['status_id' => $statusId]);
        }
    }
}
