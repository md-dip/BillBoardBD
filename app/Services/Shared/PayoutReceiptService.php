<?php

namespace App\Services\Shared;

use App\Models\Payout;

class PayoutReceiptService
{
    /**
     * @return array<string, mixed>
     */
    public function payload(Payout $payout): array
    {
        $payout->loadMissing('paidBy', 'owner');

        $rows = $payout->payments()->with('booking.billboard')->get();

        $lineItems = $rows->map(fn ($p) => [
            'billboard_title' => $p->booking?->billboard?->title,
            'booking_id' => $p->booking?->id,
            'start_date' => optional($p->booking?->start_date)->toDateString(),
            'end_date' => optional($p->booking?->end_date)->toDateString(),
            'gross' => (float) ($p->booking?->total_amount ?? 0),
            'commission' => (float) $p->commission_amount,
            'net' => (float) $p->owner_payable,
        ])->values();

        $grossTotal = round((float) $lineItems->sum('gross'), 2);
        $commissionTotal = round((float) $lineItems->sum('commission'), 2);
        $netTotal = round((float) $lineItems->sum('net'), 2);
        $amount = (float) $payout->amount;

        return [
            'number' => sprintf('PO-%06d', $payout->id),
            'issued_at' => $payout->paid_at?->toDateString(),
            'payout_id' => $payout->id,
            'method' => $payout->method,
            'reference' => $payout->reference,
            'note' => $payout->note,
            'paid_by' => $payout->paidBy?->name,
            'platform' => [
                'name' => 'BillboardBD',
                'address' => 'Uttara 10, Dhaka 1230, Bangladesh',
                'email' => 'hello@billboardbd.com',
                'phone' => '+880 1234-567890',
            ],
            'owner' => [
                'name' => $payout->owner?->name,
                'email' => $payout->owner?->email,
                'details' => $payout->payout_details ?: null,
            ],
            'line_items' => $lineItems,
            'totals' => [
                'gross' => $grossTotal,
                'commission' => $commissionTotal,
                'amount' => $amount,
                'amount_matches_lines' => abs($netTotal - $amount) < 0.01,
            ],
        ];
    }
}
