<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Unidade;
use App\Support\SellFlux\LeadPhone;
use App\Support\SellFlux\SellFluxClient;
use App\Support\SellFlux\SellFluxRequestFailed;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Read-only check of what SellFlux really returns, since most response formats
 * are not documented. It prints the structure of each response and masks the
 * values, so the output has no personal data and can be shared.
 */
class ProbeSellFlux extends Command
{
    private const LEAD_SEARCH_ROUTES = [
        'contact' => '/api/v1/contact/leads',
        'classica' => '/api/v1/lead/project',
    ];

    /** Keys whose values describe a state rather than a person. */
    private const READABLE_KEYS = '/(^|_)(status|type|kind|attendant|channel|total|page|limit|pages)$/i';

    protected $signature = 'sellflux:probe
        {unidade : ID da unidade}
        {--lead= : ID do lead no sistema (padrão: o lead sem vendedor mais recente com telefone)}';

    protected $description = 'Consulta a SellFlux somente para leitura e mostra a estrutura das respostas, sem dados pessoais';

    public function handle(): int
    {
        $unit = Unidade::query()->find($this->argument('unidade'));

        if ($unit === null) {
            $this->error('Unidade não encontrada.');

            return self::FAILURE;
        }

        try {
            $token = $unit->sellfluxToken();
        } catch (DecryptException) {
            $this->error('O token não pôde ser lido com a APP_KEY atual. Cadastre-o de novo.');

            return self::FAILURE;
        }

        if (! is_string($token)) {
            $this->error('Esta unidade não tem token da SellFlux.');

            return self::FAILURE;
        }

        $client = new SellFluxClient($token);

        $lead = $this->leadToProbe($unit);

        if ($lead === null) {
            $this->warn('Nenhum lead com telefone válido encontrado nesta unidade.');

            return self::SUCCESS;
        }

        $sellFluxLeadId = $this->searchByPhone($client, $lead);

        if ($sellFluxLeadId === null) {
            $this->warn('Nenhum resultado com o mesmo telefone; atendimento não consultado.');

            return self::SUCCESS;
        }

        $this->showStructure($client, 'Chats do lead', '/api/v1/contact/chats', ['lead_id' => $sellFluxLeadId]);
        $this->showStructure($client, 'Chats com atendente humano', '/api/v1/contact/chats', [
            'lead_id' => $sellFluxLeadId,
            'attendant' => 'user',
        ]);
        $this->showStructure($client, 'Vínculos do lead', "/api/v1/contact/leads/{$sellFluxLeadId}/links", [
            'kinds' => 'attendance,card,ticket',
        ]);

        return self::SUCCESS;
    }

    private function leadToProbe(Unidade $unit): ?Lead
    {
        $query = Lead::query()->where('unidade_id', $unit->id);
        $leadId = $this->option('lead');

        if ($leadId !== null) {
            return $query->whereKey($leadId)->first();
        }

        return $query
            ->whereNull('vendedor_id')
            ->whereNotNull('telefone')
            ->latest('id')
            ->limit(200)
            ->get(['id', 'telefone'])
            ->first(fn (Lead $lead): bool => LeadPhone::national($lead->telefone) !== null);
    }

    /**
     * Tries every phone format on both search routes, to show which ones work.
     *
     * @return int|string|null the SellFlux id of the first result with the same phone
     */
    private function searchByPhone(SellFluxClient $client, Lead $lead): int|string|null
    {
        $rows = [];
        $found = null;

        foreach (LeadPhone::searchTerms($lead->telefone) as $format => $term) {
            foreach (self::LEAD_SEARCH_ROUTES as $routeName => $path) {
                try {
                    $results = $this->items($client->get($path, ['search' => $term]));
                } catch (SellFluxRequestFailed $exception) {
                    $rows[] = [$format, $routeName, "falhou (HTTP {$exception->status})", '-'];

                    continue;
                }

                $sameLead = array_values(array_filter(
                    $results,
                    fn (mixed $item): bool => LeadPhone::matches(data_get($item, 'phone'), $lead->telefone),
                ));
                $rows[] = [$format, $routeName, count($results), count($sameLead)];

                if ($found === null && $sameLead !== []) {
                    $found = $sameLead[0];
                }
            }
        }

        $this->newLine();
        $this->info("Busca por telefone (lead #{$lead->id} do sistema)");
        $this->table(['Formato', 'Rota', 'Resultados', 'Mesmo telefone'], $rows);

        if ($found === null) {
            return null;
        }

        $this->line((string) json_encode(
            $this->describe($found),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ));

        $id = data_get($found, 'id');

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function showStructure(SellFluxClient $client, string $title, string $path, array $query = []): void
    {
        $this->newLine();
        $this->info($title);

        try {
            $this->line((string) json_encode(
                $this->describe($client->get($path, $query)),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
            ));
        } catch (SellFluxRequestFailed $exception) {
            $this->warn($exception->getMessage());
        }
    }

    /**
     * @param  array<mixed>  $response
     * @return array<mixed>
     */
    private function items(array $response): array
    {
        foreach (['data', 'leads', 'items', 'data.leads', 'data.items'] as $path) {
            $items = data_get($response, $path);

            if (is_array($items) && array_is_list($items)) {
                return $items;
            }
        }

        return array_is_list($response) ? $response : [];
    }

    private function describe(mixed $value, string $key = ''): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return $value === []
                    ? ['itens' => 0]
                    : ['itens' => count($value), 'exemplo' => $this->describe($value[0], $key)];
            }

            $described = [];

            foreach ($value as $childKey => $child) {
                $described[$childKey] = $this->describe($child, (string) $childKey);
            }

            return $described;
        }

        if ($value === null || is_bool($value)) {
            return $value;
        }

        $readable = preg_match(self::READABLE_KEYS, $key) === 1;

        if (is_string($value)) {
            return $readable && mb_strlen($value) <= 40 ? $value : 'string('.mb_strlen($value).')';
        }

        return $readable ? $value : get_debug_type($value);
    }
}
