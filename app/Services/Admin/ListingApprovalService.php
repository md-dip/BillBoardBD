<?php

namespace App\Services\Admin;

use App\Models\Billboard;
use App\Models\User;
use App\Notifications\BillboardListingNotification;

/**
 * Admin review of an owner-submitted board (reached once the listing fee is
 * paid and the board is 'pending_review').
 *
 *   approve()  the board goes live on the public map/list.
 *   reject()   terminal - the listing fee the owner paid is auto refunded to
 *              their source account (see ListingRefundService).
 *
 * Mirrors Admin\BookingApprovalService.
 */
class ListingApprovalService
{
    public function __construct(private readonly ListingRefundService $refunds) {}

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

        $billboard->owner?->notify(new BillboardListingNotification(
            $billboard,
            'Board approved',
            "Your board \"{$billboard->title}\" has been approved and is now live on the map.",
        ));

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

        $refund = $this->refunds->refundListingFee($billboard);

        $billboard = $billboard->fresh(['owner', 'listingPayments']);

        $body = "Your board \"{$billboard->title}\" was rejected. Reason: {$reason}";
        if ($refund) {
            $amount = '৳'.number_format((float) $refund->amount);
            $body .= " Your listing fee of {$amount} has been refunded (ref {$refund->transaction_ref}).";
        }

        $billboard->owner?->notify(new BillboardListingNotification(
            $billboard,
            $refund ? 'Board rejected - listing fee refunded' : 'Board rejected',
            $body,
        ));

        return [
            'ok' => true,
            'status' => 200,
            'message' => $refund ? 'Board rejected and listing fee refunded' : 'Board rejected',
            'billboard' => $billboard,
        ];
    }
}
