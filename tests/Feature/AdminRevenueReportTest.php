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
 * The rule this whole file is about: money only counts once it can no longer
 * be refunded. A client's advance is refundable until BOTH admin and the owner
 * have approved the booking; an owner's board listing fee is refundable until
 * admin approves the board. The balance payment and an approved fee are final,
 * so they count as soon as they land.
 *
 * And commission follows the cash: the platform's cut is pro-rated against
 * what the client has actually paid, never against the whole contract.
 */
class AdminRevenueReportTest extends TestCase
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

    // ---------------------------------------------------------------- bookings

    public function test_an_advance_awaiting_admin_review_is_not_income_yet(): void
    {
        $this->bookingWithPaidAdvance('pending_admin_review');

        $totals = $this->totals();

        $this->assertSame(0.0, (float) $totals['gross']);
        $this->assertSame(0.0, (float) $totals['commission']);
        $this->assertSame(0.0, (float) $totals['platform_income']);
    }

    public function test_an_advance_is_still_not_income_while_the_owner_has_yet_to_accept(): void
    {
        $booking = $this->bookingWithPaidAdvance('pending_admin_review');

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/bookings/{$booking->id}/approve")->assertOk();
        $this->assertSame('pending_owner_approval', $booking->fresh()->status);

        // The owner can still reject, which refunds the client.
        $this->assertSame(0.0, (float) $this->totals()['gross']);
    }

    public function test_the_advance_becomes_income_once_both_approvals_are_in(): void
    {
        $booking = $this->bookingWithPaidAdvance('pending_admin_review');

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/bookings/{$booking->id}/approve")->assertOk();

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/owner/bookings/{$booking->id}/approve")->assertOk();
        $this->assertSame('confirmed', $booking->fresh()->status);

        $totals = $this->totals();

        // Only the cash collected so far - the 3,000 advance - and only the
        // commission on THAT: 10% of 3,000, not 10% of the 10,000 contract.
        $this->assertSame(3000.0, (float) $totals['gross']);
        $this->assertSame(300.0, (float) $totals['commission']);
        $this->assertSame(2700.0, (float) $totals['owner_payable']);
        $this->assertSame(300.0, (float) $totals['platform_income']);
    }

    public function test_the_balance_payment_counts_as_soon_as_it_is_paid(): void
    {
        $booking = $this->bookingWithPaidAdvance('confirmed');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/bookings/{$booking->id}/balance-payment", ['method' => 'bank'])->assertCreated();

        $totals = $this->totals();

        // Nothing is rejectable at this point, so the 7,000 balance lands
        // straight away, bringing the remaining 700 of commission with it.
        $this->assertSame(10000.0, (float) $totals['gross']);
        $this->assertSame(1000.0, (float) $totals['commission']);
        $this->assertSame(9000.0, (float) $totals['owner_payable']);
    }

    public function test_an_admin_rejection_refunds_the_advance_and_it_stays_out(): void
    {
        $booking = $this->bookingWithPaidAdvance('pending_admin_review');

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/bookings/{$booking->id}/reject", [
            'rejection_reason' => 'Creative breaches the content policy.',
        ])->assertOk();

        $this->assertDatabaseHas('payments', ['booking_id' => $booking->id, 'payment_type' => 'advance', 'status' => 'refunded']);
        $this->assertSame(0.0, (float) $this->totals()['gross']);
    }

    public function test_an_owner_rejection_refunds_the_advance_and_it_stays_out(): void
    {
        $booking = $this->bookingWithPaidAdvance('pending_admin_review');

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/bookings/{$booking->id}/approve")->assertOk();

        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/owner/bookings/{$booking->id}/reject", [
            'rejection_reason' => 'The board is already committed to another campaign.',
        ])->assertOk();

        $this->assertDatabaseHas('payments', ['booking_id' => $booking->id, 'payment_type' => 'advance', 'status' => 'refunded']);
        $this->assertSame(0.0, (float) $this->totals()['gross']);
    }

    public function test_commission_is_pro_rated_against_what_the_client_has_actually_paid(): void
    {
        // A client who pays the advance and then walks away never hands over
        // the balance, so the platform never earns the cut on it. Booking the
        // full 1,000 up front would report income that may never arrive.
        $booking = $this->bookingWithPaidAdvance('confirmed');

        $this->assertSame(300.0, (float) $this->totals()['commission'], '10% of the 3,000 advance');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/bookings/{$booking->id}/balance-payment", ['method' => 'bank'])->assertCreated();

        // The other 700 arrives with the balance - 1,000 in total, the same as
        // the amount frozen on the booking, only now it is all collected.
        $this->assertSame(1000.0, (float) $this->totals()['commission']);
    }

    public function test_commission_uses_the_rate_frozen_on_the_booking_not_the_current_setting(): void
    {
        // Booking sold at 20% (commission 2,000 of 10,000), regardless of what
        // the admin's commission_rate setting says today.
        $booking = Booking::create([
            'billboard_id' => $this->billboard->id, 'user_id' => $this->client->id,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(9)->toDateString(),
            'total_amount' => 10000, 'advance_amount' => 3000, 'status' => 'confirmed',
        ]);
        Payment::create([
            'booking_id' => $booking->id, 'payment_type' => 'advance', 'amount' => 3000,
            'status' => 'paid', 'commission_amount' => 2000, 'owner_payable' => 8000, 'paid_at' => now(),
        ]);

        $this->assertSame(600.0, (float) $this->totals()['commission'], '20% of the 3,000 advance');
    }

    // ----------------------------------------------------------- listing fees

    public function test_a_fee_on_a_board_still_awaiting_review_is_not_income_yet(): void
    {
        $this->billboard->update(['listing_status' => 'pending_review', 'reviewed_at' => null]);
        $this->payListingFee($this->billboard);

        // Admin can still reject this board, which refunds the fee.
        $this->assertSame(0.0, (float) $this->totals()['listing_fees']);
    }

    public function test_the_fee_lands_as_income_the_moment_admin_approves(): void
    {
        $this->billboard->update(['listing_status' => 'pending_review', 'reviewed_at' => null]);
        $this->payListingFee($this->billboard);

        $this->assertSame(0.0, (float) $this->totals()['listing_fees']);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/billboards/{$this->billboard->id}/approve")->assertOk();

        $this->assertSame(5000.0, (float) $this->totals()['listing_fees']);
    }

    public function test_rejecting_the_board_refunds_the_fee_and_it_stays_out(): void
    {
        $this->billboard->update(['listing_status' => 'pending_review', 'reviewed_at' => null]);
        $this->payListingFee($this->billboard);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/billboards/{$this->billboard->id}/reject", [
            'rejection_reason' => 'Permit document is unreadable.',
        ])->assertOk();

        $this->assertDatabaseHas('listing_payments', ['billboard_id' => $this->billboard->id, 'status' => 'refunded']);
        $this->assertSame(0.0, (float) $this->totals()['listing_fees']);
    }

    public function test_a_rejected_board_never_contributes_even_if_the_refund_did_not_run(): void
    {
        // Fee left as 'paid' on purpose: the board status alone has to keep it out.
        $this->billboard->update(['listing_status' => 'rejected']);
        $this->payListingFee($this->billboard);

        $this->assertSame(0.0, (float) $this->totals()['listing_fees']);
    }

    public function test_the_fee_is_bucketed_in_the_month_it_was_approved(): void
    {
        // Paid two months ago, approved today: it belongs to today's month, so
        // approving never rewrites a figure the admin already read last month.
        $this->payListingFee($this->billboard, now()->subMonths(2));
        $this->billboard->update(['reviewed_at' => now()]);

        $row = collect($this->rows())->firstWhere('month', now()->format('Y-m'));

        $this->assertSame(5000.0, (float) $row['listing_fees']);
    }

    public function test_a_fee_on_a_board_with_no_bookings_still_gets_a_row(): void
    {
        $fresh = Billboard::create([
            'owner_id' => $this->owner->id, 'title' => 'Freshly Listed', 'address' => 'Dhaka',
            'latitude' => 23.9, 'longitude' => 90.5, 'size' => '20ft x 10ft', 'type' => 'unipole',
            'daily_rate' => 4500, 'pricing_mode' => 'daily', 'listing_status' => 'approved',
            'reviewed_at' => now(),
        ]);
        $this->payListingFee($fresh);

        $row = collect($this->rows())->firstWhere('billboard_id', $fresh->id);

        // Nothing booked on it yet, so it is in the report purely for the fee -
        // the month bucket the dashboard chart reads has to include it. The fee
        // counts in gross too: gross is every taka that entered the platform,
        // which is exactly what the Total revenue drill-down lists.
        $this->assertNotNull($row, 'a board with only a listing fee must still appear');
        $this->assertSame(5000.0, (float) $row['listing_fees']);
        $this->assertSame(5000.0, (float) $row['gross']);
    }

    // ----------------------------------------------------------------- totals

    public function test_platform_income_adds_booking_commission_to_listing_fees(): void
    {
        $this->bookingWithPaidAdvance('confirmed');
        $this->billboard->update(['reviewed_at' => now()]);
        $this->payListingFee($this->billboard);

        $totals = $this->totals();

        // 10% of the 3,000 advance collected, plus the whole 5,000 fee.
        $this->assertSame(300.0, (float) $totals['commission']);
        $this->assertSame(5000.0, (float) $totals['listing_fees']);
        $this->assertSame(5300.0, (float) $totals['platform_income']);
    }

    // ------------------------------------------------------------ drill-down

    public function test_the_drill_down_lists_every_transaction_that_entered_the_system(): void
    {
        $booking = $this->bookingWithPaidAdvance('confirmed');
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/bookings/{$booking->id}/balance-payment", ['method' => 'bank'])->assertCreated();

        $this->billboard->update(['reviewed_at' => now()]);
        $this->payListingFee($this->billboard);

        $rows = collect($this->transactions()['transactions']);

        $this->assertSame(3, $rows->count());
        $this->assertEqualsCanonicalizing(
            ['booking_advance', 'booking_balance', 'listing_fee'],
            $rows->pluck('type')->all()
        );
    }

    public function test_each_row_shows_the_rate_its_cut_was_taken_at(): void
    {
        $this->bookingWithPaidAdvance('confirmed');
        $this->billboard->update(['reviewed_at' => now()]);
        $this->payListingFee($this->billboard);

        $rows = collect($this->transactions()['transactions'])->keyBy('type');

        // 10% of the 3,000 advance.
        $this->assertSame(10.0, (float) $rows['booking_advance']['commission_rate']);
        $this->assertSame(300.0, (float) $rows['booking_advance']['platform_cut']);
        $this->assertSame(2700.0, (float) $rows['booking_advance']['owner_payable']);

        // A listing fee has no owner split - the platform keeps all of it.
        $this->assertSame(100.0, (float) $rows['listing_fee']['commission_rate']);
        $this->assertSame(5000.0, (float) $rows['listing_fee']['platform_cut']);
        $this->assertSame(0.0, (float) $rows['listing_fee']['owner_payable']);
    }

    public function test_the_drill_down_totals_match_the_tiles_exactly(): void
    {
        $this->bookingWithPaidAdvance('confirmed');
        $this->bookingWithPaidAdvance('pending_admin_review');   // not earned yet
        $this->billboard->update(['reviewed_at' => now()]);
        $this->payListingFee($this->billboard);

        $tiles = $this->totals();
        $drillDown = $this->transactions()['totals'];

        // The list is what the tile is made of - if these ever diverge, the
        // admin is reading two different truths on the same screen.
        foreach (['gross', 'commission', 'listing_fees', 'platform_income', 'owner_payable'] as $figure) {
            $this->assertSame(
                (float) $tiles[$figure],
                (float) $drillDown[$figure],
                "drill-down {$figure} must equal the dashboard tile"
            );
        }
    }

    public function test_money_that_is_not_earned_yet_never_appears_in_the_list(): void
    {
        // Advance paid but the booking still awaits admin; fee paid but the
        // board still awaits review. Neither is the platform's money yet.
        $this->bookingWithPaidAdvance('pending_admin_review');

        $pendingBoard = Billboard::create([
            'owner_id' => $this->owner->id, 'title' => 'Awaiting Review', 'address' => 'Dhaka',
            'latitude' => 23.9, 'longitude' => 90.5, 'size' => '20ft x 10ft', 'type' => 'unipole',
            'daily_rate' => 4500, 'pricing_mode' => 'daily', 'listing_status' => 'pending_review',
        ]);
        $this->payListingFee($pendingBoard);

        $data = $this->transactions();

        $this->assertSame(0, $data['totals']['count']);
        $this->assertSame([], $data['transactions']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A 10,000 booking with the 3,000 advance paid. Commission is 10% of the
     * WHOLE booking and frozen onto the advance row, exactly as
     * Client\BookingController writes it.
     */
    private function bookingWithPaidAdvance(string $status): Booking
    {
        $booking = Booking::create([
            'billboard_id' => $this->billboard->id, 'user_id' => $this->client->id,
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(9)->toDateString(),
            'total_amount' => 10000, 'advance_amount' => 3000, 'status' => $status,
        ]);

        Payment::create([
            'booking_id' => $booking->id, 'payment_type' => 'advance', 'amount' => 3000,
            'status' => 'paid', 'commission_amount' => 1000, 'owner_payable' => 9000, 'paid_at' => now(),
        ]);

        return $booking;
    }

    private function payListingFee(Billboard $billboard, $paidAt = null): ListingPayment
    {
        return ListingPayment::create([
            'billboard_id' => $billboard->id, 'owner_id' => $this->owner->id,
            'amount' => 5000, 'status' => 'paid', 'paid_at' => $paidAt ?? now(),
        ]);
    }

    private function transactions(): array
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/admin/reports/transactions')->assertOk()->json('data');
    }

    private function totals(): array
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/admin/reports/revenue')->assertOk()->json('data.totals');
    }

    private function rows(): array
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/admin/reports/revenue')->assertOk()->json('data.rows');
    }
}
