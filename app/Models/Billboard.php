<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Billboard extends Model
{
    protected $fillable = [
        'title', 'description', 'latitude', 'longitude', 'address',
        'size', 'type', 'daily_rate', 'pricing_mode', 'monthly_rate',
        'photo', 'rating', 'status',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Bookings that actually block the calendar: anything still "in flight"
     * (a live hold, awaiting payment, awaiting review, approved, or completed),
     * excluding only rejected/cancelled dead ends.
     */
    public function activeBookings(): HasMany
    {
        return $this->bookings()->whereIn('status', [
            'held', 'pending_payment', 'pending', 'approved', 'completed',
        ]);
    }
}