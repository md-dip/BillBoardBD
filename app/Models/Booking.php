<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'billboard_id', 'user_id', 'start_date', 'end_date', 'total_amount',
        'advance_amount', 'status', 'rejection_reason', 'brand_name', 'ad_category',
        'campaign_description', 'creative_path', 'expires_at', 'final_payment_due_at',
    ];

    protected $appends = ['creative_url'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'total_amount' => 'decimal:2',
            'advance_amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'final_payment_due_at' => 'datetime',
        ];
    }

    protected function creativeUrl(): Attribute
    {
        return Attribute::get(fn () => $this->creative_path ? Storage::disk('public')->url($this->creative_path) : null);
    }

    public function billboard(): BelongsTo
    {
        return $this->belongsTo(Billboard::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function proofOfPostings(): HasMany
    {
        return $this->hasMany(ProofOfPosting::class);
    }
}