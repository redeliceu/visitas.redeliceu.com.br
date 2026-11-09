<?php

namespace App\Support\SellFlux;

use App\Models\SellfluxAtendente;
use App\Models\Unidade;
use App\Models\User;
use App\Models\Vendedor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Links the people answering chats on SellFlux to the unit's sellers. The link
 * always uses SellFlux's user id and needs a master's confirmation; names only
 * produce suggestions, since a repeated or changed name would link the wrong
 * person.
 */
class SellFluxAttendants
{
    private const CHATS_PER_PAGE = 30;

    private const MAX_PAGES = 100;

    /**
     * Records the attendants found on the unit's chats. New ones stay pending
     * and existing associations are left untouched.
     *
     * @return int how many attendants are new
     *
     * @throws SellFluxRequestFailed
     */
    public function discover(Unidade $unit, SellFluxClient $client): int
    {
        $new = 0;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $chats = data_get($client->get('/api/v1/contact/chats', [
                'page' => $page,
                'limit' => self::CHATS_PER_PAGE,
                'attendant' => 'user',
            ]), 'data');

            if (! is_array($chats) || $chats === []) {
                break;
            }

            foreach ($chats as $chat) {
                $id = data_get($chat, 'user_attending.id');
                $name = data_get($chat, 'user_attending.name');

                if ((is_int($id) || is_string($id)) && is_string($name) && trim($name) !== '') {
                    $new += $this->record($unit, (string) $id, $name) ? 1 : 0;
                }
            }

            if (count($chats) < self::CHATS_PER_PAGE) {
                break;
            }
        }

