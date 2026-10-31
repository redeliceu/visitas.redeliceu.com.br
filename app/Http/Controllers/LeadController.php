<?php

namespace App\Http\Controllers;

use App\Models\Ciclo;
use App\Models\Lead;
use App\Models\LeadHistory;
use App\Models\LeadStatus;
use App\Models\Serie;
use App\Models\Turno;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use App\Support\UnitAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function __construct(private readonly UnitAccess $unitAccess) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $units = $this->unitAccess->availableUnits($user);
        $unitId = $this->unitAccess->selectedUnitId($request, $user, $units);
        $sellers = $this->sellersFor($user);

        $leads = $this->unitAccess->scope(Lead::query(), $user)
            ->when($unitId, fn (Builder $query) => $query->where('unidade_id', $unitId))
            ->with([
                'unidade:id,nome',
                'vendedor:id,nome',
                'serie:id,nome',
                'ciclo:id,nome',
                'turno:id,nome',
                'status:id,nome',
                'statusHistories.fromStatus:id,nome',
                'statusHistories.toStatus:id,nome',
                'statusHistories.changedBy:id,name',
                'editHistories.user:id,name',
                'agendamento',
                'visitas.vendedor:id,nome',
            ])
            ->orderByDesc('data')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('visitas/leads', [
            'leads' => $leads,
            'units' => $units->map(fn (Unidade $unit) => ['id' => $unit->id, 'nome' => $unit->nome]),
            'selectedUnitId' => $unitId,
            'sellers' => $sellers,
            'statuses' => LeadStatus::query()->where('ativo', true)->orderBy('ordem')->get(['id', 'nome']),
            'series' => Serie::query()->where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
            'cycles' => Ciclo::query()->where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
            'shifts' => Turno::query()->where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedLeadData($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, (int) $data['unidade_id']);

        DB::transaction(fn () => Lead::query()->create($data));

        return back()->with('success', 'Lead cadastrado.');
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $lead->unidade_id);
        $data = $this->validatedLeadData($request);
        $this->unitAccess->ensureCanAccess($user, (int) $data['unidade_id']);

        DB::transaction(function () use ($lead, $data, $user): void {
            $lockedLead = Lead::query()->lockForUpdate()->findOrFail($lead->id);
            $original = $lockedLead->getRawOriginal();
            $lockedLead->fill($data);
            $changes = [];
            $fieldLabels = [
                'unidade_id' => 'Unidade',
                'vendedor_id' => 'Vendedor',
                'data' => 'Data do contato',
                'responsavel' => 'Responsável',
                'telefone' => 'Telefone',
                'telefone_secundario' => 'Telefone secundário',
                'email' => 'E-mail',
                'aluno' => 'Aluno',
                'idade' => 'Idade',
                'nascimento' => 'Data de nascimento',
                'serie_id' => 'Série',
                'ciclo_id' => 'Ciclo',
                'turno_id' => 'Turno',
                'canal_mkt' => 'Canal de marketing',
                'canal_contato' => 'Canal de contato',
                'status_id' => 'Situação',
                'retorno_data' => 'Data de retorno',
                'observacoes' => 'Observações',
                'data_matricula' => 'Data de matrícula',
                'desconto' => 'Desconto',
            ];

            foreach ($fieldLabels as $field => $label) {
                if (! array_key_exists($field, $data) || ! $lockedLead->isDirty($field)) {
                    continue;
                }

                $changes[] = [
                    'field' => $label,
                    'old' => $this->historyValue($field, $original[$field] ?? null),
                    'new' => $this->historyValue($field, $lockedLead->getAttributes()[$field] ?? null),
                ];
            }

            $lockedLead->save();

            if ($changes !== []) {
                LeadHistory::query()->create([
                    'lead_id' => $lockedLead->id,
                    'user_id' => $user->id,
                    'changes' => $changes,
                    'created_at' => now(),
                ]);
            }
        });

        return back()->with('success', 'Lead atualizado.');
    }

    public function schedule(Request $request, Lead $lead): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->unitAccess->ensureCanAccess($user, $lead->unidade_id);

        $data = $request->validate([
            'data' => ['required', 'date'],
            'horario' => ['required', 'date_format:H:i'],
        ]);

        DB::transaction(function () use ($lead, $data): void {
            $lockedLead = Lead::query()->lockForUpdate()->findOrFail($lead->id);

            if ($lockedLead->agendamento()->exists()) {
                abort(409, 'Este lead já possui um agendamento.');
            }

            $lockedLead->agendamento()->create([
                'unidade_id' => $lockedLead->unidade_id,
                'vendedor_id' => $lockedLead->vendedor_id,
                'lead_status_anterior_id' => $lockedLead->status_id,
                'data' => $data['data'],
                'horario' => $data['horario'],
                'responsavel' => $lockedLead->responsavel ?? 'Responsável não informado',
                'telefone' => $lockedLead->telefone,
                'aluno' => $lockedLead->aluno,
                'observacoes' => $lockedLead->observacoes,
                'status' => 'Agendado',
            ]);

            $scheduledStatus = LeadStatus::query()->where('nome', 'Agendado')->first();

            if ($scheduledStatus) {
                $lockedLead->update(['status_id' => $scheduledStatus->id]);
            }
        });

        return redirect()->route('agendamentos.index')->with('success', 'Visita agendada.');
    }

    /**
     * @return array<int, array{id: int, unidade_id: int, nome: string, is_current_user: bool}>
     */
    private function sellersFor(User $user): array
    {
        return $this->unitAccess->scope(Vendedor::query(), $user)
            ->where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'unidade_id', 'nome', 'user_id'])
            ->map(function (Vendedor $seller) use ($user): array {
                return [
                    'id' => $seller->id,
                    'unidade_id' => $seller->unidade_id,
                    'nome' => $seller->nome,
                    'is_current_user' => $seller->user_id === $user->id,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedLeadData(Request $request): array
    {
        $unitId = $request->input('unidade_id');

        return $request->validate([
            'unidade_id' => ['required', 'integer', Rule::exists('unidades', 'id')->where('ativo', true)],
            'vendedor_id' => [
                'nullable',
                'integer',
                Rule::exists('vendedores', 'id')->where(fn ($query) => $query
                    ->where('ativo', true)
                    ->where('unidade_id', $unitId)),
            ],
            'data' => ['nullable', 'date'],
            'responsavel' => ['required', 'string', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:40'],
            'telefone_secundario' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'aluno' => ['nullable', 'string', 'max:255'],
            'idade' => ['nullable', 'integer', 'min:0', 'max:120'],
            'nascimento' => ['nullable', 'date'],
            'serie_id' => ['nullable', 'integer', Rule::exists('series', 'id')->where('ativo', true)],
            'ciclo_id' => ['nullable', 'integer', Rule::exists('ciclos', 'id')->where('ativo', true)],
            'turno_id' => ['nullable', 'integer', Rule::exists('turnos', 'id')->where('ativo', true)],
            'canal_mkt' => ['nullable', 'string', 'max:255'],
            'canal_contato' => ['nullable', 'string', 'max:255'],
            'status_id' => ['required', 'integer', Rule::exists('lead_status', 'id')->where('ativo', true)],
            'retorno_data' => ['nullable', 'date'],
            'observacoes' => ['nullable', 'string', 'max:10000'],
            'data_matricula' => ['nullable', 'date'],
            'desconto' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
    }

    private function historyValue(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! is_string($value)) {
            return (string) $value;
        }

        return match ($field) {
            'unidade_id' => Unidade::query()->find($value)->nome,
            'vendedor_id' => Vendedor::query()->find($value)->nome,
            'serie_id' => Serie::query()->find($value)->nome,
            'ciclo_id' => Ciclo::query()->find($value)->nome,
            'turno_id' => Turno::query()->find($value)->nome,
            'status_id' => LeadStatus::query()->find($value)->nome,
            default => (string) $value,
        };
    }
}
