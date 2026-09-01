<?php

namespace Tests\Feature;

use App\Models\Billboard;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
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
            'permit_expiry_date' => '2027-01-01',
        ];

        $id = $this->postJson('/api/owner/billboards', $payload)->assertCreated()->json('data.id');
        $this->putJson("/api/owner/billboards/{$id}", ['daily_rate' => 3500] + $payload)->assertOk();
        $this->deleteJson("/api/owner/billboards/{$id}")->assertOk();
        $this->assertDatabaseMissing('billboards', ['id' => $id]);
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
