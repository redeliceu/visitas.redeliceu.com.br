<?php

namespace App\Support;

use App\Models\Agendamento;
use App\Models\Campanha;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\LeadStatus;
use App\Models\User;
use App\Models\Visita;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data for the individual seller panel (D-026): agenda, unit queue,
 * pending follow-ups and campaign goal/performance.
 */
class SellerPanelService
{
    private const FOLLOW_UP_STATUSES = ['Atendendo', 'Em Negociação', 'Agendado'];

    private const AGENDA_DAYS = 7;

    private const LIST_LIMIT = 30;

    /** Follow-ups without activity for longer than this are flagged as idle. */
    private const IDLE_DAYS = 7;

    /** Waiting-period tabs of the pending follow-ups (D-030), besides "Todos". */
    private const WAIT_BUCKETS = ['vencidos', 'hoje', 'proximos', 'parados'];

    public function __construct(
        private readonly UnitAccess $unitAccess,
        private readonly SellerScope $sellerScope,
        private readonly CommercialReportService $reportService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, ?int $unitId): array
    {
        $today = CarbonImmutable::today();
        $sellerIds = $this->sellerScope->sellerIds($user);
        $unitIds = $this->unitAccess->availableUnits($user)
            ->when($unitId, fn ($units) => $units->where('id', $unitId))
            ->map(fn ($unit): int => (int) $unit->getKey())
            ->all();
        $unitIds = array_values($unitIds);

        return [
            'agenda' => $this->agenda($sellerIds, $unitIds, $today),
            'queue' => $this->queue($user, $unitIds),
            'followUps' => $this->followUps($user, $sellerIds, $unitIds, $today),
            'goals' => $this->goals($user, $sellerIds, $unitIds, $today),
            'performance' => $this->reportService->build($user, $unitId, '2000-01-01', $today->toDateString())['all'],
        ];
    }

