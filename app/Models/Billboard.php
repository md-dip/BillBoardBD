<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Billboard extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id', 'title', 'description', 'latitude', 'longitude', 'address', 'size', 'type',
        'daily_rate', 'monthly_rate', 'pricing_mode', 'photo', 'rating', 'status',
        'permit_expiry_date', 'listing_status', 'permit_document', 'listing_rejection_reason',
        'submitted_at', 'reviewed_at', 'reviewed_by',
    ];

    protected $appends = ['photo_url', 'permit_document_url'];

    /**
     * Image URL for the frontend. Seeded photos live at
     * frontend/public/billboards/<id>.jpg and are stored as a root-relative
     * "/billboards/1.jpg" (or an absolute URL) - those pass through untouched.
     * Owner-uploaded photos are stored as a bare public-disk path
     * ("board-photos/xxx.jpg") and get resolved through the storage disk.
     * Null when there is no photo.
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

            return Storage::disk('public')->url($this->photo);
        });
    }

    /** Owner-uploaded permit document (PDF/image) on the public disk. */
    protected function permitDocumentUrl(): Attribute
    {
        return Attribute::get(fn () => $this->permit_document
            ? Storage::disk('public')->url($this->permit_document)
            : null);
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
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function listingPayments(): HasMany
    {
        return $this->hasMany(ListingPayment::class);
    }

    public function activeBookings(): HasMany
    {
        return $this->bookings()->whereIn('status', [
            'held', 'pending_payment', 'pending_admin_review', 'pending_owner_approval',
            'confirmed', 'paid_in_full', 'pending_proof_review', 'active',
        ]);
    }
}
