<?php

namespace App\Http\Controllers;

use App\Models\Agendamento;
use App\Models\LeadStatus;
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
use Inertia\Inertia;
use Inertia\Response;

class AgendamentoController extends Controller
{
    private const STATUSES = [
        'Agendado',
        'Compareceu',
        'Não compareceu',
        'Cancelado',
    ];

    public function __construct(private readonly UnitAccess $unitAccess) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $units = $this->unitAccess->availableUnits($user);
        $unitId = $this->unitAccess->selectedUnitId($request, $user, $units);

        $appointments = $this->unitAccess->scope(Agendamento::query(), $user)
            ->when($unitId, fn (Builder $query) => $query->where('unidade_id', $unitId))
            ->with(['unidade:id,nome', 'vendedor:id,nome', 'lead:id', 'visita:id'])
            ->orderByDesc('data')
            ->orderByDesc('horario')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('visitas/agendamentos', [
            'appointments' => $appointments,
            'units' => $units->map(fn (Unidade $unit) => ['id' => $unit->id, 'nome' => $unit->nome]),
            'selectedUnitId' => $unitId,
            'sellers' => $this->sellersFor($user, $unitId),
            'statuses' => self::STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedAppointmentData($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, (int) $data['unidade_id']);

        Agendamento::query()->create($data);

        return back()->with('success', 'Agendamento cadastrado.');
    }

    public function update(Request $request, Agendamento $agendamento): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $agendamento->unidade_id);
        $data = $this->validatedAppointmentData($request, $agendamento);
        $this->unitAccess->ensureCanAccess($user, (int) $data['unidade_id']);
        $agendamento->update($data);

        return back()->with('success', 'Agendamento atualizado.');
    }

    public function destroy(Request $request, Agendamento $agendamento): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $agendamento->unidade_id);

        $deleted = DB::transaction(function () use ($agendamento): bool {
            $lockedAppointment = Agendamento::query()
                ->lockForUpdate()
                ->findOrFail($agendamento->id);

            if ($lockedAppointment->visita_id !== null) {
                return false;
            }

            if ($lockedAppointment->lead_id !== null) {
                $lead = $lockedAppointment->lead()
                    ->lockForUpdate()
                    ->first();
                $scheduledStatusId = LeadStatus::query()
                    ->where('nome', 'Agendado')
                    ->value('id');

                if ($lead && $lead->status_id === $scheduledStatusId) {
                    $lead->update([
                        'status_id' => $lockedAppointment->lead_status_anterior_id,
                    ]);
                }
            }

            $lockedAppointment->delete();

            return true;
        });

        if (! $deleted) {
            return back()->withErrors([
                'appointment' => 'Não é possível excluir um agendamento já vinculado a uma visita.',
            ]);
        }

        return back()->with('success', 'Agendamento excluído.');
    }

    public function convert(Request $request, Agendamento $agendamento): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $agendamento->unidade_id);

        if ($agendamento->status === 'Cancelado') {
            return back()->withErrors([
                'appointment' => 'Agendamento cancelado não pode ser transformado em visita.',
            ]);
        }

        DB::transaction(function () use ($agendamento): void {
            $lockedAppointment = Agendamento::query()
                ->lockForUpdate()
                ->findOrFail($agendamento->id);

            $visit = $lockedAppointment->visita_id
                ? Visita::query()->find($lockedAppointment->visita_id)
                : Visita::query()->where('agendamento_id', $lockedAppointment->id)->first();

            if ($visit === null) {
                $visit = Visita::query()->create([
                    'unidade_id' => $lockedAppointment->unidade_id,
                    'lead_id' => $lockedAppointment->lead_id,
                    'vendedor_id' => $lockedAppointment->vendedor_id,
                    'agendamento_id' => $lockedAppointment->id,
                    'data' => $lockedAppointment->data,
                    'horario' => $lockedAppointment->horario,
                    'responsavel' => $lockedAppointment->responsavel,
                    'telefone' => $lockedAppointment->telefone,
                    'aluno' => $lockedAppointment->aluno ?: 'A preencher',
                    'fonte' => 'Agendamento Lead',
                    'status' => 'Aguardando',
                    'observacoes' => $lockedAppointment->observacoes,
                ]);
            }

            $lockedAppointment->update([
                'visita_id' => $visit->id,
                'status' => 'Compareceu',
            ]);

            if ($lockedAppointment->lead_id !== null) {
                $compareceuStatus = LeadStatus::query()
                    ->where('nome', 'Compareceu')
                    ->first();

                if ($compareceuStatus) {
                    $lead = $lockedAppointment->lead()
                        ->where('unidade_id', $lockedAppointment->unidade_id)
                        ->first();

                    $lead?->update(['status_id' => $compareceuStatus->id]);
                }
            }
        });

        return back()->with('success', 'Visita registrada a partir do agendamento.');
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
     * @return array<string, mixed>
     */
    private function validatedAppointmentData(Request $request, ?Agendamento $agendamento = null): array
    {
        $unitId = $request->input('unidade_id');

        $rules = [
            'unidade_id' => ['required', 'integer', Rule::exists('unidades', 'id')->where('ativo', true)],
            'vendedor_id' => [
                'nullable',
                'integer',
                Rule::exists('vendedores', 'id')->where(fn ($query) => $query
                    ->where('ativo', true)
                    ->where('unidade_id', $unitId)),
            ],
            'data' => ['required', 'date'],
            'horario' => ['required', 'date_format:H:i'],
            'responsavel' => ['required', 'string', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:40'],
            'aluno' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(self::STATUSES)],
        ];

        if ($agendamento?->lead_id !== null) {
            $rules['unidade_id'][] = Rule::in([(string) $agendamento->unidade_id]);
        }

        return $request->validate($rules);
    }
}
