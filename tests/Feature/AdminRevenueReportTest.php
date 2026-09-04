<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\ListingPayment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cover for /api/admin/reports/revenue - the numbers behind the admin
 * dashboard's KPI tiles and its revenue-by-month chart.
 *
 * The two cases that were wrong: an owner's one-time board listing fee is
 * platform income in full (no owner split), but it lives in `listing_payments`,
 * which the report never looked at - so a paid fee showed up nowhere. And once
 * it was counted, it counted from the moment the owner paid, even though admin
 * can still reject the board and refund that money.
 */
class AdminRevenueReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private Billboard $billboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => Hash::make('password'), 'role' => 'admin']);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.com', 'password' => Hash::make('password'), 'role' => 'owner']);
        $client = User::create(['name' => 'Client', 'email' => 'client@test.com', 'password' => Hash::make('password'), 'role' => 'client']);

        $this->billboard = Billboard::create([
            'owner_id' => $this->owner->id, 'title' => 'Test Board', 'address' => 'Dhaka',
            'latitude' => 23.8, 'longitude' => 90.4, 'size' => '10ft x 8ft', 'type' => 'neon',
            'daily_rate' => 1000, 'pricing_mode' => 'daily', 'listing_status' => 'approved',
        ]);

        // A paid booking: 10,000 gross, 10% commission.
        $booking = Booking::create([
            'billboard_id' => $this->billboard->id, 'user_id' => $client->id,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(9)->toDateString(),
            'total_amount' => 10000, 'advance_amount' => 3000, 'status' => 'confirmed',
        ]);

        Payment::create([
            'booking_id' => $booking->id, 'payment_type' => 'advance', 'amount' => 3000,
            'status' => 'paid', 'commission_amount' => 1000, 'owner_payable' => 9000,
        ]);
    }

    private function report(): array
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/admin/reports/revenue')->assertOk()->json('data');
    }

    public function test_booking_money_is_reported_as_before(): void
    {
        $totals = $this->report()['totals'];

        $this->assertSame(10000.0, (float) $totals['gross']);
        $this->assertSame(1000.0, (float) $totals['commission']);
        $this->assertSame(9000.0, (float) $totals['owner_payable']);
        $this->assertSame(0.0, (float) $totals['listing_fees']);
        $this->assertSame(1000.0, (float) $totals['platform_income']);
    }

    public function test_a_paid_listing_fee_counts_as_platform_income(): void
    {
        ListingPayment::create([
            'billboard_id' => $this->billboard->id, 'owner_id' => $this->owner->id,
            'amount' => 5000, 'status' => 'paid', 'paid_at' => now(),
        ]);

        $totals = $this->report()['totals'];

        $this->assertSame(5000.0, (float) $totals['listing_fees']);
        // The headline the dashboard shows: commission + the listing fee.
        $this->assertSame(6000.0, (float) $totals['platform_income']);
        // Booking-side figures must not move.
        $this->assertSame(10000.0, (float) $totals['gross']);
        $this->assertSame(1000.0, (float) $totals['commission']);
    }

    public function test_a_refunded_listing_fee_is_left_out(): void
    {
        ListingPayment::create([
            'billboard_id' => $this->billboard->id, 'owner_id' => $this->owner->id,
            'amount' => 5000, 'status' => 'refunded', 'paid_at' => now(), 'refunded_at' => now(),
        ]);

        $totals = $this->report()['totals'];

        $this->assertSame(0.0, (float) $totals['listing_fees']);
        $this->assertSame(1000.0, (float) $totals['platform_income']);
    }

    public function test_a_fee_on_a_board_with_no_bookings_still_gets_a_row(): void
    {
        $fresh = Billboard::create([
            'owner_id' => $this->owner->id, 'title' => 'Freshly Listed', 'address' => 'Dhaka',
            'latitude' => 23.9, 'longitude' => 90.5, 'size' => '20ft x 10ft', 'type' => 'unipole',
            'daily_rate' => 4500, 'pricing_mode' => 'daily', 'listing_status' => 'approved',
        ]);

        ListingPayment::create([
            'billboard_id' => $fresh->id, 'owner_id' => $this->owner->id,
            'amount' => 5000, 'status' => 'paid', 'paid_at' => now(),
        ]);

        $data = $this->report();
        $row = collect($data['rows'])->firstWhere('billboard_id', $fresh->id);

        // Nothing booked on it yet, so it exists in the report purely for the
        // fee - the month bucket the dashboard chart reads has to include it.
        $this->assertNotNull($row, 'a board with only a listing fee must still appear');
        $this->assertSame(5000.0, (float) $row['listing_fees']);
        $this->assertSame(0.0, (float) $row['gross']);
        $this->assertSame(now()->format('Y-m'), $row['month']);
    }

    public function test_a_fee_on_a_board_still_awaiting_review_is_not_income_yet(): void
    {
        $this->billboard->update(['listing_status' => 'pending_review', 'reviewed_at' => null]);

        ListingPayment::create([
            'billboard_id' => $this->billboard->id, 'owner_id' => $this->owner->id,
            'amount' => 5000, 'status' => 'paid', 'paid_at' => now(),
        ]);

        // Admin can still reject this board, which refunds the fee - so the
        // platform has not earned it yet.
        $totals = $this->report()['totals'];

        $this->assertSame(0.0, (float) $totals['listing_fees']);
        $this->assertSame(1000.0, (float) $totals['platform_income']);
    }

    public function test_the_fee_lands_as_income_the_moment_admin_approves(): void
    {
        $this->billboard->update(['listing_status' => 'pending_review', 'reviewed_at' => null]);

        ListingPayment::create([
            'billboard_id' => $this->billboard->id, 'owner_id' => $this->owner->id,
            'amount' => 5000, 'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->assertSame(0.0, (float) $this->report()['totals']['listing_fees']);

        // Approve it the way admin actually does, through the API.
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/billboards/{$this->billboard->id}/approve")->assertOk();

        $this->assertSame(5000.0, (float) $this->report()['totals']['listing_fees']);
    }

    public function test_rejecting_the_board_refunds_the_fee_and_it_stays_out(): void
    {
        $this->billboard->update(['listing_status' => 'pending_review', 'reviewed_at' => null]);

        ListingPayment::create([
            'billboard_id' => $this->billboard->id, 'owner_id' => $this->owner->id,
            'amount' => 5000, 'status' => 'paid', 'paid_at' => now(),
        ]);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/billboards/{$this->billboard->id}/reject", [
            'rejection_reason' => 'Permit document is unreadable.',
        ])->assertOk();

        // The money went back to the owner, so it was never platform income.
        $this->assertDatabaseHas('listing_payments', ['billboard_id' => $this->billboard->id, 'status' => 'refunded']);
        $this->assertSame(0.0, (float) $this->report()['totals']['listing_fees']);
    }

    public function test_a_rejected_board_never_contributes_even_if_the_refund_did_not_run(): void
    {
        $this->billboard->update(['listing_status' => 'rejected']);

        // Left as 'paid' on purpose: the board status alone has to keep it out.
        ListingPayment::create([
            'billboard_id' => $this->billboard->id, 'owner_id' => $this->owner->id,
            'amount' => 5000, 'status' => 'paid', 'paid_at' => now(),
        ]);

        $this->assertSame(0.0, (float) $this->report()['totals']['listing_fees']);
    }

    public function test_the_fee_is_bucketed_in_the_month_it_was_approved(): void
    {
        // Paid two months ago, approved today: it belongs to today's month, so
        // approving never rewrites a figure the admin already read last month.
        ListingPayment::create([
            'billboard_id' => $this->billboard->id, 'owner_id' => $this->owner->id,
            'amount' => 5000, 'status' => 'paid', 'paid_at' => now()->subMonths(2),
        ]);
        $this->billboard->update(['reviewed_at' => now()]);

        $row = collect($this->report()['rows'])
            ->firstWhere('month', now()->format('Y-m'));

        $this->assertSame(5000.0, (float) $row['listing_fees']);
    }
}