    /**
     * @param  list<int>  $sellerIds
     * @param  list<int>  $unitIds
     * @return array{appointments: array<int, array<string, mixed>>, visits: array<int, array<string, mixed>>}
     */
    private function agenda(array $sellerIds, array $unitIds, CarbonImmutable $today): array
    {
        $until = $today->addDays(self::AGENDA_DAYS - 1)->toDateString();

        $appointments = Agendamento::query()
            ->whereIn('unidade_id', $unitIds)
            ->whereIn('vendedor_id', $sellerIds)
            ->where('status', 'Agendado')
            ->whereDate('data', '>=', $today->toDateString())
            ->whereDate('data', '<=', $until)
            ->with('unidade:id,nome')
            ->orderBy('data')
            ->orderBy('horario')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'unidade_id', 'lead_id', 'data', 'horario', 'responsavel', 'telefone', 'aluno'])
            ->map(fn (Agendamento $appointment): array => [
                'id' => $appointment->id,
                'lead_id' => $appointment->lead_id,
                'data' => $this->date($appointment->getAttribute('data')),
                'horario' => $this->time($appointment->getAttribute('horario')),
                'responsavel' => $appointment->responsavel,
                'telefone' => $appointment->telefone,
                'aluno' => $appointment->aluno,
                'unidade' => $appointment->unidade?->nome,
            ])
            ->all();

        $visits = Visita::query()
            ->whereIn('unidade_id', $unitIds)
            ->where(fn (Builder $query) => $query
                ->whereIn('vendedor_id', $sellerIds)
                ->orWhere(fn (Builder $lead) => $lead
                    ->whereNull('vendedor_id')
                    ->whereIn('lead_id', Lead::query()->select('id')->whereIn('vendedor_id', $sellerIds))))
            ->whereIn('status', ['Aguardando', 'Em Atendimento'])
            ->whereDate('data', $today->toDateString())
            ->with('unidade:id,nome')
            ->orderBy('horario')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'unidade_id', 'data', 'horario', 'responsavel', 'telefone', 'aluno', 'status'])
            ->map(fn (Visita $visit): array => [
                'id' => $visit->id,
                'horario' => $this->time($visit->getAttribute('horario')),
                'responsavel' => $visit->responsavel,
                'telefone' => $visit->telefone,
                'aluno' => $visit->aluno,
                'status' => $visit->status,
                'unidade' => $visit->unidade?->nome,
            ])
            ->all();

        return ['appointments' => $appointments, 'visits' => $visits];
    }

    /**
     * @param  list<int>  $unitIds
     * @return array{total: int, leads: array<int, array<string, mixed>>}
     */
    private function queue(User $user, array $unitIds): array
    {
        $query = $this->unitAccess->scope(Lead::query(), $user)
            ->whereIn('unidade_id', $unitIds)
            ->whereNull('vendedor_id');

        return [
            'total' => (clone $query)->count(),
            'leads' => $query
                ->with(['unidade:id,nome', 'status:id,nome'])
                ->orderByDesc('data')
                ->orderByDesc('id')
                ->limit(self::LIST_LIMIT)
                ->get(['id', 'unidade_id', 'status_id', 'data', 'responsavel', 'aluno', 'telefone', 'canal_contato', 'created_at'])
                ->map(fn (Lead $lead): array => [
                    'id' => $lead->id,
                    'data' => $this->date($lead->getAttribute('data')),
                    'created_at' => CarbonImmutable::parse($lead->getAttribute('created_at'))->toIso8601String(),
                    'responsavel' => $lead->responsavel,
                    'aluno' => $lead->aluno,
                    'telefone' => $lead->telefone,
                    'canal' => $lead->canal_contato,
                    'status' => $lead->status?->nome,
                    'unidade' => $lead->unidade?->nome,
                ])
                ->all(),
        ];
    }

    /**
     * Own leads in follow-up statuses: overdue returns first, then the ones
     * idle for the longest time.
     *
     * @param  list<int>  $sellerIds
     * @param  list<int>  $unitIds
     * @return array{total: int, overdue: int, idle: int, counts: array<string, int>, leads: array<int, array<string, mixed>>}
     */
    private function followUps(User $user, array $sellerIds, array $unitIds, CarbonImmutable $today): array
    {
        $statusIds = LeadStatus::query()
            ->whereIn('nome', self::FOLLOW_UP_STATUSES)
            ->pluck('id');

        $leads = Lead::query()
            ->whereIn('unidade_id', $unitIds)
            ->whereIn('vendedor_id', $sellerIds)
            ->whereIn('status_id', $statusIds)
            ->with([
                'unidade:id,nome',
                'status:id,nome',
                'serie:id,nome',
                'followUps.user:id,name',
                'followUps.vendedor:id,nome',
            ])
            ->get(['id', 'unidade_id', 'status_id', 'serie_id', 'data', 'responsavel', 'aluno', 'telefone', 'observacoes', 'retorno_data', 'updated_at'])
            ->map(function (Lead $lead) use ($today, $user): array {
                // Movement = latest of the lead's own change and its last follow-up (D-030).
                $lastActivity = $lead->followUps
                    ->map(fn (LeadFollowUp $note) => CarbonImmutable::parse($note->getAttribute('updated_at')))
                    ->push(CarbonImmutable::parse($lead->getAttribute('updated_at')))
                    ->max();
                $return = $this->date($lead->getAttribute('retorno_data'));
                $overdueDays = $return !== null && $return < $today->toDateString()
                    ? (int) CarbonImmutable::parse($return)->diffInDays($today)
                    : 0;
                $idleDays = (int) $lastActivity->startOfDay()->diffInDays($today);

                return [
                    'id' => $lead->id,
                    'responsavel' => $lead->responsavel,
                    'aluno' => $lead->aluno,
                    'serie' => $lead->serie?->nome,
                    'telefone' => $lead->telefone,
                    'status' => $lead->status?->nome,
                    'unidade' => $lead->unidade?->nome,
                    'retorno_data' => $return,
                    'overdue' => $overdueDays > 0,
                    'overdue_days' => $overdueDays,
                    'idle_days' => $idleDays,
                    'bucket' => $this->waitBucket($return, $idleDays, $today),
                    ...$this->notes($lead, $user),
                ];
            })
            ->sortBy([
                fn (array $a, array $b): int => $b['overdue_days'] <=> $a['overdue_days'],
                fn (array $a, array $b): int => $b['idle_days'] <=> $a['idle_days'],
            ])
            ->values();

        $counts = ['todos' => $leads->count()];
        // The overall top of the list plus the top of each tab, so every tab
        // can show its first items while counts cover the whole set.
        $visible = $leads->take(self::LIST_LIMIT);

        foreach (self::WAIT_BUCKETS as $bucket) {
            $inBucket = $leads->where('bucket', $bucket);
            $counts[$bucket] = $inBucket->count();
            $visible = $visible->concat($inBucket->take(self::LIST_LIMIT));
        }

        return [
            'total' => $leads->count(),
            'overdue' => $counts['vencidos'],
            'idle' => $counts['parados'],
            'counts' => $counts,
            'leads' => $visible->unique('id')->values()->all(),
        ];
    }

    /**
     * Waiting-period tab of a pending follow-up (D-030); null = only in "Todos".
     */
    private function waitBucket(?string $return, int $idleDays, CarbonImmutable $today): ?string
    {
        if ($return !== null) {
            return match (true) {
                $return < $today->toDateString() => 'vencidos',
                $return === $today->toDateString() => 'hoje',
                $return <= $today->addDays(self::IDLE_DAYS)->toDateString() => 'proximos',
                default => null,
            };
        }

        return $idleDays > self::IDLE_DAYS ? 'parados' : null;
    }

    /**
     * Follow-up notes of a lead, newest first. The lead's main observation
     * counts as the first (oldest) follow-up when filled (D-029).
     *
     * @return array{follow_ups_count: int, initial_note: array{texto: string, data: string|null}|null, follow_ups: array<int, array<string, mixed>>}
     */
    private function notes(Lead $lead, User $user): array
    {
        $observation = trim((string) $lead->getAttribute('observacoes'));
        $notes = $lead->followUps
            ->map(fn (LeadFollowUp $note): array => [
                'id' => $note->id,
                'texto' => $note->texto,
                'proximo_retorno' => $this->date($note->getAttribute('proximo_retorno')),
                'created_at' => CarbonImmutable::parse($note->getAttribute('created_at'))->toIso8601String(),
                'edited' => $note->getAttribute('updated_at') != $note->getAttribute('created_at'),
                'autor' => $note->user?->name,
                'vendedor' => $note->vendedor?->nome,
                'can_manage' => $note->canBeManagedBy($user),
            ])
            ->values()
            ->all();

        return [
            'follow_ups_count' => count($notes) + ($observation !== '' ? 1 : 0),
            'initial_note' => $observation !== ''
                ? ['texto' => $observation, 'data' => $this->date($lead->getAttribute('data'))]
                : null,
            'follow_ups' => $notes,
        ];
    }

    private function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    private function time(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr($value, 0, 5) : null;
    }

    /**
     * @param  list<int>  $sellerIds
     * @param  list<int>  $unitIds
     * @return array<int, array<string, mixed>>
     */
    private function goals(User $user, array $sellerIds, array $unitIds, CarbonImmutable $today): array
    {
        return Campanha::query()
            ->whereIn('unidade_id', $unitIds)
            ->with(['unidade:id,nome', 'metasVendedores'])
            ->orderBy('inicio')
            ->get()
            ->filter(fn (Campanha $campaign): bool => $campaign->inicio !== null && $campaign->fim !== null)
            ->map(function (Campanha $campaign) use ($user, $sellerIds, $today): array {
                $start = CarbonImmutable::parse($campaign->inicio->toDateString());
                $end = CarbonImmutable::parse($campaign->fim->toDateString());
                $countUntil = $end->lessThan($today) ? $end : $today;
                $sales = $start->lessThanOrEqualTo($countUntil)
                    ? (int) $this->reportService->build(
                        $user,
                        $campaign->unidade_id,
                        $start->toDateString(),
                        $countUntil->toDateString(),
                    )['all']['sales']
                    : 0;
                $goal = (int) $campaign->metasVendedores->whereIn('vendedor_id', $sellerIds)->sum('meta');
                $expected = null;

                if ($goal > 0 && $today->greaterThanOrEqualTo($start)) {
                    $periodDays = $start->diffInDays($end) + 1;
                    $elapsed = min($periodDays, $start->diffInDays($today) + 1);
                    $expected = (int) ceil($goal * $elapsed / $periodDays);
                }

                return [
                    'id' => $campaign->id,
                    'unidade' => $campaign->unidade?->nome,
                    'inicio' => $start->toDateString(),
                    'fim' => $end->toDateString(),
                    'active' => $today->betweenIncluded($start, $end),
                    'goal' => $goal > 0 ? $goal : null,
                    'sales' => $sales,
                    'expected_sales' => $expected,
                ];
            })
            ->values()
            ->all();
    }
}
