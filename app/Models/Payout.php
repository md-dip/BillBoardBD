<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'owner_id', 'amount', 'method', 'reference', 'note', 'payout_details', 'paid_by', 'paid_at',
])]
class Payout extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payout_details' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
