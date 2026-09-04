<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\ProofOfPosting;
use App\Models\User;
use App\Services\Shared\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cover for the proof-of-installation gate on owner payouts.
 *
 * A fully paid booking earns the owner revenue immediately, but the money is
 * not payable until the owner has uploaded proof the campaign went up AND
 * admin has verified it. Revenue and payout balance are deliberately allowed
 * to disagree in that window.
 */
class PayoutProofGateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $client;

    private Billboard $billboard;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => Hash::make('password'), 'role' => 'admin']);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.com', 'password' => Hash::make('password'), 'role' => 'owner']);
        $this->client = User::create(['name' => 'Client', 'email' => 'client@test.com', 'password' => Hash::make('password'), 'role' => 'client']);

        $this->billboard = Billboard::create([
            'owner_id' => $this->owner->id, 'title' => 'Test Board', 'address' => 'Dhaka',
            'latitude' => 23.8, 'longitude' => 90.4, 'size' => '10ft x 8ft', 'type' => 'neon',
            'daily_rate' => 1000, 'pricing_mode' => 'daily', 'listing_status' => 'approved',
        ]);
    }

    public function test_a_fully_paid_booking_with_no_proof_is_not_payable(): void
    {
        $this->paidInFullBooking();

        $this->assertSame(0.0, $this->outstanding(), 'paid in full is not the same as earned');
    }

    public function test_uploading_the_proof_is_not_enough_on_its_own(): void
    {
        $booking = $this->paidInFullBooking();

        Sanctum::actingAs($this->owner);
        $this->post("/api/owner/bookings/{$booking->id}/proof", [
            'photos' => [UploadedFile::fake()->image('installed.jpg', 800, 600)],
        ])->assertCreated();

        // Admin has not looked at it yet - the money stays put.
        $this->assertSame('pending_proof_review', $booking->fresh()->status);
        $this->assertSame(0.0, $this->outstanding());
    }

    public function test_the_balance_moves_the_moment_admin_verifies_the_proof(): void
    {
        $booking = $this->paidInFullBooking();

        Sanctum::actingAs($this->owner);
        $this->post("/api/owner/bookings/{$booking->id}/proof", [
            'photos' => [UploadedFile::fake()->image('installed.jpg', 800, 600)],
        ])->assertCreated();

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/bookings/{$booking->id}/proof/verify")->assertOk();

        $this->assertSame('active', $booking->fresh()->status);
        $this->assertSame(9000.0, $this->outstanding(), 'owner_payable on the advance row is the whole booking net');
    }

    public function test_a_rejected_proof_puts_the_money_back_out_of_reach(): void
    {
        $booking = $this->paidInFullBooking();

        Sanctum::actingAs($this->owner);
        $this->post("/api/owner/bookings/{$booking->id}/proof", [
            'photos' => [UploadedFile::fake()->image('installed.jpg', 800, 600)],
        ])->assertCreated();

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/bookings/{$booking->id}/proof/reject", [
            'rejection_reason' => 'The photo does not show the installed creative.',
        ])->assertOk();

        $this->assertSame('paid_in_full', $booking->fresh()->status);
        $this->assertSame(0.0, $this->outstanding());
    }

    public function test_the_owners_own_payouts_page_reports_the_same_zero(): void
    {
        $this->paidInFullBooking();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/owner/payouts')
            ->assertOk()
            ->assertJsonPath('data.outstanding', 0);
    }

    public function test_admin_is_not_offered_an_owner_whose_proof_is_unverified(): void
    {
        $this->paidInFullBooking();

        $owed = app(PayoutService::class)->outstandingByOwner();

        $this->assertTrue($owed->isEmpty(), 'an owner with nothing payable must not appear on the admin payouts list');
    }

    /** A 10,000 booking, advance + balance both paid, no proof yet. */
    private function paidInFullBooking(): Booking
    {
        $booking = Booking::create([
            'billboard_id' => $this->billboard->id, 'user_id' => $this->client->id,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(9)->toDateString(),
            'total_amount' => 10000, 'advance_amount' => 3000, 'status' => 'paid_in_full',
        ]);

        Payment::create([
            'booking_id' => $booking->id, 'payment_type' => 'advance', 'amount' => 3000,
            'status' => 'paid', 'commission_amount' => 1000, 'owner_payable' => 9000, 'paid_at' => now(),
        ]);
        Payment::create([
            'booking_id' => $booking->id, 'payment_type' => 'balance', 'amount' => 7000,
            'status' => 'paid', 'commission_amount' => 0, 'owner_payable' => 7000, 'paid_at' => now(),
        ]);

        return $booking;
    }

    private function outstanding(): float
    {
        return app(PayoutService::class)->outstandingForOwner($this->owner->fresh());
    }
}
