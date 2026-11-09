<?php

namespace App\Support\SellFlux;

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\SellfluxAtendente;
use App\Models\Unidade;
use App\Support\LeadAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gives a seller to leads that have none, using the last person who answered
 * the lead on SellFlux (D-035, D-037, D-039, D-040). Leads are looked up one
 * by one through their phone, at most a fixed number per unit in each round,
 * rotating through the queue with a cursor kept in the cache.
 */
class SellFluxSellerSync
{
    public const REASON = 'Vínculo automático pela SellFlux';

    /** Leads in these situations are never looked up (D-039). */
    public const EXCLUDED_STATUSES = ['Matriculado', 'Aluno', 'Rematrícula', 'Perdido', 'Sem Interesse'];

    private const CHATS_PER_LEAD = 30;

    public function __construct(
        private readonly SellFluxAttendants $attendants,
        private readonly LeadAssignment $assignment,
    ) {}

    /**
     * @return array<string, int> counts only, never personal data
     *
     * @throws SellFluxRequestFailed when SellFlux refuses the token
     */
    public function syncUnit(Unidade $unit, SellFluxClient $client, int $limit, bool $dryRun): array
    {
        $counts = array_fill_keys([
            'consultados', 'vinculados', 'sem_telefone', 'nao_encontrados', 'ambiguos',
            'sem_atendente', 'atendente_sem_vendedor', 'ja_vinculados', 'falhas', 'atendentes_novos',
        ], 0);

        $sellers = $this->associatedSellers($unit);

        foreach ($this->nextLeads($unit, $limit, $dryRun) as $lead) {
            $counts['consultados']++;

            try {
                $outcome = $this->syncLead($unit, $client, $lead, $sellers, $dryRun, $counts);
            } catch (SellFluxRequestFailed $exception) {
                if ($exception->isAuthenticationFailure()) {
                    throw $exception;
                }

                $outcome = 'falhas';
            }

            $counts[$outcome]++;
        }

        return $counts;
    }

    /**
     * Active sellers linked to an attendant of this unit, keyed by SellFlux id.
     *
     * @return array<string, int>
     */
    public function associatedSellers(Unidade $unit): array
    {
        return SellfluxAtendente::query()
            ->where('sellflux_atendentes.unidade_id', $unit->id)
            ->where('situacao', SellfluxAtendente::ASSOCIADO)
            ->join('vendedores', 'vendedores.id', '=', 'sellflux_atendentes.vendedor_id')
            ->where('vendedores.ativo', true)
            ->where('vendedores.unidade_id', $unit->id)
            ->pluck('vendedores.id', 'sellflux_atendentes.sellflux_user_id')
            ->mapWithKeys(fn (mixed $sellerId, mixed $userId): array => [(string) $userId => (int) $sellerId])
            ->all();
    }

    /**
     * The next leads of the unit's queue, after the cursor of the last round.
     * When the end of the queue is reached it starts over from the beginning.
     *
     * @return Collection<int, Lead>
     */
    public function nextLeads(Unidade $unit, int $limit, bool $dryRun): Collection
    {
        $cursorKey = "sellflux-sync:cursor:{$unit->id}";
        $cursor = (int) Cache::get($cursorKey, 0);
        $excluded = LeadStatus::query()->whereIn('nome', self::EXCLUDED_STATUSES)->pluck('id');

        $query = fn () => Lead::query()
            ->where('unidade_id', $unit->id)
            ->whereNull('vendedor_id')
            ->where(fn ($query) => $query->whereNull('status_id')->orWhereNotIn('status_id', $excluded))
            ->where(fn ($query) => $query->whereNotNull('telefone')->orWhereNotNull('telefone_secundario'))
            ->orderBy('id')
            ->select(['id', 'unidade_id', 'vendedor_id', 'telefone', 'telefone_secundario']);

        $leads = $query()->where('id', '>', $cursor)->limit($limit)->get();

        if ($leads->count() < $limit && $cursor > 0) {
            $leads = $leads->concat(
                $query()->where('id', '<=', $cursor)->limit($limit - $leads->count())->get(),
            );
        }

        if (! $dryRun) {
            Cache::forever($cursorKey, $leads->count() < $limit ? 0 : (int) $leads->last()?->id);
        }

        return $leads->values();
    }

