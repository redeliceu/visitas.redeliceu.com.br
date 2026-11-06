<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\LeadHistory;
use App\Models\User;
use App\Support\LeadAssignment;
use App\Support\SellerScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Follow-up notes on leads (D-028, D-029).
 */
class LeadFollowUpController extends Controller
{
    public function __construct(
        private readonly SellerScope $sellerScope,
        private readonly LeadAssignment $assignment,
    ) {}

    public function store(Request $request, Lead $lead): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->sellerScope->ensureCanAccessLead($user, $lead);
        $data = $this->validated($request);

        DB::transaction(function () use ($lead, $data, $user): void {
            $lockedLead = Lead::query()->lockForUpdate()->findOrFail($lead->id);

            // A seller writing on a queue lead takes ownership of it (D-022).
            if ($lockedLead->vendedor_id === null) {
                $ownSellerId = $this->sellerScope->resolveSellerId($user, $lockedLead->unidade_id, null);

                if ($ownSellerId !== null) {
                    $this->assignment->assign($lockedLead, $ownSellerId, $user);
                }
            }

            LeadFollowUp::query()->create([
                'lead_id' => $lockedLead->id,
                'vendedor_id' => $lockedLead->vendedor_id,
                'user_id' => $user->id,
                'texto' => $data['texto'],
                'proximo_retorno' => $data['proximo_retorno'] ?? null,
            ]);

            $this->syncLeadReturn($lockedLead, $data['proximo_retorno'] ?? null, $user);
        });

        return back()->with('success', 'Follow-up registrado.');
    }

    public function update(Request $request, LeadFollowUp $followUp): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->authorizeNote($user, $followUp);
        $data = $this->validated($request);

        DB::transaction(function () use ($followUp, $data, $user): void {
            $lockedLead = Lead::query()->lockForUpdate()->findOrFail($followUp->lead_id);
            $followUp->update([
                'texto' => $data['texto'],
                'proximo_retorno' => $data['proximo_retorno'] ?? null,
            ]);

            $this->syncLeadReturn($lockedLead, $data['proximo_retorno'] ?? null, $user);
        });

        return back()->with('success', 'Follow-up atualizado.');
    }

    public function destroy(Request $request, LeadFollowUp $followUp): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->authorizeNote($user, $followUp);
        $followUp->delete();

        return back()->with('success', 'Follow-up excluído.');
    }

    private function authorizeNote(User $user, LeadFollowUp $followUp): void
    {
        $lead = Lead::query()->findOrFail($followUp->lead_id);
        $this->sellerScope->ensureCanAccessLead($user, $lead);
        abort_unless($followUp->canBeManagedBy($user), 403);
    }

    /**
     * @return array{texto: string, proximo_retorno?: string|null}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'texto' => ['required', 'string', 'max:2000'],
            'proximo_retorno' => ['nullable', 'date'],
        ]);
    }

    /**
     * A note with a next return date updates the lead's return date and
     * records the change in the lead history (D-028).
     */
    private function syncLeadReturn(Lead $lead, ?string $nextReturn, User $user): void
    {
        if ($nextReturn === null || $nextReturn === '') {
            return;
        }

        $old = $lead->getRawOriginal('retorno_data');
        $lead->retorno_data = $nextReturn;

        if (! $lead->isDirty('retorno_data')) {
            return;
        }

        $lead->save();

        LeadHistory::query()->create([
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'changes' => [[
                'field' => 'Data de retorno',
                'old' => is_string($old) && $old !== '' ? $old : null,
                'new' => (string) $lead->getRawOriginal('retorno_data'),
            ]],
            'created_at' => now(),
        ]);
    }
}
