<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\ListingPayment;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\BillboardListingNotification;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end regression cover for the actor-wise backend reorg + the SSLCommerz
 * gateway. Runs on the in-memory sqlite test DB (phpunit.xml) - it never touches
 * the real pgsql database. Exercises every actor's route group and the full
 * booking lifecycle, so a broken namespace / DI wire / route surfaces here.
 */
class FullFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private User $owner;

    private Billboard $billboard;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(SettingSeeder::class);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@test.com', 'password' => Hash::make('password'), 'role' => 'admin']);
        $this->client = User::create(['name' => 'Client', 'email' => 'client@test.com', 'password' => Hash::make('password'), 'role' => 'client']);
        $this->owner = User::create(['name' => 'Owner', 'email' => 'owner@test.com', 'password' => Hash::make('password'), 'role' => 'owner']);

        $this->billboard = Billboard::create([
            'title' => 'Test Unipole', 'address' => 'Test Rd, Dhaka',
            'latitude' => 23.79, 'longitude' => 90.40, 'size' => '20ft x 10ft',
            'type' => 'unipole', 'pricing_mode' => 'monthly', 'daily_rate' => 0,
            'monthly_rate' => 180000, 'rating' => 4.5, 'status' => 'available',
            'owner_id' => $this->owner->id,
        ]);

        config([
            'services.sslcommerz.store_id' => 'test_store',
            'services.sslcommerz.store_passwd' => 'test_pass',
            'services.sslcommerz.sandbox' => true,
            'services.sslcommerz.frontend_url' => 'http://localhost:5173',
        ]);
    }

    /** Fake both SSLCommerz calls: session init + order validation. */
    private function fakeSslcommerz(): void
    {
        Http::fake(function (ClientRequest $req) {
            if (str_contains($req->url(), '/gwprocess/')) {
                return Http::response([
                    'status' => 'SUCCESS',
                    'GatewayPageURL' => 'https://sandbox.sslcommerz.com/EasyCheckOut/test-abc',
                    'sessionkey' => 'sess_'.uniqid(),
                ]);
            }
            if (str_contains($req->url(), '/validator/')) {
                $p = Payment::whereNotNull('gateway_tran_id')->where('status', '!=', 'paid')->latest('id')->first();

                return Http::response([
                    'status' => 'VALID',
                    'tran_id' => $p?->gateway_tran_id,
                    'val_id' => $req->data()['val_id'] ?? 'val_x',
                    'currency' => 'BDT',
                    'amount' => number_format((float) ($p?->amount ?? 0), 2, '.', ''),
                    'card_type' => 'VISA-Dutch Bangla Bank',
                    'bank_tran_id' => 'BNK'.uniqid(),
                ]);
            }

            return Http::response([]);
        });
    }

    /** Fake SSLCommerz for the owner listing-fee flow (resolves a ListingPayment). */
    private function fakeSslcommerzForListing(): void
    {
        Http::fake(function (ClientRequest $req) {
            if (str_contains($req->url(), '/gwprocess/')) {
                return Http::response([
                    'status' => 'SUCCESS',
                    'GatewayPageURL' => 'https://sandbox.sslcommerz.com/EasyCheckOut/test-list',
                    'sessionkey' => 'sess_'.uniqid(),
                ]);
            }
            if (str_contains($req->url(), '/validator/')) {
                $p = ListingPayment::whereNotNull('gateway_tran_id')->where('status', '!=', 'paid')->latest('id')->first();

                return Http::response([
                    'status' => 'VALID',
                    'tran_id' => $p?->gateway_tran_id,
                    'val_id' => $req->data()['val_id'] ?? 'val_x',
                    'currency' => 'BDT',
                    'amount' => number_format((float) ($p?->amount ?? 0), 2, '.', ''),
                    'card_type' => 'BKASH-Bkash',
                    'bank_tran_id' => 'BNK'.uniqid(),
                ]);
            }

            return Http::response([]);
        });
    }

    /** Owner checks the listing fee out through the gateway; drive the success callback. */
    private function payListingFeeViaGateway(int $listingPaymentId): void
    {
        Sanctum::actingAs($this->owner);

        $res = $this->postJson("/api/owner/listing-payments/{$listingPaymentId}/checkout")->assertOk();
        $this->assertNotEmpty($res->json('data.gateway_url'), 'listing checkout returned no gateway_url');

        $tranId = ListingPayment::find($listingPaymentId)->gateway_tran_id;
        $this->assertNotNull($tranId, 'listing checkout did not persist gateway_tran_id');

        $this->post('/api/listing-payments/sslcommerz/success', [
            'tran_id' => $tranId,
            'val_id' => 'val_'.$listingPaymentId,
            'value_a' => (string) $listingPaymentId,
        ])->assertRedirect('http://localhost:5173/owner/billboards?listing=success');

        $this->assertSame('paid', ListingPayment::find($listingPaymentId)->status);
    }

    /** Client checks a payment out through the gateway; drive the success callback. */
    private function payViaGateway(int $paymentId): void
    {
        Sanctum::actingAs($this->client);

        $res = $this->postJson("/api/payments/{$paymentId}/checkout")->assertOk();
        $this->assertNotEmpty($res->json('data.gateway_url'), 'checkout returned no gateway_url');

        $tranId = Payment::find($paymentId)->gateway_tran_id;
        $this->assertNotNull($tranId, 'checkout did not persist gateway_tran_id');

        // SSLCommerz POSTs back to the public (no-auth) callback.
        $this->post('/api/payments/sslcommerz/success', [
            'tran_id' => $tranId,
            'val_id' => 'val_'.$paymentId,
            'value_a' => (string) $paymentId,
        ])->assertRedirect('http://localhost:5173/dashboard?payment=success');

        $this->assertSame('paid', Payment::find($paymentId)->status);
    }

    // ---------------------------------------------------------------------

    public function test_public_and_auth_endpoints(): void
    {
        $this->getJson('/api/billboards')->assertOk();
        $this->getJson("/api/billboards/{$this->billboard->id}")->assertOk();
        $this->getJson('/api/settings/public')->assertOk();
        // NOTE: /api/billboards/nearby uses SQL acos()/radians() - works on the
        // real pgsql DB, not sqlite, so it is exercised in the manual/API smoke.

        // real login endpoint (single login, no actor switching)
        $login = $this->postJson('/api/login', ['email' => 'client@test.com', 'password' => 'password'])
            ->assertOk();
        $token = $login->json('data.token');
        $this->assertNotEmpty($token);

        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.email', 'client@test.com');
        $this->withToken($token)->getJson('/api/notifications')->assertOk();
        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->postJson('/api/register', [
            'name' => 'Fresh User', 'email' => 'fresh@test.com', 'password' => 'secret123', 'role' => 'client',
        ])->assertCreated()->assertJsonPath('data.user.email', 'fresh@test.com');
    }

    public function test_authorization_is_enforced(): void
    {
        $this->getJson('/api/bookings/my')->assertUnauthorized();          // no token -> 401

        Sanctum::actingAs($this->client);
        $this->getJson('/api/admin/bookings')->assertForbidden();          // wrong role -> 403
        $this->getJson('/api/owner/payouts')->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/admin/settings')->assertForbidden();
    }

    public function test_owner_and_admin_read_endpoints(): void
    {
        Sanctum::actingAs($this->owner);
        foreach (['/api/owner/billboards', '/api/owner/bookings', '/api/owner/payouts'] as $url) {
            $this->assertSame(200, $this->getJson($url)->status(), "GET $url (owner)");
        }

        Sanctum::actingAs($this->admin);
        foreach ([
            '/api/admin/billboards', '/api/admin/bookings', '/api/admin/settings',
            '/api/admin/payouts', '/api/admin/ping',
        ] as $url) {
            $this->assertSame(200, $this->getJson($url)->status(), "GET $url (admin)");
        }
        // NOTE: /api/admin/reports/{revenue,occupancy} use Postgres-only SQL
        // (to_char) - green on the real DB, not runnable on sqlite; covered by
        // the manual/API smoke instead.
    }

    public function test_owner_billboard_crud(): void
    {
        Sanctum::actingAs($this->owner);
        $payload = [
            'title' => 'New Owner Board', 'address' => 'Rd 5, Dhaka',
            'latitude' => 23.8, 'longitude' => 90.41, 'size' => '10ft x 8ft',
            'type' => 'neon', 'daily_rate' => 3000, 'pricing_mode' => 'daily',
            'permit_expiry_date' => now()->addYear()->toDateString(),
            'photo' => UploadedFile::fake()->image('board.jpg', 800, 600),
            'permit_document' => UploadedFile::fake()->create('permit.pdf', 120, 'application/pdf'),
        ];

        // Listing a board now creates it as pending_payment with a pending fee.
        $created = $this->post('/api/owner/billboards', $payload)->assertCreated()->json('data');
        $id = $created['billboard']['id'];
        $this->assertSame('pending_payment', $created['billboard']['listing_status']);
        $this->assertDatabaseHas('listing_payments', [
            'id' => $created['listing_payment']['id'], 'billboard_id' => $id, 'status' => 'pending', 'amount' => '5000.00',
        ]);
        // It must not be publicly visible yet.
        $this->getJson('/api/billboards')->assertOk()->assertJsonMissing(['id' => $id]);
        $this->getJson("/api/billboards/{$id}")->assertNotFound();

        $this->putJson("/api/owner/billboards/{$id}", ['daily_rate' => 3500])->assertOk();
        $this->deleteJson("/api/owner/billboards/{$id}")->assertOk();
        $this->assertDatabaseMissing('billboards', ['id' => $id]);
    }

    public function test_owner_listing_fee_flow_approve_makes_board_public(): void
    {
        $this->fakeSslcommerzForListing();

        Sanctum::actingAs($this->owner);
        $created = $this->post('/api/owner/billboards', [
            'title' => 'Paid Listing Board', 'address' => 'Rd 9, Dhaka',
            'latitude' => 23.81, 'longitude' => 90.42, 'size' => '20ft x 10ft',
            'type' => 'unipole', 'daily_rate' => 4000, 'pricing_mode' => 'daily',
            'permit_expiry_date' => now()->addYear()->toDateString(),
            'photo' => UploadedFile::fake()->image('b.jpg'),
            'permit_document' => UploadedFile::fake()->create('p.pdf', 90, 'application/pdf'),
        ])->assertCreated()->json('data');

        $billboardId = $created['billboard']['id'];
        $this->payListingFeeViaGateway($created['listing_payment']['id']);

        // Fee paid -> board is now awaiting admin review + admins were notified.
        $this->assertSame('pending_review', Billboard::find($billboardId)->listing_status);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->admin->id, 'type' => BillboardListingNotification::class,
        ]);

        // Admin approves -> board goes live.
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/billboards/{$billboardId}/approve")->assertOk();
        $this->assertSame('approved', Billboard::find($billboardId)->listing_status);

        $this->getJson('/api/billboards')->assertOk()->assertJsonFragment(['id' => $billboardId]);
        $this->getJson("/api/billboards/{$billboardId}")->assertOk();
    }

    public function test_rejected_listing_refunds_the_fee(): void
    {
        $this->fakeSslcommerzForListing();

        Sanctum::actingAs($this->owner);
        $created = $this->post('/api/owner/billboards', [
            'title' => 'Doomed Board', 'address' => 'Rd 11, Dhaka',
            'latitude' => 23.82, 'longitude' => 90.43, 'size' => '20ft x 10ft',
            'type' => 'unipole', 'daily_rate' => 4000, 'pricing_mode' => 'daily',
            'permit_expiry_date' => now()->addYear()->toDateString(),
            'photo' => UploadedFile::fake()->image('b.jpg'),
            'permit_document' => UploadedFile::fake()->create('p.pdf', 90, 'application/pdf'),
        ])->assertCreated()->json('data');

        $billboardId = $created['billboard']['id'];
        $feeId = $created['listing_payment']['id'];
        $this->payListingFeeViaGateway($feeId);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/billboards/{$billboardId}/reject", [
            'rejection_reason' => 'Permit document is not legible.',
        ])->assertOk();

        $this->assertSame('rejected', Billboard::find($billboardId)->listing_status);
        $this->assertSame('refunded', ListingPayment::find($feeId)->status);
        $this->assertNotNull(ListingPayment::find($feeId)->refunded_at);
        $this->getJson('/api/billboards')->assertOk()->assertJsonMissing(['id' => $billboardId]);
    }

    public function test_full_booking_lifecycle_with_gateway_and_invoices(): void
    {
        $this->fakeSslcommerz();

        // 1. hold
        Sanctum::actingAs($this->client);
        $bookingId = $this->postJson('/api/bookings/hold', [
            'billboard_id' => $this->billboard->id,
            'start_date' => now()->addDays(5)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ])->assertCreated()->json('data.id');
        $this->assertSame('held', Booking::find($bookingId)->status);

        // 2. campaign + creative
        $this->post("/api/bookings/{$bookingId}/campaign", [
            'brand_name' => 'Acme', 'ad_category' => 'Retail',
            'campaign_description' => 'Launch campaign for the new store.',
            'creative' => UploadedFile::fake()->image('ad.jpg', 800, 600),
        ])->assertOk();
        $this->assertSame('pending_payment', Booking::find($bookingId)->status);

        $advance = Payment::where('booking_id', $bookingId)->where('payment_type', 'advance')->firstOrFail();

        // 3. pay advance through SSLCommerz
        $this->payViaGateway($advance->id);
        $this->assertSame('pending_admin_review', Booking::find($bookingId)->status);
        $this->assertDatabaseHas('invoices', ['booking_id' => $bookingId, 'kind' => 'advance']);

        // 4. admin approve -> owner
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/bookings/{$bookingId}/approve")->assertOk();
        $this->assertSame('pending_owner_approval', Booking::find($bookingId)->status);

        // 5. owner accept -> confirmed + balance row
        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/owner/bookings/{$bookingId}/approve")->assertOk();
        $booking = Booking::find($bookingId);
        $this->assertSame('confirmed', $booking->status);
        $this->assertNotNull($booking->final_payment_due_at);
        $balance = Payment::where('booking_id', $bookingId)->where('payment_type', 'balance')->firstOrFail();

        // 6. pay balance -> paid in full + final invoice
        $this->payViaGateway($balance->id);
        $this->assertSame('paid_in_full', Booking::find($bookingId)->status);
        $this->assertDatabaseHas('invoices', ['booking_id' => $bookingId, 'kind' => 'final']);

        // 7. client invoice hides the commission split
        Sanctum::actingAs($this->client);
        $clientInv = $this->getJson("/api/bookings/{$bookingId}/invoice")->assertOk()->json('data');
        $this->assertSame('final', $clientInv['kind']);
        $this->assertArrayHasKey('amount_paid', $clientInv);
        $this->assertArrayHasKey('balance_due', $clientInv);
        $this->assertArrayNotHasKey('commission_amount', $clientInv);

        // 8. admin invoice shows it
        Sanctum::actingAs($this->admin);
        $adminInv = $this->getJson("/api/admin/bookings/{$bookingId}/invoice")->assertOk()->json('data');
        $this->assertArrayHasKey('commission_amount', $adminInv);
        $this->assertArrayHasKey('owner_payable', $adminInv);

        // 9. owner proof -> admin verify -> active
        Sanctum::actingAs($this->owner);
        $this->post("/api/owner/bookings/{$bookingId}/proof", [
            'photos' => [UploadedFile::fake()->image('proof.jpg')],
        ])->assertCreated();
        $this->assertSame('pending_proof_review', Booking::find($bookingId)->status);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/bookings/{$bookingId}/proof/verify")->assertOk();
        $this->assertSame('active', Booking::find($bookingId)->status);

        // 10. owner payout
        $this->postJson("/api/admin/payouts/{$this->owner->id}")->assertSuccessful();
    }

    public function test_rejected_booking_refunds_the_advance(): void
    {
        $this->fakeSslcommerz();

        Sanctum::actingAs($this->client);
        $bookingId = $this->postJson('/api/bookings/hold', [
            'billboard_id' => $this->billboard->id,
            'start_date' => now()->addDays(20)->toDateString(),
            'end_date' => now()->addDays(25)->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->post("/api/bookings/{$bookingId}/campaign", [
            'brand_name' => 'Beta', 'ad_category' => 'Tech',
            'campaign_description' => 'A short campaign description here.',
            'creative' => UploadedFile::fake()->image('c.png'),
        ])->assertOk();

        $advance = Payment::where('booking_id', $bookingId)->where('payment_type', 'advance')->firstOrFail();
        $this->payViaGateway($advance->id);

        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/bookings/{$bookingId}/reject", [
            'rejection_reason' => 'Dates clash with scheduled maintenance work.',
        ])->assertOk();

        $this->assertSame('rejected', Booking::find($bookingId)->status);
        $this->assertSame('refunded', Payment::find($advance->id)->status);
        $this->assertDatabaseHas('payments', ['booking_id' => $bookingId, 'payment_type' => 'refund']);
    }

    public function test_owner_declined_booking_refunds_the_advance(): void
    {
        $this->fakeSslcommerz();

        // client hold -> campaign -> pay advance via gateway
        Sanctum::actingAs($this->client);
        $bookingId = $this->postJson('/api/bookings/hold', [
            'billboard_id' => $this->billboard->id,
            'start_date' => now()->addDays(30)->toDateString(),
            'end_date' => now()->addDays(35)->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->post("/api/bookings/{$bookingId}/campaign", [
            'brand_name' => 'Delta', 'ad_category' => 'Retail',
            'campaign_description' => 'Owner-decline refund scenario campaign.',
            'creative' => UploadedFile::fake()->image('d.png'),
        ])->assertOk();

        $advance = Payment::where('booking_id', $bookingId)->where('payment_type', 'advance')->firstOrFail();
        $this->payViaGateway($advance->id);

        // admin approve -> forwards to the owner's tab
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/admin/bookings/{$bookingId}/approve")->assertOk();
        $this->assertSame('pending_owner_approval', Booking::find($bookingId)->status);

        // owner rejects with a reason
        Sanctum::actingAs($this->owner);
        $this->patchJson("/api/owner/bookings/{$bookingId}/reject", [
            'rejection_reason' => 'The site is already committed to another campaign.',
        ])->assertOk();

        // booking terminal, advance flipped to refunded with a timestamp
        $this->assertSame('rejected', Booking::find($bookingId)->status);
        $refundedAdvance = Payment::find($advance->id);
        $this->assertSame('refunded', $refundedAdvance->status);
        $this->assertNotNull($refundedAdvance->refunded_at);

        // dedicated audit 'refund' payment row exists (full parity with admin reject)
        $this->assertDatabaseHas('payments', ['booking_id' => $bookingId, 'payment_type' => 'refund']);

        // the client's notification wording mentions the refund
        $bodies = $this->client->notifications()->get()->pluck('data.body');
        $this->assertTrue(
            $bodies->contains(fn ($b) => str_contains((string) $b, 'refunded')),
            'client should get a BookingStatusNotification mentioning the refund',
        );
    }

    public function test_gateway_callback_rejects_a_tampered_amount(): void
    {
        Http::fake(function (ClientRequest $req) {
            if (str_contains($req->url(), '/gwprocess/')) {
                return Http::response(['status' => 'SUCCESS', 'GatewayPageURL' => 'https://x/pay', 'sessionkey' => 's']);
            }

            // validation says a different amount was paid -> must not settle
            return Http::response(['status' => 'VALID', 'tran_id' => 'whatever', 'currency' => 'BDT', 'amount' => '1.00']);
        });

        Sanctum::actingAs($this->client);
        $bookingId = $this->postJson('/api/bookings/hold', [
            'billboard_id' => $this->billboard->id,
            'start_date' => now()->addDays(40)->toDateString(),
            'end_date' => now()->addDays(45)->toDateString(),
        ])->json('data.id');
        $this->post("/api/bookings/{$bookingId}/campaign", [
            'brand_name' => 'Gamma', 'ad_category' => 'Food',
            'campaign_description' => 'Another campaign description string.',
            'creative' => UploadedFile::fake()->image('g.jpg'),
        ])->assertOk();
        $advance = Payment::where('booking_id', $bookingId)->where('payment_type', 'advance')->firstOrFail();

        $this->postJson("/api/payments/{$advance->id}/checkout")->assertOk();
        $tranId = Payment::find($advance->id)->gateway_tran_id;

        $this->post('/api/payments/sslcommerz/success', [
            'tran_id' => $tranId, 'val_id' => 'v', 'value_a' => (string) $advance->id,
        ])->assertRedirect('http://localhost:5173/dashboard?payment=failed');

        $this->assertSame('pending', Payment::find($advance->id)->status);
        $this->assertSame('pending_payment', Booking::find($bookingId)->status);
    }

    public function test_database_seeder_runs_clean(): void
    {
        // RefreshDatabase already migrated; setUp() added a few rows. The full
        // seeder must run on top without error (seeders are idempotent).
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'client@test.com']);
        $this->assertGreaterThan(1, Billboard::count());
        $this->assertGreaterThan(0, Booking::count());   // OwnerDemoSeeder builds the pipeline
    }
}
