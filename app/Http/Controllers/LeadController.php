<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Serie;
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
                'status:id,nome',
                'agendamento:id,lead_id',
                'statusHistories.fromStatus:id,nome',
                'statusHistories.toStatus:id,nome',
                'statusHistories.changedBy:id,name',
            ])
            ->withCount('statusHistories')
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
        abort_if($lead->agendamento()->exists(), 404);

        $data = $this->validatedLeadData($request);
        $this->unitAccess->ensureCanAccess($user, (int) $data['unidade_id']);
        DB::transaction(fn () => $lead->update($data));

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
            'aluno' => ['nullable', 'string', 'max:255'],
            'idade' => ['nullable', 'integer', 'min:0', 'max:120'],
            'serie_id' => ['nullable', 'integer', Rule::exists('series', 'id')->where('ativo', true)],
            'canal_mkt' => ['nullable', 'string', 'max:255'],
            'canal_contato' => ['nullable', 'string', 'max:255'],
            'status_id' => ['required', 'integer', Rule::exists('lead_status', 'id')->where('ativo', true)],
            'observacoes' => ['nullable', 'string', 'max:10000'],
        ]);
    }
}
