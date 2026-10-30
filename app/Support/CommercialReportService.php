<?php

namespace App\Support;

use App\Models\Agendamento;
use App\Models\Lead;
use App\Models\Turma;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use App\Models\Visita;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CommercialReportService
{
    public function __construct(private readonly UnitAccess $unitAccess) {}

    /**
     * @return array{
     *     all: array<string, int|string>,
     *     byUnit: array<int, array{label: string, unit_id: int, seller_id: int, leads: int, appointments: int, visits: int, sales: int}>,
     *     bySeller: array<int, array{label: string, unit_id: int, seller_id: int, leads: int, appointments: int, visits: int, sales: int}>,
     *     units: \Illuminate\Database\Eloquent\Collection<int, Unidade>,
     *     sellers: \Illuminate\Database\Eloquent\Collection<int, Vendedor>
     * }
     */
    public function build(
        User $user,
        ?int $unitId,
        string $start,
        string $end,
        ?int $sellerId = null,
    ): array {
        $units = $this->unitAccess->availableUnits($user)
            ->when($unitId, fn (Collection $collection) => $collection->where('id', $unitId)->values());
        $unitIds = $units->modelKeys();
        $leads = $this->unitAccess->scope(Lead::query(), $user)
            ->whereIn('unidade_id', $unitIds)
            ->whereDate('data', '>=', $start)
            ->whereDate('data', '<=', $end)
            ->when($sellerId, fn (Builder $query) => $query->where('vendedor_id', $sellerId))
            ->get(['id', 'unidade_id', 'vendedor_id']);
        $appointments = $this->unitAccess->scope(Agendamento::query(), $user)
            ->whereIn('unidade_id', $unitIds)
            ->whereDate('data', '>=', $start)
            ->whereDate('data', '<=', $end)
            ->when($sellerId, fn (Builder $query) => $query->where('vendedor_id', $sellerId))
            ->get(['id', 'unidade_id', 'vendedor_id']);
        $visits = $this->unitAccess->scope(Visita::query(), $user)
            ->whereIn('unidade_id', $unitIds)
            ->whereDate('data', '>=', $start)
            ->whereDate('data', '<=', $end)
            ->with(['matriculaAtiva:id,visita_id', 'lead:id,vendedor_id'])
            ->get(['id', 'unidade_id', 'lead_id', 'vendedor_id', 'status']);

        $salesVisits = $visits->filter(fn (Visita $visit) => in_array($visit->status, [
            'Matriculado',
            'Matrícula',
        ], true) || $visit->matriculaAtiva !== null);
        $linkedLeadIds = $salesVisits->pluck('lead_id')->filter()->unique()->all();
        $historicalSales = $this->unitAccess->scope(Lead::query(), $user)
            ->whereIn('unidade_id', $unitIds)
            ->whereHas('status', fn (Builder $query) => $query->where('nome', 'Matriculado'))
            ->where(function (Builder $query) use ($start, $end): void {
                $query->where(function (Builder $dated) use ($start, $end): void {
                    $dated->whereNotNull('data_matricula')
                        ->whereDate('data_matricula', '>=', $start)
                        ->whereDate('data_matricula', '<=', $end);
                })->orWhere(function (Builder $undated) use ($start, $end): void {
                    $undated->whereNull('data_matricula')
                        ->whereDate('data', '>=', $start)
                        ->whereDate('data', '<=', $end);
                });
            })
            ->when($linkedLeadIds, fn (Builder $query) => $query->whereNotIn('id', $linkedLeadIds))
            ->when($sellerId, fn (Builder $query) => $query->where('vendedor_id', $sellerId))
            ->get(['id', 'unidade_id', 'vendedor_id'])
            ->map(fn (Lead $lead): array => [
                'unidade_id' => $lead->unidade_id,
                'vendedor_id' => $lead->vendedor_id,
            ]);

        $sales = $salesVisits
            ->map(fn (Visita $visit): array => [
                'unidade_id' => $visit->unidade_id,
                'vendedor_id' => $visit->vendedor_id ?? $visit->lead?->vendedor_id,
            ])
            ->concat($historicalSales);

        if ($sellerId !== null) {
            $sales = $sales->filter(fn (array $sale) => (int) $sale['vendedor_id'] === $sellerId);
        }

        $row = function (string $label, ?int $targetUnitId = null, ?int $targetSellerId = null) use (
            $leads,
            $appointments,
            $visits,
            $sales,
        ): array {
            $matches = fn ($record): bool => ($targetUnitId === null || (int) $record->unidade_id === $targetUnitId)
                && ($targetSellerId === null || (int) $record->vendedor_id === $targetSellerId);
            $matchSale = fn (array $sale): bool => ($targetUnitId === null || (int) $sale['unidade_id'] === $targetUnitId)
                && ($targetSellerId === null || (int) $sale['vendedor_id'] === $targetSellerId);

            return [
                'label' => $label,
                'unit_id' => $targetUnitId ?? 0,
                'seller_id' => $targetSellerId ?? 0,
                'leads' => $leads->filter($matches)->count(),
                'appointments' => $appointments->filter($matches)->count(),
                'visits' => $visits->filter($matches)->count(),
                'sales' => $sales->filter($matchSale)->count(),
            ];
        };

        $sellers = Vendedor::query()
            ->whereIn('unidade_id', $unitIds)
            ->with('unidade:id,nome')
            ->orderBy('nome')
            ->get();

        $selectedUnit = $units->first();

        return [
            'all' => $row($unitId && $selectedUnit instanceof Unidade ? $selectedUnit->nome : 'Geral', $unitId),
            'byUnit' => $units->map(fn (Unidade $unit): array => $row($unit->nome, $unit->id))->values()->all(),
            'bySeller' => $sellers
                ->when($sellerId, fn (Collection $collection) => $collection
                    ->where('id', $sellerId))
                ->map(fn (Vendedor $seller): array => $row(
                    $seller->nome.' • '.$seller->unidade->nome,
                    $seller->unidade_id,
                    $seller->id,
                ))
                ->values()
                ->all(),
            'units' => $units,
            'sellers' => $sellers,
        ];
    }

    /**
     * @return array{classes: int, capacity: int, occupied: int}
     */
    public function classSummary(User $user, ?int $unitId): array
    {
        $classes = $this->unitAccess->scope(Turma::query(), $user)
            ->when($unitId, fn (Builder $query) => $query->where('unidade_id', $unitId))
            ->withCount([
                'matriculas as active_matriculas_count' => fn (Builder $query) => $query
                    ->where('status', 'Ativa'),
            ])
            ->get(['id', 'capacidade']);

        return [
            'classes' => $classes->count(),
            'capacity' => $classes->sum('capacidade'),
            'occupied' => $classes->sum('active_matriculas_count'),
        ];
    }
}