    /**
     * @param  array<string, int>  $sellers
     * @param  array<string, int>  $counts
     * @return string the counter to increase
     *
     * @throws SellFluxRequestFailed
     */
    private function syncLead(Unidade $unit, SellFluxClient $client, Lead $lead, array $sellers, bool $dryRun, array &$counts): string
    {
        $phones = array_filter(
            [$lead->telefone, $lead->telefone_secundario],
            fn (mixed $phone): bool => LeadPhone::national($phone) !== null,
        );

        if ($phones === []) {
            return 'sem_telefone';
        }

        $sellfluxLeadIds = [];

        foreach ($phones as $phone) {
            $sellfluxLeadIds = $this->findSellFluxLeads($client, $phone);

            if ($sellfluxLeadIds !== []) {
                break;
            }
        }

        if ($sellfluxLeadIds === []) {
            return 'nao_encontrados';
        }

        // Two leads with the same phone on SellFlux could be different people.
        if (count($sellfluxLeadIds) > 1) {
            return 'ambiguos';
        }

        $attendant = $this->lastAttendant($client, $sellfluxLeadIds[0]);

        if ($attendant === null) {
            return 'sem_atendente';
        }

        if (! $dryRun && $this->attendants->remember($unit, $attendant['id'], $attendant['name'])) {
            $counts['atendentes_novos']++;
        }

        $sellerId = $sellers[$attendant['id']] ?? null;

        if ($sellerId === null) {
            return 'atendente_sem_vendedor';
        }

        if ($dryRun) {
            return 'vinculados';
        }

        return $this->assign($lead->id, $unit->id, $sellerId) ? 'vinculados' : 'ja_vinculados';
    }

    /**
     * SellFlux has no exact phone filter, so the text search results are
     * checked by area code plus the last eight digits.
     *
     * @return list<string>
     *
     * @throws SellFluxRequestFailed
     */
    private function findSellFluxLeads(SellFluxClient $client, string $phone): array
    {
        $national = (string) LeadPhone::national($phone);
        $terms = array_unique([$national, ...array_filter(
            LeadPhone::searchTerms($phone),
            fn (string $term, string $label): bool => $label === 'ddd_numero_outro_nono_digito',
            ARRAY_FILTER_USE_BOTH,
        )]);

        foreach ($terms as $term) {
            $ids = array_values(collect($this->items($client->get('/api/v1/contact/leads', ['search' => $term])))
                ->filter(fn (mixed $item): bool => LeadPhone::matches(data_get($item, 'phone'), $phone))
                ->map(fn (mixed $item): mixed => data_get($item, 'id'))
                ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && $id !== ''))
                ->map(fn (int|string $id): string => (string) $id)
                ->unique()
                ->all());

            if ($ids !== []) {
                return $ids;
            }
        }

        return [];
    }

    /**
     * The person on the most recent chat with a human attendant, open or
     * closed (D-039).
     *
     * @return array{id: string, name: mixed}|null
     *
     * @throws SellFluxRequestFailed
     */
    private function lastAttendant(SellFluxClient $client, string $sellfluxLeadId): ?array
    {
        $chats = $this->items($client->get('/api/v1/contact/chats', [
            'lead_id' => $sellfluxLeadId,
            'attendant' => 'user',
            'limit' => self::CHATS_PER_LEAD,
        ]));

        $latest = null;
        $latestAt = null;

        foreach ($chats as $chat) {
            $id = data_get($chat, 'user_attending.id');

            if ((string) data_get($chat, 'lead_id') !== $sellfluxLeadId || (! is_int($id) && ! is_string($id)) || $id === '') {
                continue;
            }

            $createdAt = $this->date(data_get($chat, 'created_at'));

            if ($latest === null || ($createdAt !== null && ($latestAt === null || $createdAt->greaterThan($latestAt)))) {
                $latest = ['id' => (string) $id, 'name' => data_get($chat, 'user_attending.name')];
                $latestAt = $createdAt;
            }
        }

        return $latest;
    }

    /**
     * Locks the lead and only links it if it still has no seller, so a seller
     * who took it meanwhile is never replaced (D-040).
     */
    private function assign(int $leadId, int $unitId, int $sellerId): bool
    {
        return DB::transaction(function () use ($leadId, $unitId, $sellerId): bool {
            $lead = Lead::query()->lockForUpdate()->whereKey($leadId)->first();

            if ($lead === null || $lead->vendedor_id !== null || (int) $lead->unidade_id !== $unitId) {
                return false;
            }

            return $this->assignment->assign($lead, $sellerId, null, self::REASON);
        });
    }

    /**
     * @param  array<mixed>  $response
     * @return array<mixed>
     */
    private function items(array $response): array
    {
        $items = data_get($response, 'data');

        return is_array($items) && array_is_list($items) ? $items : [];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
