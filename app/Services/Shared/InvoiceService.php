<?php

namespace App\Services\Shared;

use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Setting;

/**
 * Builds and stores the two invoices a booking earns over its life:
 *
 *   'advance' - issued the moment the client pays the 30% advance.
 *   'final'   - issued once the booking is paid in full.
 *
 * Each invoice is an immutable snapshot. The platform commission is NOT a
 * constant anywhere - it is read from the `commission_rate` setting at pay
 * time (frozen onto the Payment rows by the booking flow), and this service
 * simply snapshots whatever was frozen. Change the rate in the admin panel
 * and every booking paid after that point invoices at the new rate.
 */
class InvoiceService
{
    /**
     * Issue the invoice for a milestone, or return the one already issued.
     * Idempotent - safe to call again on a re-tried payment.
     */
    public function issue(Booking $booking, string $kind): Invoice
    {
        $booking->loadMissing('payments');

        $subtotal = round((float) $booking->total_amount, 2);

        $commissionAmount = round((float) $booking->payments->sum('commission_amount'), 2);
        if ($commissionAmount <= 0) {
            // Nothing frozen yet (shouldn't happen once the advance is paid) -
            // fall back to the live setting so the number is never zero.
            $rate = (float) Setting::get('commission_rate', 10);
            $commissionAmount = round($subtotal * $rate / 100, 2);
        }
        $commissionRate = $subtotal > 0 ? round($commissionAmount / $subtotal * 100, 2) : 0.0;

        // Deterministic by milestone, not a live sum - an 'advance' invoice
        // always states the advance and the balance still owed; a 'final' one
        // always states the whole amount with nothing left.
        if ($kind === 'final') {
            $amountPaid = $subtotal;
            $balanceDue = 0.0;
        } else {
            $amountPaid = round((float) $booking->advance_amount, 2);
            $balanceDue = round($subtotal - $amountPaid, 2);
        }

        return Invoice::query()->firstOrCreate(
            ['booking_id' => $booking->id, 'kind' => $kind],
            [
                'number' => $this->number($booking, $kind),
                'subtotal' => $subtotal,
                'commission_rate' => $commissionRate,
                'commission_amount' => $commissionAmount,
                'owner_payable' => round($subtotal - $commissionAmount, 2),
                'amount_paid' => $amountPaid,
                'balance_due' => $balanceDue,
                'issued_at' => now(),
            ],
        );
    }

    /**
     * The display payload for an invoice. $showOwnerSplit adds the platform
     * commission / payable-to-owner lines - on for admin, off for the client.
     *
     * @return array<string, mixed>
     */
    public function payload(Invoice $invoice, bool $showOwnerSplit): array
    {
        $booking = $invoice->booking()->with(['billboard', 'user', 'payments'])->first();

        $days = $booking->start_date->diffInDays($booking->end_date) + 1;
        $subtotal = (float) $invoice->subtotal;

        // Only the payments that belong on this invoice: the advance invoice
        // shows the advance alone, the final invoice shows the full history.
        $paidPayments = $booking->payments->where('status', 'paid');
        if ($invoice->kind === 'advance') {
            $paidPayments = $paidPayments->where('payment_type', 'advance');
        }

        $data = [
            'number' => $invoice->number,
            'kind' => $invoice->kind,
            'issued_at' => $invoice->issued_at->toDateString(),
            'booking_id' => $booking->id,
            'seller' => [
                'name' => 'BillboardBD',
                'address' => 'Uttara 10, Dhaka 1230, Bangladesh',
                'email' => 'hello@billboardbd.com',
                'phone' => '+880 1234-567890',
            ],
            'client' => [
                'name' => $booking->user?->name,
                'email' => $booking->user?->email,
            ],
            'billboard' => [
                'title' => $booking->billboard?->title,
                'address' => $booking->billboard?->address,
                'size' => $booking->billboard?->size,
                'type' => $booking->billboard?->type,
            ],
            'line_item' => [
                'description' => 'Billboard hire',
                'start_date' => $booking->start_date->toDateString(),
                'end_date' => $booking->end_date->toDateString(),
                'days' => $days,
                'daily_rate' => $days > 0 ? round($subtotal / $days, 2) : $subtotal,
                'amount' => $subtotal,
            ],
            'subtotal' => $subtotal,
            'payments' => $paidPayments
                ->sortBy('id')          // advance row is always created before the balance row
                ->map(fn ($p) => [
                    'date' => optional($p->paid_at)->toDateString(),
                    'type' => $p->payment_type,
                    'method' => $p->method,
                    'transaction_ref' => $p->transaction_ref,
                    'amount' => (float) $p->amount,
                ])
                ->values(),
            'amount_paid' => (float) $invoice->amount_paid,
            'balance_due' => (float) $invoice->balance_due,
        ];

        if ($showOwnerSplit) {
            $data['commission_rate'] = (float) $invoice->commission_rate;
            $data['commission_amount'] = (float) $invoice->commission_amount;
            $data['owner_payable'] = (float) $invoice->owner_payable;
        }

        return $data;
    }

    private function number(Booking $booking, string $kind): string
    {
        return sprintf('INV-%06d-%s', $booking->id, $kind === 'final' ? 'F' : 'A');
    }
}
