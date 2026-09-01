<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Billboard extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id', 'title', 'description', 'latitude', 'longitude', 'address', 'size', 'type',
        'daily_rate', 'monthly_rate', 'pricing_mode', 'photo', 'rating', 'status',
        'permit_expiry_date',
    ];

    protected $appends = ['photo_url'];

    /**
     * Image URL for the frontend. Photos live at frontend/public/billboards/<id>.jpg
     * (committed, served by the SPA itself), so `photo` is already a usable
     * root-relative URL like "/billboards/1.jpg". Absolute URLs pass through; a
     * legacy bare path gets a leading slash. Null when there is no photo.
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->photo) {
                return null;
            }
            if (str_starts_with($this->photo, 'http') || str_starts_with($this->photo, '/')) {
                return $this->photo;
            }

            return '/'.ltrim($this->photo, '/');
        });
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'daily_rate' => 'decimal:2',
            'monthly_rate' => 'decimal:2',
            'rating' => 'decimal:1',
            'permit_expiry_date' => 'date',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function activeBookings(): HasMany
    {
        return $this->bookings()->whereIn('status', [
            'held', 'pending_payment', 'pending_admin_review', 'pending_owner_approval',
            'confirmed', 'paid_in_full', 'pending_proof_review', 'active',
        ]);
    }
}
