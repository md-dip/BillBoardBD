<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'billboard_id', 'owner_id', 'amount', 'status', 'method', 'transaction_ref',
    'gateway', 'gateway_tran_id', 'gateway_val_id', 'gateway_session_key', 'gateway_payload',
    'paid_at', 'refunded_at',
    'refund_method', 'refund_transaction_ref', 'refund_gateway_tran_id',
    'refund_gateway_val_id', 'refund_gateway_session_key', 'refund_gateway_payload',
])]
class ListingPayment extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gateway_payload' => 'array',
            'refund_gateway_payload' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function billboard(): BelongsTo
    {
        return $this->belongsTo(Billboard::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
