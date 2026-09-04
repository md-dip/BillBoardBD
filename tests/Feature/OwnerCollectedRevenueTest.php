<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cover for the owner dashboard's "Revenue (BDT)" tile, which sums the
 * collected_amount that /api/owner/bookings puts on every booking.
 *
 * Same rule as the admin report (Shared\RevenueRecognitionService): a booking
 * earns nothing while it still awaits an approval, the advance once both are
 * in, and the rest when the balance is actually paid - never the contract
 * value of money the client has not handed over.
 */
class OwnerCollectedRevenueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $client;

    private Billboard $billboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => Hash::make('password'), 'role' => 'admin']);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.com', 'password' => Hash::make('password'), 'role' => 'owner']);
        $this->client = User::create(['name' => 'Client', 'email' => 'client@test.com', 'password' => Hash::make('password'), 'role' => 'client']);

        $this->billboard = Billboard::create([
            'owner_id' => $this->owner->id, 'title' => 'Test Board', 'address' => 'Dhaka',
            'latitude' => 23.8, 'longitude' => 90.4, 'size' => '10ft x 8ft', 'type' => 'neon',
            'daily_rate' => 1000, 'pricing_mode' => 'daily', 'listing_status' => 'approved',
        ]);
    }

    public function test_an_advance_awaiting_the_owners_own_approval_earns_nothing_yet(): void
    {
        $this->bookingWithPaidAdvance('pending_owner_approval');

        // The owner can still reject this, which refunds the client in full.
        $this->assertSame(0.0, $this->revenueTile());
    }

    public function test_accepting_the_booking_earns_the_advance_only(): void
    {
        $booking = $this->bookingWithPaidAdvance('pending_owner_approval');

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/owner/bookings/{$booking->id}/approve")->assertOk();

        // 3,000 collected of a 10,000 booking - the balance is not theirs yet.
        $this->assertSame(3000.0, $this->revenueTile());
    }

    public function test_the_balance_payment_brings_the_booking_up_to_its_full_value(): void
    {
        $booking = $this->bookingWithPaidAdvance('confirmed');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/bookings/{$booking->id}/balance-payment", ['method' => 'bank'])->assertCreated();

        $this->assertSame(10000.0, $this->revenueTile());
    }

    public function test_a_rejected_booking_earns_nothing(): void
    {
        $booking = $this->bookingWithPaidAdvance('pending_owner_approval');

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/owner/bookings/{$booking->id}/reject", [
            'rejection_reason' => 'The board is committed to another campaign.',
        ])->assertOk();

        $this->assertSame(0.0, $this->revenueTile());
    }

    public function test_the_tile_only_counts_this_owners_own_boards(): void
    {
        $otherOwner = User::create(['name' => 'Other', 'email' => 'other@test.com', 'password' => Hash::make('password'), 'role' => 'owner']);
        $otherBoard = Billboard::create([
            'owner_id' => $otherOwner->id, 'title' => 'Someone Elses Board', 'address' => 'Dhaka',
            'latitude' => 23.7, 'longitude' => 90.3, 'size' => '10ft x 8ft', 'type' => 'neon',
            'daily_rate' => 1000, 'pricing_mode' => 'daily', 'listing_status' => 'approved',
        ]);

        $this->bookingWithPaidAdvance('confirmed');
        $this->bookingWithPaidAdvance('confirmed', $otherBoard);

        $this->assertSame(3000.0, $this->revenueTile());
    }

    /**
     * What the dashboard tile does: sum collected_amount over the owner's
     * bookings (see owner/pages/Dashboard.jsx).
     */
    private function revenueTile(): float
    {
        Sanctum::actingAs($this->owner);

        $bookings = $this->getJson('/api/owner/bookings')->assertOk()->json('data');

        return round(collect($bookings)->sum(fn (array $booking) => (float) $booking['collected_amount']), 2);
    }

    private function bookingWithPaidAdvance(string $status, ?Billboard $billboard = null): Booking
    {
        $booking = Booking::create([
            'billboard_id' => ($billboard ?? $this->billboard)->id, 'user_id' => $this->client->id,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(9)->toDateString(),
            'total_amount' => 10000, 'advance_amount' => 3000, 'status' => $status,
        ]);

        Payment::create([
            'booking_id' => $booking->id, 'payment_type' => 'advance', 'amount' => 3000,
            'status' => 'paid', 'commission_amount' => 1000, 'owner_payable' => 9000, 'paid_at' => now(),
        ]);

        return $booking;
    }
}
