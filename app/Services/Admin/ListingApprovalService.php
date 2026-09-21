<?php

namespace App\Services\Admin;

use App\Models\Billboard;
use App\Models\User;
use App\Notifications\NotificationService;

/**
 * Admin review of an owner-submitted board (reached once the listing fee is
 * paid and the board is 'pending_review').
 *
 *   approve()  the board goes live on the public map/list.
 *   reject()   terminal - the listing fee the owner paid is recorded as owed
 *              back, and the admin pays it out by hand through SSLCommerz from
 *              the Listing refunds tab (see ListingRefundService).
 *
 * Mirrors Admin\BookingApprovalService.
 */
class ListingApprovalService
{
    public function __construct(
        private readonly ListingRefundService $refunds,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @return array{ok: bool, status: int, message: string, billboard?: Billboard}
     */
    public function approve(Billboard $billboard, User $admin): array
    {
        if ($billboard->listing_status !== 'pending_review') {
            return ['ok' => false, 'status' => 422, 'message' => 'Only boards awaiting review can be approved.'];
        }

        $billboard->update([
            'listing_status' => 'approved',
            'reviewed_at' => now(),
            'reviewed_by' => $admin->id,
        ]);

        $billboard = $billboard->fresh(['owner']);

        $this->notifications->notifyListingApproved($billboard);

        return [
            'ok' => true,
            'status' => 200,
            'message' => 'Board approved',
            'billboard' => $billboard,
        ];
    }

    /**
     * @return array{ok: bool, status: int, message: string, billboard?: Billboard}
     */
    public function reject(Billboard $billboard, string $reason, User $admin): array
    {
        if ($billboard->listing_status !== 'pending_review') {
            return ['ok' => false, 'status' => 422, 'message' => 'Only boards awaiting review can be rejected.'];
        }

        $billboard->update([
            'listing_status' => 'rejected',
            'listing_rejection_reason' => $reason,
            'reviewed_at' => now(),
            'reviewed_by' => $admin->id,
        ]);

        // Rejecting records the debt; it does not move the money. The fee stays
        // 'paid' until the admin has actually sent it back, which is what the
        // Listing refunds tab is for.
        $refund = $this->refunds->pendingRefundFor($billboard);

        $billboard = $billboard->fresh(['owner', 'listingPayments']);

        $this->notifications->notifyListingRejected($billboard, $reason, $refund);

        return [
            'ok' => true,
            'status' => 200,
            'message' => $refund ? 'Board rejected - the listing fee is now awaiting refund' : 'Board rejected',
            'billboard' => $billboard,
        ];
    }
}
