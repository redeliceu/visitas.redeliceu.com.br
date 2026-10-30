<?php

namespace App\Http\Controllers;

use App\Models\Campanha;
use App\Models\User;
use App\Support\CommercialReportService;
use App\Support\UnitAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        UnitAccess $unitAccess,
        CommercialReportService $reportService,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $units = $unitAccess->availableUnits($user);
        $unitId = $unitAccess->selectedUnitId($request, $user, $units);
        $today = now()->toDateString();
        $report = $reportService->build($user, $unitId, '2000-01-01', $today);
        $campaigns = $unitAccess->scope(Campanha::query(), $user)
            ->whereIn('unidade_id', $units->modelKeys())
            ->when($unitId, fn ($query) => $query->where('unidade_id', $unitId))
            ->with(['unidade:id,nome', 'metasVendedores.vendedor:id,nome'])
            ->orderBy('inicio')
            ->get()
            ->map(function (Campanha $campaign) use ($reportService, $user, $today): array {
                $start = $campaign->inicio?->toDateString();
                $end = min($campaign->fim?->toDateString() ?? $today, $today);
                $summary = $start && $start <= $end
                    ? $reportService->build($user, $campaign->unidade_id, $start, $end)['all']
                    : ['leads' => 0, 'appointments' => 0, 'visits' => 0, 'sales' => 0];

                return [
                    'id' => $campaign->id,
                    'unidade_id' => $campaign->unidade_id,
                    'unidade' => $campaign->unidade,
                    'inicio' => $campaign->inicio?->toDateString(),
                    'fim' => $campaign->fim?->toDateString(),
                    'meta' => $campaign->meta,
                    'sales' => $summary['sales'],
                    'leads' => $summary['leads'],
                    'appointments' => $summary['appointments'],
                    'visits' => $summary['visits'],
                ];
            });

        return Inertia::render('home', [
            'units' => $units->map(fn ($unit) => ['id' => $unit->id, 'nome' => $unit->nome]),
            'selectedUnitId' => $unitId,
            'metrics' => $report['all'],
            'unitRows' => $report['byUnit'],
            'classSummary' => $reportService->classSummary($user, $unitId),
            'campaigns' => $campaigns,
        ]);
    }
}
