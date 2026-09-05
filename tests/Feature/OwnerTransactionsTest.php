<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\ListingPayment;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\ProofOfPosting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cover for /api/owner/reports/transactions - the drill-down behind the owner
 * dashboard's "Revenue (BDT)" tile.
 *
 * Two things matter here: the figures have to add up to that tile exactly, and
 * an owner must only ever see money collected on their own boards.
 */
class OwnerTransactionsTest extends TestCase
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

        $this->billboard = $this->board('Test Board', $this->owner);
    }

    public function test_a_confirmed_bookings_advance_shows_with_the_commission_split(): void
    {
        $this->bookingWithPaidAdvance('confirmed');

        $data = $this->ledger();
        $row = $data['transactions'][0];

        $this->assertSame('booking_advance', $row['type']);
        $this->assertSame(3000.0, (float) $row['amount'], 'what the client paid');
        $this->assertSame(10.0, (float) $row['commission_rate']);
        $this->assertSame(300.0, (float) $row['platform_cut']);
        $this->assertSame(2700.0, (float) $row['owner_earning'], 'what the owner keeps');
    }

    public function test_the_final_payment_appears_as_its_own_row(): void
    {
        $booking = $this->bookingWithPaidAdvance('confirmed');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/bookings/{$booking->id}/balance-payment", ['method' => 'bank'])->assertCreated();

        $data = $this->ledger();

        $this->assertSame(2, $data['totals']['count']);
        $this->assertSame(10000.0, (float) $data['totals']['collected']);
        $this->assertSame(1000.0, (float) $data['totals']['platform_cut']);
        $this->assertSame(9000.0, (float) $data['totals']['earnings']);
    }

    public function test_money_that_could_still_be_refunded_is_not_listed(): void
    {
        // Advance paid, but the booking has not cleared both approvals - either
        // the admin or this owner could still reject and refund it.
        $this->bookingWithPaidAdvance('pending_owner_approval');

        $data = $this->ledger();

        $this->assertSame(0, $data['totals']['count']);
        $this->assertSame(0.0, (float) $data['totals']['collected']);
    }

    public function test_an_owner_never_sees_another_owners_money(): void
    {
        $otherOwner = User::create(['name' => 'Other', 'email' => 'other@test.com', 'password' => Hash::make('password'), 'role' => 'owner']);
        $otherBoard = $this->board('Someone Elses Board', $otherOwner);

        $this->bookingWithPaidAdvance('confirmed');
        $this->bookingWithPaidAdvance('confirmed', $otherBoard);

        $data = $this->ledger();

        $this->assertSame(1, $data['totals']['count']);
        $this->assertSame('Test Board', $data['transactions'][0]['billboard_title']);
        $this->assertSame(3000.0, (float) $data['totals']['collected']);
    }

    public function test_a_listing_fee_the_owner_paid_is_not_counted_as_revenue(): void
    {
        $this->bookingWithPaidAdvance('confirmed');

        // Money the owner paid the platform to list a board is an expense, not
        // something a board earned them.
        ListingPayment::create([
            'billboard_id' => $this->billboard->id, 'owner_id' => $this->owner->id,
            'amount' => 5000, 'status' => 'paid', 'paid_at' => now(),
        ]);

        $data = $this->ledger();

        $this->assertSame(1, $data['totals']['count']);
        $this->assertSame(3000.0, (float) $data['totals']['collected']);
    }

    public function test_the_total_matches_the_dashboards_revenue_tile(): void
    {
        $booking = $this->bookingWithPaidAdvance('confirmed');
        $this->bookingWithPaidAdvance('pending_owner_approval');   // not earned yet

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/bookings/{$booking->id}/balance-payment", ['method' => 'bank'])->assertCreated();

        Sanctum::actingAs($this->owner);
        $tile = collect($this->getJson('/api/owner/bookings')->assertOk()->json('data'))
            ->sum(fn (array $b) => (float) $b['collected_amount']);

        // The tile is the sum of the list behind it - if these ever diverge,
        // the owner is reading two different truths on the same screen.
        $this->assertSame(round($tile, 2), (float) $this->ledger()['totals']['collected']);
    }

    public function test_only_owners_can_open_it(): void
    {
        Sanctum::actingAs($this->client);
        $this->getJson('/api/owner/reports/transactions')->assertForbidden();

        $this->getJson('/api/owner/reports/transactions')->assertForbidden();
    }

    // ------------------------------------------------- where the money sits

    public function test_earnings_split_across_the_four_buckets(): void
    {
        // Verified and already disbursed -> paid out.
        $this->settledBooking('verified');
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/payouts/{$this->owner->id}", ['method' => 'bank'])->assertCreated();

        // Verified, but no payout run since -> ready.
        $this->settledBooking('verified');

        // Proof uploaded, sitting with the admin -> awaiting verification.
        $this->settledBooking('awaiting');

        // Paid in full but no proof uploaded, plus a booking whose client still
        // owes the balance -> in progress. Nothing here is waiting on the admin,
        // so neither may be called "awaiting verification".
        $this->settledBooking('no_proof');
        $this->bookingWithPaidAdvance('confirmed');

        $totals = $this->ledger()['totals'];

        $this->assertSame(9000.0, (float) $totals['paid_out']);
        $this->assertSame(9000.0, (float) $totals['ready_for_payout']);
        $this->assertSame(9000.0, (float) $totals['awaiting_verification'], 'only what the admin is holding up');
        $this->assertSame(11700.0, (float) $totals['in_progress'], '9,000 unproven + 2,700 advance-only');
        $this->assertSame(38700.0, (float) $totals['earnings']);
    }

    public function test_money_with_no_proof_uploaded_is_not_called_awaiting_verification(): void
    {
        // The exact complaint: paid in full, but the owner has not installed or
        // uploaded anything. The admin is not holding this up.
        $this->settledBooking('no_proof');

        $totals = $this->ledger()['totals'];

        $this->assertSame(0.0, (float) $totals['awaiting_verification']);
        $this->assertSame(9000.0, (float) $totals['in_progress']);
    }

    public function test_an_advance_only_booking_is_not_called_awaiting_verification(): void
    {
        // Client has paid the advance but still owes the balance - again, not
        // something the admin can act on.
        $this->bookingWithPaidAdvance('confirmed');

        $totals = $this->ledger()['totals'];

        $this->assertSame(0.0, (float) $totals['awaiting_verification']);
        $this->assertSame(2700.0, (float) $totals['in_progress']);
    }

    public function test_awaiting_verification_covers_exactly_the_awaiting_admin_tab(): void
    {
        $this->settledBooking('awaiting');
        $this->settledBooking('no_proof');
        $this->bookingWithPaidAdvance('confirmed');

        Sanctum::actingAs($this->owner);
        $bookings = collect($this->getJson('/api/owner/bookings')->assertOk()->json('data'));

        // The tab lists bookings in pending_proof_review; the Revenue page's
        // card is the owner's earnings on exactly those bookings.
        $tabBookingIds = $bookings->where('status', 'pending_proof_review')->pluck('id');
        $data = $this->ledger();

        $tabEarnings = collect($data['transactions'])
            ->whereIn('booking_id', $tabBookingIds)
            ->sum('owner_earning');

        $this->assertSame(1, $tabBookingIds->count());
        $this->assertSame(round((float) $tabEarnings, 2), (float) $data['totals']['awaiting_verification']);
    }

    public function test_money_walks_the_whole_flow_one_stage_at_a_time(): void
    {
        Storage::fake('public');

        // Paid in full, nothing uploaded: the owner's move, not the admin's.
        $booking = $this->settledBooking('no_proof');

        $totals = $this->ledger()['totals'];
        $this->assertSame(9000.0, (float) $totals['in_progress']);
        $this->assertSame(0.0, (float) $totals['awaiting_verification']);

        // The owner uploads the proof of installation.
        Sanctum::actingAs($this->owner);
        $this->post("/api/owner/bookings/{$booking->id}/proof", [
            'photos' => [UploadedFile::fake()->image('installed.jpg', 800, 600)],
        ])->assertCreated();

        $totals = $this->ledger()['totals'];
        $this->assertSame(0.0, (float) $totals['in_progress']);
        $this->assertSame(9000.0, (float) $totals['awaiting_verification'], 'now with the admin');
        $this->assertSame(0.0, (float) $totals['ready_for_payout'], 'not payable until admin accepts it');

        // Admin verifies the installation.
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/bookings/{$booking->id}/proof/verify")->assertOk();

        $totals = $this->ledger()['totals'];
        $this->assertSame(0.0, (float) $totals['awaiting_verification']);
        $this->assertSame(9000.0, (float) $totals['ready_for_payout'], 'payable the moment admin verifies');

        // ...and it is on the Payouts page the same moment.
        Sanctum::actingAs($this->owner);
        $this->assertSame(9000.0, (float) $this->getJson('/api/owner/payouts')->json('data.outstanding'));

        // Admin sends it.
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/payouts/{$this->owner->id}", ['method' => 'bank'])->assertCreated();

        $totals = $this->ledger()['totals'];
        $this->assertSame(0.0, (float) $totals['ready_for_payout']);
        $this->assertSame(9000.0, (float) $totals['paid_out'], 'only a payout fills this bucket');

        Sanctum::actingAs($this->owner);
        $this->assertSame(0.0, (float) $this->getJson('/api/owner/payouts')->json('data.outstanding'));
    }

    public function test_paid_out_always_equals_the_owners_payout_history(): void
    {
        $this->settledBooking('verified');
        $this->settledBooking('awaiting');
        $this->settledBooking('no_proof');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/payouts/{$this->owner->id}", ['method' => 'bank'])->assertCreated();

        Sanctum::actingAs($this->owner);
        $history = collect($this->getJson('/api/owner/payouts')->json('data.history'))
            ->sum(fn (array $payout) => (float) $payout['amount']);

        // Only the verified booking was payable, and "Paid out to you" is
        // exactly what the payout history says was sent - never more.
        $this->assertSame(9000.0, round($history, 2));
        $this->assertSame(round($history, 2), (float) $this->ledger()['totals']['paid_out']);
    }

    public function test_an_unverified_booking_is_never_payable(): void
    {
        // The rule underneath the whole flow: no verification, no payout.
        $this->settledBooking('awaiting');
        $this->settledBooking('no_proof');

        Sanctum::actingAs($this->owner);
        $this->assertSame(0.0, (float) $this->getJson('/api/owner/payouts')->json('data.outstanding'));
        $this->assertSame(0.0, (float) $this->ledger()['totals']['ready_for_payout']);
        $this->assertSame(0.0, (float) $this->ledger()['totals']['paid_out']);
    }

    public function test_the_four_buckets_always_add_back_up_to_earnings(): void
    {
        $this->settledBooking('verified');
        $this->settledBooking('awaiting');
        $this->settledBooking('no_proof');
        $this->bookingWithPaidAdvance('confirmed');

        $totals = $this->ledger()['totals'];

        $this->assertSame(
            round((float) $totals['earnings'], 2),
            round(
                (float) $totals['paid_out']
                + (float) $totals['ready_for_payout']
                + (float) $totals['awaiting_verification']
                + (float) $totals['in_progress'],
                2
            )
        );
    }

    public function test_a_payout_moves_money_from_ready_to_paid_out_without_touching_earnings(): void
    {
        $this->settledBooking('verified');

        $before = $this->ledger()['totals'];
        $this->assertSame(9000.0, (float) $before['ready_for_payout']);
        $this->assertSame(0.0, (float) $before['paid_out']);

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/payouts/{$this->owner->id}", ['method' => 'bank'])->assertCreated();

        $after = $this->ledger()['totals'];

        // The owner has been paid: the money changes bucket, the lifetime
        // earnings figure does not move, and the dashboard's "Awaiting payout"
        // (ready + held) drops to zero.
        $this->assertSame(0.0, (float) $after['ready_for_payout']);
        $this->assertSame(9000.0, (float) $after['paid_out']);
        $this->assertSame((float) $before['earnings'], (float) $after['earnings']);
    }

    public function test_the_ready_bucket_equals_the_payouts_page_outstanding_balance(): void
    {
        $this->settledBooking('verified');
        $this->settledBooking('awaiting');

        Sanctum::actingAs($this->owner);
        $outstanding = (float) $this->getJson('/api/owner/payouts')->assertOk()->json('data.outstanding');

        // Two pages, one number - if these drift the owner sees two truths.
        $this->assertSame($outstanding, (float) $this->ledger()['totals']['ready_for_payout']);
    }

    public function test_a_paid_out_row_carries_the_run_it_was_paid_in(): void
    {
        $this->settledBooking('verified');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/payouts/{$this->owner->id}", ['method' => 'bank', 'reference' => 'PAYOUT-TEST-1'])->assertCreated();

        $rows = collect($this->ledger()['transactions']);

        // Both rows of the booking - the advance AND the final payment - read
        // as paid out, even though only the advance row carries the payout_id.
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('paid_out', $row['payout_status']);
            $this->assertSame('PAYOUT-TEST-1', $row['payout_reference']);
            $this->assertNotNull($row['paid_out_at']);
        }
    }

    /**
     * A booking the client has paid in full, left at one of the three stages
     * that follow - each with the booking status the real flow would have set:
     *
     *   no_proof - paid_in_full, the owner has not uploaded anything yet
     *   awaiting - pending_proof_review, uploaded and sitting with the admin
     *   verified - active, the admin accepted it
     */
    private function settledBooking(string $stage): Booking
    {
        $booking = $this->bookingWithPaidAdvance(match ($stage) {
            'no_proof' => 'paid_in_full',
            'awaiting' => 'pending_proof_review',
            'verified' => 'active',
        });

        Payment::create([
            'booking_id' => $booking->id, 'payment_type' => 'balance', 'amount' => 7000,
            'status' => 'paid', 'commission_amount' => 0, 'owner_payable' => 7000, 'paid_at' => now(),
        ]);

        if ($stage !== 'no_proof') {
            ProofOfPosting::create([
                'booking_id' => $booking->id,
                'photo_path' => 'proof-of-posting/test.png',
                'status' => $stage === 'verified' ? 'verified' : 'pending',
                'verified_by' => $stage === 'verified' ? $this->admin->id : null,
                'verified_at' => $stage === 'verified' ? now() : null,
            ]);
        }

        return $booking;
    }

    private function ledger(): array
    {
        Sanctum::actingAs($this->owner);

        return $this->getJson('/api/owner/reports/transactions')->assertOk()->json('data');
    }

    private function board(string $title, User $owner): Billboard
    {
        return Billboard::create([
            'owner_id' => $owner->id, 'title' => $title, 'address' => 'Dhaka',
            'latitude' => 23.8, 'longitude' => 90.4, 'size' => '10ft x 8ft', 'type' => 'neon',
            'daily_rate' => 1000, 'pricing_mode' => 'daily', 'listing_status' => 'approved',
        ]);
    }

    /** A 10,000 booking with the 3,000 advance paid, commission frozen at 10%. */
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
