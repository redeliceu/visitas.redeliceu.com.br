<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unidade;
use App\Models\User;
use App\Support\LegacyLeadImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LegacyLeadImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/legacy-import', [
            'units' => Unidade::query()
                ->where('ativo', true)
                ->orderBy('nome')
                ->get(['id', 'nome']),
        ]);
    }

    public function store(Request $request, LegacyLeadImportService $importer): RedirectResponse
    {
        $data = $request->validate([
            'unidade_id' => ['required', 'integer', Rule::exists('unidades', 'id')->where('ativo', true)],
            'arquivo' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $file = $data['arquivo'] ?? null;
        abort_unless($file instanceof UploadedFile, 422);

        $result = $importer->import(
            $file,
            (int) $data['unidade_id'],
        );
        $unit = Unidade::query()->findOrFail((int) $data['unidade_id']);
        $report = [
            'filename' => 'relatorio-importacao-historica-'.now()->format('Ymd-His').'.md',
            'markdown' => $this->buildReport($result, $unit->nome),
        ];

        $message = sprintf(
            'Importação concluída: %d lead(s) criada(s), %d atualizada(s), %d vendedor(es) criado(s), %d agendamento(s) criado(s)/atualizado(s) e %d visita(s) criada(s)/atualizada(s).',
            $result['created'],
            $result['updated'],
            $result['sellers_created'],
            $result['appointments_created'] + $result['appointments_updated'],
            $result['visits_created'] + $result['visits_updated'],
        );

        $skipped = $result['skipped_without_date'] + $result['skipped_without_contact'];
        if ($skipped > 0) {
            $message .= " {$skipped} linha(s) ignorada(s) por falta de Data ou telefone/e-mail.";
        }

        if ($result['unmatched_series'] !== []) {
            $examples = implode(', ', array_slice(array_keys($result['unmatched_series']), 0, 5));
            $message .= ' '.array_sum($result['unmatched_series']).' valor(es) da coluna Turma ficaram sem série correspondente (ex.: '.$examples.').';
        }

        $details = array_slice($result['failed_rows'], 0, 10);
        if (count($result['failed_rows']) > count($details)) {
            $details[] = 'Mais '.(count($result['failed_rows']) - count($details)).' linha(s) inválida(s) não exibida(s).';
        }

        if ($details !== []) {
            $message .= "\n".count($result['failed_rows']).' linha(s) ignorada(s) por erro:'."\n".implode("\n", $details);

            return back()->with([
                'import_report' => $report,
                'warning' => $message,
            ]);
        }

        return back()->with([
            'import_report' => $report,
            'success' => $message,
        ]);
    }

    /**
     * @param  array{
     *     created: int,
     *     updated: int,
     *     sellers_created: int,
     *     appointments_created: int,
     *     appointments_updated: int,
     *     visits_created: int,
     *     visits_updated: int,
     *     skipped_without_date: int,
     *     skipped_without_contact: int,
     *     failed_rows: list<string>,
     *     unmatched_series: array<string, int>
     * }  $result
     */
    private function buildReport(array $result, string $unitName): string
    {
        $lines = [
            '# Relatório de importação histórica',
            '',
            '- Data: '.now()->format('d/m/Y H:i:s'),
            '- Unidade: '.$unitName,
            '',
            '## Resultado',
            '',
            '- Leads criadas: '.$result['created'],
            '- Leads atualizadas: '.$result['updated'],
            '- Vendedores criados: '.$result['sellers_created'],
            '- Agendamentos criados: '.$result['appointments_created'],
            '- Agendamentos atualizados: '.$result['appointments_updated'],
            '- Visitas criadas: '.$result['visits_created'],
            '- Visitas atualizadas: '.$result['visits_updated'],
            '- Linhas sem Data ignoradas: '.$result['skipped_without_date'],
            '- Linhas sem telefone/e-mail ignoradas: '.$result['skipped_without_contact'],
            '',
            '## Linhas com falha',
            '',
        ];

        if ($result['failed_rows'] === []) {
            $lines[] = 'Nenhuma linha com falha.';
        } else {
            foreach ($result['failed_rows'] as $failedRow) {
                $lines[] = '- '.str_replace(["\r", "\n"], ' ', $failedRow);
            }
        }

        if ($result['unmatched_series'] !== []) {
            $lines = [
                ...$lines,
                '',
                '## Valores da coluna Turma sem correspondência',
                '',
            ];

            foreach ($result['unmatched_series'] as $seriesName => $count) {
                $lines[] = '- '.$seriesName.': '.$count.' linha(s)';
            }
        }

        return implode("\n", $lines)."\n";
    }
}
