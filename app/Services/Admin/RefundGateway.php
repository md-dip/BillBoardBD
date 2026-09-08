<?php

namespace App\Services\Admin;

use App\Models\ListingPayment;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SSLCommerz v4 hosted-checkout client for the ADMIN paying a refund out by
 * hand. The third sibling of Client\SslCommerzGateway (booking payments) and
 * Owner\ListingFeeGateway (listing fees) - same actor split, same small surface
 * (start a session, validate a callback).
 *
 * The admin is the one sitting at the hosted page, but the customer details
 * sent to SSLCommerz are the RECIPIENT's - the client owed their advance back,
 * or the owner owed their listing fee. That is who the money is for, and it is
 * what makes the gateway's own record of the transaction readable later.
 *
 * Both refund kinds share one set of callback routes; `value_b` says which
 * table to settle, exactly as `value_a` says which row.
 */
class RefundGateway
{
    private const SANDBOX_HOST = 'https://sandbox.sslcommerz.com';

    private const LIVE_HOST = 'https://securepay.sslcommerz.com';

    public const BOOKING_REFUND = 'booking_refund';

    public const LISTING_REFUND = 'listing_refund';

    /**
     * Open a checkout session to refund a client's booking advance. Persists
     * the generated tran_id + session key onto the refund row so the later
     * callback can be tied back to exactly this attempt.
     */
    public function startBookingRefund(Payment $refund): string
    {
        $refund->loadMissing('booking.billboard', 'booking.user');
        $booking = $refund->booking;
        $client = $booking?->user;

        $tranId = 'BBD-RFND-'.$refund->id.'-'.Str::upper(Str::random(12));

        $session = $this->openSession([
            'amount' => (float) $refund->amount,
            'tran_id' => $tranId,
            'value_a' => (string) $refund->id,
            'value_b' => self::BOOKING_REFUND,
            'product_name' => 'Refund - advance on booking #'.$booking?->id.' - '.($booking?->billboard?->title ?? 'billboard'),
            'recipient_name' => $client?->name ?: 'BillboardBD client',
            'recipient_email' => $client?->email ?: 'client@billboardbd.test',
            'recipient_phone' => $client?->phone ?: '01700000000',
            'recipient_address' => $booking?->billboard?->address ?: 'Dhaka',
        ]);

        $refund->update([
            'gateway' => 'sslcommerz',
            'gateway_tran_id' => $tranId,
            'gateway_session_key' => $session['sessionkey'],
        ]);

        return $session['gateway_page_url'];
    }

    /**
     * Open a checkout session to refund an owner's board listing fee. Writes to
     * the refund_* columns so the owner's original payment reference - the
     * proof the fee was ever collected - is left untouched.
     */
    public function startListingRefund(ListingPayment $payment): string
    {
        $payment->loadMissing('billboard', 'owner');
        $billboard = $payment->billboard;
        $owner = $payment->owner;

        $tranId = 'BBD-RFND-LIST-'.$payment->id.'-'.Str::upper(Str::random(12));

        $session = $this->openSession([
            'amount' => (float) $payment->amount,
            'tran_id' => $tranId,
            'value_a' => (string) $payment->id,
            'value_b' => self::LISTING_REFUND,
            'product_name' => 'Refund - listing fee for '.($billboard?->title ?? 'board'),
            'recipient_name' => $owner?->name ?: 'BillboardBD owner',
            'recipient_email' => $owner?->email ?: 'owner@billboardbd.test',
            'recipient_phone' => $owner?->phone ?: '01700000000',
            'recipient_address' => $billboard?->address ?: 'Dhaka',
        ]);

        $payment->update([
            'refund_gateway_tran_id' => $tranId,
            'refund_gateway_session_key' => $session['sessionkey'],
        ]);

        return $session['gateway_page_url'];
    }

    /**
     * Ask SSLCommerz to confirm a transaction by its validation id. This is the
     * authoritative check; the browser POST back to our success_url is never
     * trusted on its own.
     *
     * @return array<string, mixed> the raw validation response
     */
    public function validate(string $valId): array
    {
        $config = config('services.sslcommerz');

        return Http::get($this->host().'/validator/api/validationserverAPI.php', [
            'val_id' => $valId,
            'store_id' => $config['store_id'],
            'store_passwd' => $config['store_passwd'],
            'v' => 1,
            'format' => 'json',
        ])->json() ?? [];
    }

    /**
     * The one POST that actually opens a hosted checkout, shared by both refund
     * kinds so their payloads can never drift apart.
     *
     * @param  array<string, mixed>  $refund
     * @return array{gateway_page_url: string, sessionkey: string|null}
     */
    private function openSession(array $refund): array
    {
        $config = config('services.sslcommerz');

        if (empty($config['store_id']) || empty($config['store_passwd'])) {
            throw new RuntimeException('SSLCommerz store credentials are not configured.');
        }

        $payload = [
            'store_id' => $config['store_id'],
            'store_passwd' => $config['store_passwd'],
            'total_amount' => number_format($refund['amount'], 2, '.', ''),
            'currency' => 'BDT',
            'tran_id' => $refund['tran_id'],
            'value_a' => $refund['value_a'],          // passthrough - the row to settle
            'value_b' => $refund['value_b'],          // passthrough - which table it is in

            'success_url' => url('/api/refunds/sslcommerz/success'),
            'fail_url' => url('/api/refunds/sslcommerz/fail'),
            'cancel_url' => url('/api/refunds/sslcommerz/cancel'),
            'ipn_url' => url('/api/refunds/sslcommerz/ipn'),

            'shipping_method' => 'NO',
            'product_name' => Str::limit($refund['product_name'], 250, ''),
            'product_category' => 'refund',
            'product_profile' => 'general',
            'num_of_item' => 1,

            'cus_name' => $refund['recipient_name'],
            'cus_email' => $refund['recipient_email'],
            'cus_add1' => $refund['recipient_address'],
            'cus_city' => 'Dhaka',
            'cus_postcode' => '1230',
            'cus_country' => 'Bangladesh',
            'cus_phone' => $refund['recipient_phone'],
        ];

        $response = Http::asForm()
            ->post($this->host().'/gwprocess/v4/api.php', $payload)
            ->json() ?? [];

        if (($response['status'] ?? null) !== 'SUCCESS' || empty($response['GatewayPageURL'])) {
            throw new RuntimeException($response['failedreason'] ?? 'Could not start an SSLCommerz session.');
        }

        return [
            'gateway_page_url' => $response['GatewayPageURL'],
            'sessionkey' => $response['sessionkey'] ?? null,
        ];
    }

    private function host(): string
    {
        return config('services.sslcommerz.sandbox') ? self::SANDBOX_HOST : self::LIVE_HOST;
    }
}
