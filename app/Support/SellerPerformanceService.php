<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\LeadStatusHistory;
use App\Models\User;
use App\Models\Vendedor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SellerPerformanceService
{
    public function __construct(private readonly UnitAccess $unitAccess) {}

    /**
     * @return array{
     *     statuses: array<int, array{id: int, nome: string}>,
     *     sellers: array<int, array{
     *         id: int,
     *         unidade_id: int,
     *         unidade: string,
     *         nome: string,
     *         ativo: bool,
     *         user_id: int|null,
     *         user_name: string|null,
     *         leads: int,
     *         status_counts: array<int, int>
     *     }>
     * }
     */
    public function build(User $user, ?int $unitId, ?CarbonImmutable $snapshotAt = null): array
    {
        $snapshotAt ??= CarbonImmutable::now()->endOfDay();
        $units = $this->unitAccess->availableUnits($user);
        $unitIds = $units->modelKeys();
        $statuses = LeadStatus::query()
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get(['id', 'nome']);
        $sellers = $this->unitAccess->scope(Vendedor::query(), $user)
            ->whereIn('unidade_id', $unitIds)
            ->when($unitId, fn (Builder $query) => $query->where('unidade_id', $unitId))
            ->when(
                $user->role === User::ROLE_ATENDIMENTO,
                fn (Builder $query) => $query->where('user_id', $user->id),
            )
            ->with(['unidade:id,nome', 'user:id,name'])
            ->orderBy('unidade_id')
            ->orderBy('nome')
            ->get();
        $sellerIds = $sellers->modelKeys();
        $statusCounts = collect();

        if ($sellerIds !== []) {
            $latestStatus = LeadStatusHistory::query()
                ->select('to_status_id')
                ->whereColumn('lead_id', 'leads.id')
                ->where('changed_at', '<=', $snapshotAt)
                ->orderByDesc('changed_at')
                ->orderByDesc('id')
                ->limit(1);
            $leadSnapshots = $this->unitAccess->scope(Lead::query(), $user)
                ->whereIn('unidade_id', $unitIds)
                ->when($unitId, fn (Builder $query) => $query->where('unidade_id', $unitId))
                ->whereIn('vendedor_id', $sellerIds)
                ->where('created_at', '<=', $snapshotAt)
                ->select('vendedor_id')
                ->selectSub($latestStatus, 'snapshot_status_id');
            $statusCounts = DB::query()
                ->fromSub($leadSnapshots, 'lead_snapshots')
                ->selectRaw('vendedor_id, snapshot_status_id as status_id, COUNT(*) as total')
                ->whereNotNull('snapshot_status_id')
                ->groupBy('vendedor_id', 'snapshot_status_id')
                ->get()
                ->keyBy(fn ($row): string => $row->vendedor_id.':'.$row->status_id);
        }

        return [
            'statuses' => $statuses
                ->map(fn (LeadStatus $status): array => [
                    'id' => $status->id,
                    'nome' => $status->nome,
                ])
                ->all(),
            'sellers' => $sellers
                ->map(function (Vendedor $seller) use ($statuses, $statusCounts): array {
                    $counts = [];
                    $followUpCounts = [
                        'atendendo' => 0,
                        'em_negociacao' => 0,
                        'agendado' => 0,
                    ];
                    foreach ($statuses as $status) {
                        $counts[$status->id] = (int) ($statusCounts->get($seller->id.':'.$status->id)->total ?? 0);
                        $followUpKey = match ($status->nome) {
                            'Atendendo' => 'atendendo',
                            'Em Negociação' => 'em_negociacao',
                            'Agendado' => 'agendado',
                            default => null,
                        };

                        if ($followUpKey !== null) {
                            $followUpCounts[$followUpKey] = $counts[$status->id];
                        }
                    }

                    return [
                        'id' => $seller->id,
                        'unidade_id' => $seller->unidade_id,
                        'unidade' => $seller->unidade->nome,
                        'nome' => $seller->nome,
                        'ativo' => $seller->ativo,
                        'user_id' => $seller->user_id,
                        'user_name' => $seller->user?->name,
                        'leads' => array_sum($counts),
                        'status_counts' => $counts,
                        'follow_up_counts' => $followUpCounts,
                    ];
                })
                ->all(),
        ];
    }

    /**
     * @return array<int, array{
     *     id: int,
     *     data: string|null,
     *     aluno: string|null,
     *     responsavel: string,
     *     telefone: string|null,
     *     status: string
     * }>
     */
    public function followUpLeads(
        User $user,
        int $unitId,
        int $sellerId,
        CarbonImmutable $snapshotAt,
    ): array {
        $followUpStatuses = LeadStatus::query()
            ->whereIn('nome', ['Atendendo', 'Em Negociação', 'Agendado'])
            ->get(['id', 'nome'])
            ->keyBy('id');

        if ($followUpStatuses->isEmpty()) {
            return [];
        }

        $latestStatus = LeadStatusHistory::query()
            ->select('to_status_id')
            ->whereColumn('lead_id', 'leads.id')
            ->where('changed_at', '<=', $snapshotAt)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->limit(1);
        $leadSnapshots = $this->unitAccess->scope(Lead::query(), $user)
            ->where('unidade_id', $unitId)
            ->where('vendedor_id', $sellerId)
            ->where('created_at', '<=', $snapshotAt)
            ->select(['id', 'data'])
            ->selectSub($latestStatus, 'snapshot_status_id');
        $followUpSnapshots = DB::query()
            ->fromSub($leadSnapshots, 'lead_snapshots')
            ->select(['id', 'snapshot_status_id'])
            ->whereIn('snapshot_status_id', $followUpStatuses->modelKeys())
            ->get()
            ->keyBy('id');

        if ($followUpSnapshots->isEmpty()) {
            return [];
        }

        return $this->unitAccess->scope(Lead::query(), $user)
            ->where('unidade_id', $unitId)
            ->whereIn('id', $followUpSnapshots->keys())
            ->orderByDesc('data')
            ->orderByDesc('id')
            ->get(['id', 'data', 'aluno', 'responsavel', 'telefone'])
            ->map(function (Lead $lead) use ($followUpSnapshots, $followUpStatuses): array {
                $statusId = $followUpSnapshots->get($lead->id)->snapshot_status_id;

                return [
                    'id' => $lead->id,
                    'data' => $lead->data,
                    'aluno' => $lead->aluno,
                    'responsavel' => $lead->responsavel,
                    'telefone' => $lead->telefone,
                    'status' => $followUpStatuses->get($statusId)->nome,
                ];
            })
            ->all();
    }
}
