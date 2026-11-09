<?php

namespace App\Console\Commands;

use App\Models\Unidade;
use App\Support\SellFlux\SellFluxClient;
use App\Support\SellFlux\SellFluxRequestFailed;
use App\Support\SellFlux\SellFluxSellerSync;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled every 10 minutes (D-035, D-040). Each unit is independent: a
 * refused token or an error in one unit never stops the others. Output and
 * logs carry counts only, never phones or names.
 */
class SyncSellFluxSellers extends Command
{
    protected $signature = 'sellflux:sync-sellers
        {--unidade= : Só esta unidade}
        {--limite= : Leads por unidade nesta rodada (padrão: SELLFLUX_SYNC_LIMIT, 50)}
        {--dry-run : Consulta a SellFlux, mas não altera nada no sistema}';

    protected $description = 'Vincula a leads sem vendedor o vendedor associado ao último atendente da SellFlux';

    public function handle(SellFluxSellerSync $sync): int
    {
        $limit = (int) ($this->option('limite') ?? config('services.sellflux.sync_limit'));
        $dryRun = (bool) $this->option('dry-run');

        if ($limit < 1) {
            $this->error('O limite precisa ser maior que zero.');

            return self::FAILURE;
        }

        $units = Unidade::query()
            ->where('ativo', true)
            ->whereNotNull('sellflux_token')
            ->when($this->option('unidade') !== null, fn ($query) => $query->whereKey($this->option('unidade')))
            ->orderBy('id')
            ->get();

        if ($units->isEmpty()) {
            $this->warn('Nenhuma unidade ativa com token da SellFlux.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('Simulação: nada será alterado.');
        }

        $rows = [];
        $failed = false;

        foreach ($units as $unit) {
            [$situation, $counts] = $this->syncUnit($sync, $unit, $limit, $dryRun);
            $failed = $failed || $situation === 'erro';

            $rows[] = [
                $unit->id,
                $situation,
                $counts['consultados'] ?? 0,
                $counts['vinculados'] ?? 0,
                $counts['vinculados_por_historico'] ?? 0,
                $counts['nao_encontrados'] ?? 0,
                ($counts['sem_atendente'] ?? 0) + ($counts['atendente_sem_vendedor'] ?? 0),
                ($counts['ambiguos'] ?? 0) + ($counts['sem_telefone'] ?? 0) + ($counts['ja_vinculados'] ?? 0),
                $counts['falhas'] ?? 0,
            ];

            Log::info('sellflux:sync-sellers', [
                'unidade_id' => $unit->id,
                'situacao' => $situation,
                'dry_run' => $dryRun,
                ...$counts,
            ]);
        }

        $this->table(
            ['Unidade', 'Situação', 'Consultados', $dryRun ? 'Seriam vinculados' : 'Vinculados', 'Por atendimento anterior', 'Não encontrados', 'Sem vendedor associado', 'Outros', 'Falhas'],
            $rows,
        );

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: array<string, int>}
     */
    private function syncUnit(SellFluxSellerSync $sync, Unidade $unit, int $limit, bool $dryRun): array
    {
        // Without any associated seller nothing could be linked, so the
        // unit costs no calls (D-038).
        if ($sync->associatedSellers($unit) === []) {
            return ['sem atendente associado', []];
        }

        try {
            $token = $unit->sellfluxToken();
        } catch (DecryptException) {
            return ['erro', ['token_ilegivel' => 1]];
        }

        if (! is_string($token)) {
            return ['sem token', []];
        }

        try {
            return ['ok', $sync->syncUnit($unit, new SellFluxClient($token), $limit, $dryRun)];
        } catch (SellFluxRequestFailed $exception) {
            return ['erro', ['http_status' => (int) $exception->status]];
        }
    }
}
