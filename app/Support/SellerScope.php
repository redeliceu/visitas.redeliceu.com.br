<?php

namespace App\Support;

use App\Models\Lead;
use App\Models\User;
use App\Models\Vendedor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Individual seller scope (D-021/D-022): Atendimento/Vendedor accounts only
 * work on their own commercial records plus the unit queue of unassigned leads.
 * Master and gestor keep the unit scope enforced by UnitAccess.
 */
class SellerScope
{
    /** @var array<int, list<int>> */
    private array $sellerIdsCache = [];

    public function __construct(private readonly UnitAccess $unitAccess) {}

    public function applies(User $user): bool
    {
        return $user->role === User::ROLE_ATENDIMENTO;
    }

    /**
     * Seller records linked to the account (active or not, so history stays visible).
     *
     * @return list<int>
     */
    public function sellerIds(User $user): array
    {
        return $this->sellerIdsCache[$user->id] ??= array_values(Vendedor::query()
            ->where('user_id', $user->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    public function ownSellerIdForUnit(User $user, int $unitId): ?int
    {
        $id = Vendedor::query()
            ->where('user_id', $user->id)
            ->where('unidade_id', $unitId)
            ->where('ativo', true)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Leads visible to the user. Sellers see their own leads and, when
     * $includeQueue is true, the unassigned leads of their units.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function leads(Builder $query, User $user, bool $includeQueue = true): Builder
    {
        $query = $this->unitAccess->scope($query, $user);

        if (! $this->applies($user)) {
            return $query;
        }

        $sellerIds = $this->sellerIds($user);
        $column = $query->getModel()->qualifyColumn('vendedor_id');

        return $query->where(function (Builder $scoped) use ($column, $sellerIds, $includeQueue): void {
            $scoped->whereIn($column, $sellerIds);

            if ($includeQueue) {
                $scoped->orWhereNull($column);
            }
        });
    }

    /**
     * Appointments and visits visible to the user: attributed to the seller,
     * belonging to one of the seller's leads or, optionally, unassigned.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function records(Builder $query, User $user, bool $includeQueue = true): Builder
    {
        $query = $this->unitAccess->scope($query, $user);

        if (! $this->applies($user)) {
            return $query;
        }

        $sellerIds = $this->sellerIds($user);
        $model = $query->getModel();
        $sellerColumn = $model->qualifyColumn('vendedor_id');
        $leadColumn = $model->qualifyColumn('lead_id');

        return $query->where(function (Builder $scoped) use ($sellerColumn, $leadColumn, $sellerIds, $includeQueue): void {
            $scoped->whereIn($sellerColumn, $sellerIds)
                ->orWhereIn($leadColumn, Lead::query()->select('id')->whereIn('vendedor_id', $sellerIds));

            if ($includeQueue) {
                $scoped->orWhere(function (Builder $queue) use ($sellerColumn, $leadColumn): void {
                    $queue->whereNull($sellerColumn)
                        ->where(function (Builder $lead) use ($leadColumn): void {
                            $lead->whereNull($leadColumn)
                                ->orWhereIn($leadColumn, Lead::query()->select('id')->whereNull('vendedor_id'));
                        });
                });
            }
        });
    }

    public function ensureCanAccessLead(User $user, Lead $lead): void
    {
        $this->unitAccess->ensureCanAccess($user, $lead->unidade_id);

        if (! $this->applies($user)) {
            return;
        }

        if ($lead->vendedor_id !== null && ! in_array((int) $lead->vendedor_id, $this->sellerIds($user), true)) {
            throw new NotFoundHttpException;
        }
    }

    public function ensureCanAccessRecord(User $user, Model $record): void
    {
        $this->unitAccess->ensureCanAccess($user, (int) $record->getAttribute('unidade_id'));

        if (! $this->applies($user)) {
            return;
        }

        $visible = $this->records($record->newQuery(), $user)
            ->whereKey($record->getKey())
            ->exists();

        if (! $visible) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * Sellers working on an unassigned record take ownership of it (D-022).
     */
    public function resolveSellerId(User $user, int $unitId, int|string|null $requested): ?int
    {
        if ($requested !== null && $requested !== '') {
            return (int) $requested;
        }

        return $this->applies($user) ? $this->ownSellerIdForUnit($user, $unitId) : null;
    }
}
