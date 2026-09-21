<?php

namespace App\Services\Shared;

use App\Models\Payout;
use App\Models\User;
use App\Notifications\NotificationService;
use App\Services\Admin\AdminPanelCalculationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owner payouts are computed live from settled-but-not-yet-disbursed payments
 * (payout_id IS NULL, status = paid, on a booking that reached at least
 * paid_in_full) rather than pre-generated batches - admin triggers a payout
 * for a given owner manually, any time, from the Admin Payouts page.
 *
 * Being paid in full is not enough to be payable. The owner still owes the
 * platform proof that the campaign actually went up on the board, and admin
 * still has to accept that proof - until then the money is collected but not
 * earned, and paying it out would mean paying for a posting nobody has
 * confirmed. So the booking's own revenue can show on the owner dashboard
 * while its payout balance stays at zero, and the balance only moves when
 * admin verifies the proof of posting.
 *
 * The actual "how much is payable" calculation lives in
 * Services\Admin\AdminPanelCalculationService - this class only orchestrates
 * turning that figure into a recorded Payout (and notifying the owner).
 */
class PayoutService
{
    public function __construct(
        private readonly AdminPanelCalculationService $calculations,
        private readonly NotificationService $notifications,
    ) {}

    public function outstandingForOwner(User $owner): float
    {
        return $this->calculations->payableToOwner($owner);
    }

    /**
     * @return Collection<int, array{owner: User, amount: float}>
     */
    public function outstandingByOwner(): Collection
    {
        return $this->calculations->payableToAllOwners();
    }

    /**
     * @param  array{method?: string|null, reference?: string|null, note?: string|null}  $data
     */
    public function payout(User $owner, array $data): Payout
    {
        return DB::transaction(function () use ($owner, $data) {
            $amount = $this->calculations->payableToOwner($owner);

            $payout = Payout::query()->create([
                'owner_id' => $owner->id,
                'amount' => $amount,
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                // Freeze where the money was sent. Owners can edit their payout
                // details any time, so the receipt must render from this snapshot,
                // never the live users row. Null when the owner never filled any
                // of it in, so the receipt shows a "not recorded" notice rather
                // than a grid of blanks.
                'payout_details' => $this->payoutDetailsSnapshot($owner),
                'paid_by' => auth()->id(),
                'paid_at' => now(),
            ]);

            $this->calculations->settledPaymentsQueryForOwner($owner->id)->update(['payout_id' => $payout->id]);

            $this->notifications->notifyPayoutSent($payout, $owner);

            return $payout->fresh();
        });
    }

    /**
     * @return Collection<int, Payout>
     */
    public function history(?User $owner = null): Collection
    {
        return Payout::query()
            ->with('owner')
            ->when($owner, fn ($q) => $q->where('owner_id', $owner->id))
            ->latest()
            ->get();
    }

    /**
     * The owner's payout account, frozen for the receipt - or null when they
     * never entered any of it.
     *
     * @return array<string, string|null>|null
     */
    private function payoutDetailsSnapshot(User $owner): ?array
    {
        $details = $owner->only([
            'payout_method', 'payout_account_name', 'payout_account_number',
            'payout_bank_name', 'payout_branch',
        ]);

        return collect($details)->contains(fn ($v) => filled($v)) ? $details : null;
    }
}
