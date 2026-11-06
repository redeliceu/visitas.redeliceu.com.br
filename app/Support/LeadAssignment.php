<?php

namespace App\Support;

use App\Models\Agendamento;
use App\Models\Lead;
use App\Models\LeadHistory;
use App\Models\User;
use App\Models\Vendedor;

/**
 * Changes the seller responsible for a lead (D-022/D-023). Every change is
 * written to the lead history, and open appointments follow the lead.
 * Visits and enrollments already done keep the original seller.
 */
class LeadAssignment
{
    public const HISTORY_FIELD = 'Vendedor';

    public const REASON_FIELD = 'Motivo da transferência';

    /**
     * Must run inside a transaction with the lead locked for update.
     */
    public function assign(Lead $lead, ?int $sellerId, User $user, ?string $reason = null): bool
    {
        $previousSellerId = $lead->vendedor_id === null ? null : (int) $lead->vendedor_id;

        if ($previousSellerId === $sellerId) {
            return false;
        }

        $lead->forceFill(['vendedor_id' => $sellerId])->save();

        $changes = [[
            'field' => self::HISTORY_FIELD,
            'old' => $this->sellerName($previousSellerId),
            'new' => $this->sellerName($sellerId),
        ]];

        if ($reason !== null && trim($reason) !== '') {
            $changes[] = [
                'field' => self::REASON_FIELD,
                'old' => null,
                'new' => trim($reason),
            ];
        }

        LeadHistory::query()->create([
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'changes' => $changes,
            'created_at' => now(),
        ]);

        $this->moveOpenAppointments($lead, $sellerId);

        return true;
    }

    public function moveOpenAppointments(Lead $lead, ?int $sellerId): void
    {
        Agendamento::query()
            ->where('lead_id', $lead->id)
            ->where('status', 'Agendado')
            ->whereNull('visita_id')
            ->update(['vendedor_id' => $sellerId]);
    }

    private function sellerName(?int $sellerId): ?string
    {
        if ($sellerId === null) {
            return null;
        }

        $name = Vendedor::query()->whereKey($sellerId)->value('nome');

        return is_string($name) ? $name : null;
    }
}
