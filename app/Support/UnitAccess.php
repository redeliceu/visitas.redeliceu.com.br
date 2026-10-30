<?php

namespace App\Support;

use App\Models\Unidade;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UnitAccess
{
    /**
     * @return Collection<int, Unidade>
     */
    public function availableUnits(User $user): Collection
    {
        if ($user->isMaster()) {
            return Unidade::query()->where('ativo', true)->orderBy('nome')->get();
        }

        return $user->unidades()
            ->where('unidades.ativo', true)
            ->orderBy('unidades.nome')
            ->get(['unidades.id', 'nome']);
    }

    /**
     * @param  Collection<int, Unidade>  $units
     */
    public function selectedUnitId(Request $request, User $user, Collection $units): ?int
    {
        $requested = $request->integer('unidade_id');

        if ($requested > 0) {
            $this->ensureCanAccess($user, $requested);
            abort_unless($units->contains('id', $requested), 404);

            return $requested;
        }

        return $user->isMaster() ? null : ($units->count() === 1 ? $units->first()->id : null);
    }

    /**
     * Restrict a unit-owned query to the authenticated user's active units.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scope(Builder $query, User $user, string $unitColumn = 'unidade_id'): Builder
    {
        if ($user->isMaster()) {
            return $query;
        }

        $unitIds = $user->unidades()
            ->where('unidades.ativo', true)
            ->select('unidades.id');

        return $query->whereIn($query->getModel()->qualifyColumn($unitColumn), $unitIds);
    }

    public function ensureCanAccess(User $user, Unidade|int $unidade): void
    {
        if (! $user->canAccessUnit($unidade)) {
            throw new NotFoundHttpException;
        }
    }
}
