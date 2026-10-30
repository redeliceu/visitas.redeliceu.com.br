<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Vendedor;
use App\Support\CommercialReportPdf;
use App\Support\CommercialReportService;
use App\Support\UnitAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class RelatorioController extends Controller
{
    public function __construct(private readonly UnitAccess $unitAccess) {}

    public function index(Request $request, CommercialReportService $reportService): Response
    {
        [$user, $filters, $report] = $this->data($request, $reportService);

        return Inertia::render('relatorios/index', [
            'report' => $report,
            'units' => $this->unitAccess->availableUnits($user)->map(fn ($unit) => [
                'id' => $unit->id,
                'nome' => $unit->nome,
            ]),
            'sellers' => $report['sellers']->map(fn ($seller) => [
                'id' => $seller->id,
                'nome' => $seller->nome,
                'unidade' => $seller->unidade?->nome,
            ]),
            'selectedUnitId' => $filters['unidade_id'],
            'filters' => $filters,
        ]);
    }

    public function csv(Request $request, CommercialReportService $reportService): HttpResponse
    {
        [, $filters, $report] = $this->data($request, $reportService);
        $rows = [['Grupo', 'Nome', 'Leads', 'Agendamentos', 'Visitas', 'Matrículas', 'Lead/Agendamento', 'Agendamento/Visita', 'Visita/Matrícula', 'Lead/Matrícula']];

        foreach ([
            ['Geral', [$report['all']]],
            ['Unidade', $report['byUnit']],
            ['Vendedor', $report['bySeller']],
        ] as [$group, $items]) {
            foreach ($items as $item) {
                $rows[] = [
                    $group,
                    $item['label'],
                    $item['leads'],
                    $item['appointments'],
                    $item['visits'],
                    $item['sales'],
                    $this->percentage($item['appointments'], $item['leads']),
                    $this->percentage($item['visits'], $item['appointments']),
                    $this->percentage($item['sales'], $item['visits']),
                    $this->percentage($item['sales'], $item['leads']),
                ];
            }
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Não foi possível gerar o arquivo CSV.');
        }

        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, array_map($this->safeCsvValue(...), $row), ';', '"', '\\');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new \RuntimeException('Não foi possível ler o arquivo CSV gerado.');
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="relatorio_comercial_'.$filters['inicio'].'_'.$filters['fim'].'.csv"',
        ]);
    }

    public function pdf(Request $request, CommercialReportService $reportService): HttpResponse
    {
        [$user, $filters, $report] = $this->data($request, $reportService);
        $rows = [
            ['Consolidado', [$report['all']]],
            ['Por unidade', $report['byUnit']],
            ['Por vendedor', $report['bySeller']],
        ];
        $units = $this->unitAccess->availableUnits($user);
        $pdfFilters = $filters + [
            'unidade' => $filters['unidade_id']
                ? (string) $units->firstWhere('id', $filters['unidade_id'])?->nome
                : 'Todas as unidades',
            'vendedor' => $filters['vendedor_id']
                ? (string) Vendedor::query()->findOrFail($filters['vendedor_id'])->nome
                : 'Todos os vendedores',
        ];
        $pdf = (new CommercialReportPdf)->generate($pdfFilters, $rows);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="relatorio_comercial_'.$filters['inicio'].'_'.$filters['fim'].'.pdf"',
        ]);
    }

    /**
     * @return array{0: User, 1: array{inicio: string, fim: string, unidade_id: ?int, vendedor_id: ?int}, 2: array<string, mixed>}
     */
    private function data(Request $request, CommercialReportService $reportService): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $units = $this->unitAccess->availableUnits($user);
        $unitId = $this->unitAccess->selectedUnitId($request, $user, $units);
        $validated = $request->validate([
            'inicio' => ['nullable', 'date'],
            'fim' => ['nullable', 'date', 'after_or_equal:inicio'],
            'vendedor_id' => ['nullable', 'integer'],
        ]);
        $start = $validated['inicio'] ?? $validated['fim'] ?? now()->toDateString();
        $end = $validated['fim'] ?? $start;
        $sellerId = isset($validated['vendedor_id']) && (int) $validated['vendedor_id'] > 0
            ? (int) $validated['vendedor_id']
            : null;

        if ($sellerId !== null) {
            $sellerAllowed = Vendedor::query()
                ->whereKey($sellerId)
                ->whereIn('unidade_id', $units->modelKeys())
                ->when($unitId, fn (Builder $query) => $query->where('unidade_id', $unitId))
                ->exists();
            abort_unless($sellerAllowed, 404);
        }

        $filters = [
            'inicio' => $start,
            'fim' => $end,
            'unidade_id' => $unitId,
            'vendedor_id' => $sellerId,
        ];

        return [
            $user,
            $filters,
            $reportService->build($user, $unitId, $start, $end, $sellerId),
        ];
    }

    private function percentage(int $value, int $total): string
    {
        return $total > 0
            ? number_format(($value / $total) * 100, 1, ',', '.').'%'
            : '0,0%';
    }

    private function safeCsvValue(string|int $value): string|int
    {
        if (is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
