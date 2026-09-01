<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'booking_id', 'amount', 'payment_type', 'method', 'transaction_ref',
    'status', 'commission_amount', 'owner_payable', 'paid_at', 'refunded_at', 'payout_id',
    'gateway', 'gateway_tran_id', 'gateway_val_id', 'gateway_session_key', 'gateway_payload',
])]
class Payment extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'owner_payable' => 'decimal:2',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'gateway_payload' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
