<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Notifications\PayoutNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owner payouts are computed live from settled-but-not-yet-disbursed payments
 * (payout_id IS NULL, status = paid, on a booking that reached at least
 * paid_in_full) rather than pre-generated batches — admin triggers a payout
 * for a given owner manually, any time, from the Admin Payouts page.
 */
class PayoutService
{
    private const SETTLED_STATUSES = ['paid_in_full', 'pending_proof_review', 'active'];

    private function settledPaymentsQuery(int $ownerId)
    {
        return Payment::query()
            ->whereNull('payout_id')
            ->where('status', 'paid')
            ->whereHas(
                'booking',
                fn ($q) => $q->whereIn('status', self::SETTLED_STATUSES)
                    ->whereHas('billboard', fn ($q2) => $q2->where('owner_id', $ownerId))
            );
    }

    public function outstandingForOwner(User $owner): float
    {
        return round((float) $this->settledPaymentsQuery($owner->id)->sum('owner_payable'), 2);
    }

    /**
     * @return Collection<int, array{owner: User, amount: float}>
     */
    public function outstandingByOwner(): Collection
    {
        return User::query()
            ->where('role', 'owner')
            ->get()
            ->map(fn (User $owner) => ['owner' => $owner, 'amount' => $this->outstandingForOwner($owner)])
            ->filter(fn (array $row) => $row['amount'] > 0)
            ->values();
    }

    /**
     * @param  array{method?: string|null, reference?: string|null, note?: string|null}  $data
     */
    public function payout(User $owner, array $data): Payout
    {
        return DB::transaction(function () use ($owner, $data) {
            $amount = $this->outstandingForOwner($owner);

            $payout = Payout::query()->create([
                'owner_id' => $owner->id,
                'amount' => $amount,
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'paid_by' => auth()->id(),
                'paid_at' => now(),
            ]);

            $this->settledPaymentsQuery($owner->id)->update(['payout_id' => $payout->id]);

            $owner->notify(new PayoutNotification($payout));

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
}
