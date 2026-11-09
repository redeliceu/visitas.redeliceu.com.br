<?php

namespace App\Support;

use App\Models\Agendamento;
use App\Models\Lead;
use App\Models\MetaVendedor;
use App\Models\SellfluxAtendente;
use App\Models\User;
use App\Models\Vendedor;
use App\Models\Visita;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Links an Atendimento/Vendedor account to an existing seller record that has
 * no account yet, such as one created by the historical import (D-024).
 * The account's automatic seller in the same unit is merged into the linked
 * one and deactivated; nothing is deleted.
 */
class SellerLinkService
{
    /**
     * @return array{merged_from: int|null, leads: int, appointments: int, visits: int, goals: int}
     */
    public function link(User $user, Vendedor $target): array
    {
        if ($user->role !== User::ROLE_ATENDIMENTO) {
            throw ValidationException::withMessages([
                'vendedor_id' => 'Somente contas Atendimento / Vendedor podem ser vinculadas a um vendedor.',
            ]);
        }

        return DB::transaction(function () use ($user, $target): array {
            $target = Vendedor::query()->lockForUpdate()->findOrFail($target->id);

            if ($target->user_id !== null) {
                throw ValidationException::withMessages([
                    'vendedor_id' => 'Este vendedor já está vinculado a uma conta.',
                ]);
            }

            if (! $user->unidades()->whereKey($target->unidade_id)->exists()) {
                throw ValidationException::withMessages([
                    'vendedor_id' => 'A conta não está atribuída à unidade deste vendedor.',
                ]);
            }

            $current = Vendedor::query()
                ->lockForUpdate()
                ->where('user_id', $user->id)
                ->where('unidade_id', $target->unidade_id)
                ->first();
            $result = ['merged_from' => null, 'leads' => 0, 'appointments' => 0, 'visits' => 0, 'goals' => 0];

            if ($current !== null) {
                $result['merged_from'] = $current->id;
                $result['leads'] = Lead::query()->where('vendedor_id', $current->id)->update(['vendedor_id' => $target->id]);
                $result['appointments'] = Agendamento::query()->where('vendedor_id', $current->id)->update(['vendedor_id' => $target->id]);
                $result['visits'] = Visita::query()->where('vendedor_id', $current->id)->update(['vendedor_id' => $target->id]);
                $targetCampaignIds = MetaVendedor::query()->where('vendedor_id', $target->id)->pluck('campanha_id');
                // Goals for campaigns the target already has stay on the deactivated record.
                $result['goals'] = MetaVendedor::query()
                    ->where('vendedor_id', $current->id)
                    ->whereNotIn('campanha_id', $targetCampaignIds)
                    ->update(['vendedor_id' => $target->id]);

                // The attendant is the same person, so the association follows the merge.
                SellfluxAtendente::query()->where('vendedor_id', $current->id)->update(['vendedor_id' => $target->id]);

                // Frees the (user_id, unidade_id) unique key for the linked record.
                $current->forceFill(['user_id' => null, 'ativo' => false])->save();
            }

            $target->forceFill([
                'user_id' => $user->id,
                'nome' => $user->name,
                'ativo' => $user->ativo,
            ])->save();

            return $result;
        });
    }
}
