<?php

namespace Rag\Ingestion\DocumentBuilders;

use App\Models\Booking;
use App\Services\Client\AssistantTools as ClientAssistantTools;
use Rag\Ingestion\DocumentDraft;

/**
 * Every booking, written twice - once for the advertiser who made it, once for
 * the owner whose board it is on.
 *
 * Two documents rather than one shared document, because a document belongs to
 * exactly one account and these two people are not entitled to the same
 * sentence. The client's copy carries what they still owe; the owner's carries
 * who is advertising and what it is worth to them. Neither can retrieve the
 * other's, since retrieval filters on visible_to_user_id before it ranks
 * anything.
 */
class BookingDocumentBuilder implements DocumentBuilder
{
    public function sourceType(): string
    {
        return 'booking';
    }

    public function build(): iterable
    {
        $bookings = Booking::query()
            ->with(['billboard:id,title,address,owner_id', 'user:id,name', 'payments'])
            ->cursor();

        foreach ($bookings as $booking) {
            yield from $this->forBooking($booking);
        }
    }

    /**
     * @return array<int, DocumentDraft>
     */
    public function forBooking(Booking $booking): array
    {
        $drafts = [
            new DocumentDraft(
                sourceType: $this->sourceType(),
                sourceId: $booking->id.':client',
                title: sprintf('Your booking #%d - %s', $booking->id, $booking->billboard?->title ?? 'billboard'),
                content: $this->clientView($booking),
                visibleToUserId: $booking->user_id,
                visibleToRole: 'client',
            ),
        ];

        if ($booking->billboard?->owner_id) {
            $drafts[] = new DocumentDraft(
                sourceType: $this->sourceType(),
                sourceId: $booking->id.':owner',
                title: sprintf('Booking request #%d on %s', $booking->id, $booking->billboard->title),
                content: $this->ownerView($booking),
                visibleToUserId: $booking->billboard->owner_id,
                visibleToRole: 'owner',
            );
        }

        return $drafts;
    }

    private function clientView(Booking $booking): string
    {
        $paid = $this->paid($booking);
        $total = (float) $booking->total_amount;

        $lines = [sprintf(
            'Booking #%d is your campaign%s on %s at %s, running from %s to %s.',
            $booking->id,
            $booking->brand_name ? ' for '.$booking->brand_name : '',
            $booking->billboard?->title ?? 'a billboard',
            $booking->billboard?->address ?? 'Dhaka',
            $booking->start_date?->toFormattedDateString() ?? 'an unset date',
            $booking->end_date?->toFormattedDateString() ?? 'an unset date',
        )];

        if ($booking->ad_category) {
            $lines[] = sprintf('The ad category is %s.', $booking->ad_category);
        }

        $lines[] = sprintf(
            'The booking total is BDT %s. BDT %s has been paid so far, leaving BDT %s outstanding.',
            number_format($total),
            number_format($paid),
            number_format(max($total - $paid, 0)),
        );

        $lines[] = sprintf(
            'Its current stage is "%s". %s',
            $booking->status,
            ClientAssistantTools::STATUS_MEANINGS[$booking->status] ?? '',
        );

        if ($booking->final_payment_due_at) {
            $lines[] = sprintf('The balance is due by %s.', $booking->final_payment_due_at->toFormattedDateString());
        }

        if ($booking->rejection_reason) {
            $lines[] = sprintf('It was turned down for this reason: %s', $booking->rejection_reason);
        }

        return implode(' ', array_filter($lines));
    }

    private function ownerView(Booking $booking): string
    {
        $lines = [sprintf(
            'Booking request #%d on your billboard %s, from %s to %s.',
            $booking->id,
            $booking->billboard?->title ?? 'a billboard',
            $booking->start_date?->toFormattedDateString() ?? 'an unset date',
            $booking->end_date?->toFormattedDateString() ?? 'an unset date',
        )];

        $lines[] = sprintf(
            'The advertiser is %s%s.',
            $booking->user?->name ?? 'a client',
            $booking->brand_name ? ', advertising '.$booking->brand_name : '',
        );

        if ($booking->ad_category) {
            $lines[] = sprintf('The ad category is %s.', $booking->ad_category);
        }

        $lines[] = sprintf('The booking is worth BDT %s before the platform commission.', number_format((float) $booking->total_amount));

        $lines[] = $booking->status === 'pending_owner_approval'
            ? 'It is waiting for you to accept or decline it.'
            : sprintf('Its current stage is "%s".', $booking->status);

        return implode(' ', array_filter($lines));
    }

    private function paid(Booking $booking): float
    {
        return (float) $booking->payments
            ->where('status', 'paid')
            ->whereNull('refunded_at')
            ->sum(fn ($payment) => (float) $payment->amount);
    }
}
