<?php

namespace App\Services\Shared;

use App\Models\Payout;
use App\Models\User;
use App\Notifications\NotificationService;
use App\Services\Admin\AdminPanelCalculationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;


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
     * Unsorted on purpose - both PayoutController::index() methods (Admin and
     * Owner) decide the display order themselves, the same split as
     * outstandingByOwner()/AdminPanelCalculationService.
     *
     * @return Collection<int, Payout>
     */
    public function history(?User $owner = null): Collection
    {
        return Payout::query()
            ->with('owner')
            ->when($owner, fn ($q) => $q->where('owner_id', $owner->id))
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
