<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Support\CommercialReportService;
use App\Support\SellerPerformanceService;
use App\Support\UnitAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class VendedorController extends Controller
{
    public function index(
        Request $request,
        UnitAccess $unitAccess,
        SellerPerformanceService $performance,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $units = $unitAccess->availableUnits($user);
        $unitId = $unitAccess->selectedUnitId($request, $user, $units);
        $data = $performance->build($user, $unitId);

        return Inertia::render('vendedores/index', [
            'units' => $units->map(fn ($unit) => ['id' => $unit->id, 'nome' => $unit->nome]),
            'selectedUnitId' => $unitId,
            ...$data,
        ]);
    }

    public function sales(
        Request $request,
        UnitAccess $unitAccess,
        SellerPerformanceService $performance,
        CommercialReportService $reportService,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $trackingStartedAt = DB::table('lead_status_tracking')
            ->where('id', 1)
            ->value('started_at');
        abort_unless(
            is_string($trackingStartedAt),
            503,
            'O histórico de status ainda não foi inicializado.',
        );
        $validated = $request->validate([
            'snapshot_date' => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:'.substr($trackingStartedAt, 0, 10),
                'before_or_equal:today',
            ],
            'seller_id' => ['nullable', 'integer', 'min:1'],
            'lead_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $snapshotDate = $validated['snapshot_date'] ?? now()->toDateString();
        $selectedSellerId = isset($validated['seller_id']) ? (int) $validated['seller_id'] : null;
        $units = $unitAccess->availableUnits($user);
        $unitId = $unitAccess->selectedUnitId($request, $user, $units);
        $data = $performance->build(
            $user,
            $unitId,
            CarbonImmutable::parse($snapshotDate)->endOfDay(),
        );
        $until = now()->toDateString();
        $report = $reportService->build($user, $unitId, '2000-01-01', $until);
        $sellerIds = array_column($data['sellers'], 'id');
        $sellerRows = collect($report['bySeller'])
            ->whereIn('seller_id', $sellerIds)
            ->keyBy('seller_id');
        $metrics = $user->role === User::ROLE_ATENDIMENTO
            ? $sellerRows->reduce(fn (array $totals, array $row): array => [
                'label' => 'Meu desempenho',
                'leads' => $totals['leads'] + $row['leads'],
                'appointments' => $totals['appointments'] + $row['appointments'],
                'visits' => $totals['visits'] + $row['visits'],
                'sales' => $totals['sales'] + $row['sales'],
            ], ['label' => 'Meu desempenho', 'leads' => 0, 'appointments' => 0, 'visits' => 0, 'sales' => 0])
            : $report['all'];
        $sellers = collect($data['sellers'])
            ->map(function (array $seller) use ($sellerRows): array {
                return [
                    ...$seller,
                    'metrics' => $sellerRows->get($seller['id']) ?? [
                        'leads' => 0,
                        'appointments' => 0,
                        'visits' => 0,
                        'sales' => 0,
                    ],
                ];
            })
            ->all();
        $selectedSeller = $selectedSellerId
            ? collect($sellers)->firstWhere('id', $selectedSellerId)
            : null;
        abort_if($selectedSellerId !== null && $selectedSeller === null, 404);
        $sellerDetail = null;
        $selectedLead = null;

        if ($selectedSeller !== null) {
            $sellerId = (int) $selectedSeller['id'];
            $sellerDetail = [
                ...$selectedSeller,
                'follow_up_leads' => $performance->followUpLeads(
                    $user,
                    (int) $selectedSeller['unidade_id'],
                    $sellerId,
                    CarbonImmutable::parse($snapshotDate)->endOfDay(),
                ),
                'enrollments' => $reportService->sellerSales(
                    $user,
                    (int) $selectedSeller['unidade_id'],
                    $sellerId,
                    '2000-01-01',
                    $until,
                ),
            ];

            if (isset($validated['lead_id'])) {
                $visibleLeadIds = collect($sellerDetail['follow_up_leads'])
                    ->pluck('id')
                    ->merge(collect($sellerDetail['enrollments'])->pluck('lead_id'))
                    ->filter()
                    ->unique();
                abort_unless($visibleLeadIds->contains((int) $validated['lead_id']), 404);
                $selectedLead = $unitAccess->scope(Lead::query(), $user)
                    ->where('unidade_id', $selectedSeller['unidade_id'])
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
                        'agendamentos',
                        'visitas.vendedor:id,nome',
                    ])
                    ->findOrFail((int) $validated['lead_id']);
            }
        } else {
            abort_if(isset($validated['lead_id']), 404);
        }

        return Inertia::render('painel-vendas', [
            'units' => $units->map(fn ($unit) => ['id' => $unit->id, 'nome' => $unit->nome]),
            'selectedUnitId' => $unitId,
            'snapshotDate' => $snapshotDate,
            'today' => now()->toDateString(),
            'historyStartDate' => substr($trackingStartedAt, 0, 10),
            'metrics' => $metrics,
            'statuses' => $data['statuses'],
            'sellers' => $sellers,
            'selectedSellerId' => $selectedSellerId,
            'sellerDetail' => $sellerDetail,
            'selectedLead' => $selectedLead,
        ]);
    }
}