        return $new;
    }

    /**
     * @return Collection<int, Vendedor>
     */
    public function sellers(Unidade $unit): Collection
    {
        return Vendedor::query()
            ->where('unidade_id', $unit->id)
            ->orderByDesc('ativo')
            ->orderBy('nome')
            ->get(['id', 'unidade_id', 'user_id', 'nome', 'ativo']);
    }

    /**
     * Why an inactive seller can't be reactivated here, or null if it can.
     *
     * @param  Collection<int, Vendedor>  $sellers  all sellers of the unit
     */
    public function reactivationBlock(Vendedor $seller, Collection $sellers): ?string
    {
        // These follow their account (user status and units), and any later
        // edit of that account would undo a reactivation made here.
        if ($seller->user_id !== null) {
            return 'a conta deste vendedor está inativa ou fora da unidade; ajuste em Usuários';
        }

        // Usually what a D-024 merge leaves behind: the old record of a seller
        // who is still active, so reactivating it would create a duplicate.
        $name = self::normalize($seller->nome);
        $hasActiveTwin = $sellers->contains(
            fn (Vendedor $other): bool => $other->ativo
                && $other->id !== $seller->id
                && self::normalize($other->nome) === $name,
        );

        return $hasActiveTwin ? 'já existe um vendedor ativo com este nome' : null;
    }

    /**
     * What the screen preselects for a pending attendant. It is never saved
     * until a master confirms it.
     *
     * @param  Collection<int, Vendedor>  $sellers  all sellers of the unit
     * @return array{tipo: 'vendedor', vendedor_id: int, reativar: bool}|array{tipo: 'criar'}|array{tipo: 'bloqueado', motivo: string}|null
     */
    public function suggestion(SellfluxAtendente $attendant, Collection $sellers): ?array
    {
        $name = self::normalize($attendant->nome);
        $sameName = $sellers->filter(fn (Vendedor $seller): bool => self::normalize($seller->nome) === $name);
        $active = $sameName->where('ativo', true);

        if ($active->count() === 1) {
            return ['tipo' => 'vendedor', 'vendedor_id' => (int) $active->first()?->id, 'reativar' => false];
        }

        if ($active->isEmpty()) {
            $reactivable = $sameName->filter(fn (Vendedor $seller): bool => $this->reactivationBlock($seller, $sellers) === null);

            if ($reactivable->count() === 1) {
                return ['tipo' => 'vendedor', 'vendedor_id' => (int) $reactivable->first()?->id, 'reativar' => true];
            }

            // Creating another seller with this name would be refused.
            $blocked = $sameName->first();

            if ($blocked !== null && $reactivable->isEmpty()) {
                return ['tipo' => 'bloqueado', 'motivo' => (string) $this->reactivationBlock($blocked, $sellers)];
            }
        }

        return $sellers->where('ativo', true)->isEmpty() && $sameName->isEmpty() ? ['tipo' => 'criar'] : null;
    }

    /**
     * @return bool whether the seller had to be reactivated
     */
    public function associate(SellfluxAtendente $attendant, int $sellerId, User $user): bool
    {
        return DB::transaction(function () use ($attendant, $sellerId, $user): bool {
            $seller = Vendedor::query()
                ->lockForUpdate()
                ->where('unidade_id', $attendant->unidade_id)
                ->whereKey($sellerId)
                ->first();

            if ($seller === null) {
                throw ValidationException::withMessages([
                    'vendedor_id' => 'Selecione um vendedor desta unidade.',
                ]);
            }

            $reactivated = ! $seller->ativo;

            if ($reactivated) {
                $block = $this->reactivationBlock($seller, $this->unitSellers($attendant->unidade_id));

                if ($block !== null) {
                    throw ValidationException::withMessages([
                        'vendedor_id' => "Não é possível reativar {$seller->nome}: {$block}.",
                    ]);
                }

                $seller->forceFill(['ativo' => true])->save();
            }

            $this->saveState($attendant, SellfluxAtendente::ASSOCIADO, $seller->id, $user);

            return $reactivated;
        });
    }

    public function associateWithNewSeller(SellfluxAtendente $attendant, string $name, User $user): Vendedor
    {
        return DB::transaction(function () use ($attendant, $name, $user): Vendedor {
            $name = trim($name);
            $normalized = self::normalize($name);
            $existing = $this->unitSellers($attendant->unidade_id)
                ->first(fn (Vendedor $seller): bool => self::normalize($seller->nome) === $normalized);

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'nome' => "Já existe um vendedor chamado {$existing->nome} nesta unidade. Selecione-o na lista.",
                ]);
            }

            $seller = Vendedor::query()->create([
                'unidade_id' => $attendant->unidade_id,
                'nome' => $name,
                'ativo' => true,
            ]);

            $this->saveState($attendant, SellfluxAtendente::ASSOCIADO, $seller->id, $user);

            return $seller;
        });
    }

    public function setState(SellfluxAtendente $attendant, string $state, User $user): void
    {
        $this->saveState($attendant, $state, null, $user);
    }

    public static function normalize(string $name): string
    {
        return Str::of($name)->ascii()->lower()->squish()->toString();
    }

    /**
     * @return Collection<int, Vendedor>
     */
    private function unitSellers(int $unitId): Collection
    {
        return Vendedor::query()
            ->where('unidade_id', $unitId)
            ->lockForUpdate()
            ->get(['id', 'unidade_id', 'user_id', 'nome', 'ativo']);
    }

    private function saveState(SellfluxAtendente $attendant, string $state, ?int $sellerId, User $user): void
    {
        $attendant->forceFill([
            'situacao' => $state,
            'vendedor_id' => $sellerId,
            'alterado_por' => $user->id,
        ])->save();
    }

    /**
     * @return bool whether the attendant is new
     */
    private function record(Unidade $unit, string $sellfluxUserId, string $name): bool
    {
        $attendant = SellfluxAtendente::query()->firstOrNew([
            'unidade_id' => $unit->id,
            'sellflux_user_id' => $sellfluxUserId,
        ]);
        $isNew = ! $attendant->exists;

        $attendant->fill(['nome' => mb_substr(trim($name), 0, 255), 'visto_em' => now()])->save();

        return $isNew;
    }
}
