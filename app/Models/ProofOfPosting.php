<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'booking_id', 'photo_path', 'status', 'verified_by', 'verified_at', 'rejection_reason',
])]
class ProofOfPosting extends Model
{
    protected $appends = ['photo_url'];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn () => Storage::disk('public')->url($this->photo_path));
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
