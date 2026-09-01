<?php

namespace App\Services\Client;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SSLCommerz v4 hosted-checkout client.
 *
 * Two calls matter:
 *   startSession() - POST the order, get back a GatewayPageURL to redirect to.
 *   validate()     - after the callback, ask SSLCommerz whether the payment is
 *                    genuine. This is the authoritative check; the browser POST
 *                    to our success_url is never trusted on its own.
 *
 * No SDK / Composer package - just the HTTP client, so the surface stays small.
 */
class SslCommerzGateway
{
    private const SANDBOX_HOST = 'https://sandbox.sslcommerz.com';

    private const LIVE_HOST = 'https://securepay.sslcommerz.com';

    /**
     * Open a checkout session for a payment and return the hosted-page URL.
     * Persists the generated tran_id + session key onto the payment row so the
     * later callback can be tied back to exactly this attempt.
     */
    public function startSession(Payment $payment): string
    {
        $config = config('services.sslcommerz');

        if (empty($config['store_id']) || empty($config['store_passwd'])) {
            throw new RuntimeException('SSLCommerz store credentials are not configured.');
        }

        $payment->loadMissing('booking.billboard', 'booking.user');
        $booking = $payment->booking;
        $customer = $booking->user;

        $tranId = 'BBD-'.$payment->id.'-'.Str::upper(Str::random(12));

        $payload = [
            'store_id' => $config['store_id'],
            'store_passwd' => $config['store_passwd'],
            'total_amount' => number_format((float) $payment->amount, 2, '.', ''),
            'currency' => 'BDT',
            'tran_id' => $tranId,
            'value_a' => (string) $payment->id,          // passthrough - our payment id
            'value_b' => $payment->payment_type,

            'success_url' => url('/api/payments/sslcommerz/success'),
            'fail_url' => url('/api/payments/sslcommerz/fail'),
            'cancel_url' => url('/api/payments/sslcommerz/cancel'),
            'ipn_url' => url('/api/payments/sslcommerz/ipn'),

            'shipping_method' => 'NO',
            'product_name' => Str::limit('Billboard hire - '.($booking->billboard?->title ?? 'booking'), 250, ''),
            'product_category' => 'advertising',
            'product_profile' => 'general',
            'num_of_item' => 1,

            'cus_name' => $customer?->name ?: 'BillboardBD client',
            'cus_email' => $customer?->email ?: 'client@billboardbd.test',
            'cus_add1' => $booking->billboard?->address ?: 'Dhaka',
            'cus_city' => 'Dhaka',
            'cus_postcode' => '1230',
            'cus_country' => 'Bangladesh',
            'cus_phone' => $customer?->phone ?: '01700000000',
        ];

        $response = Http::asForm()
            ->post($this->host().'/gwprocess/v4/api.php', $payload)
            ->json() ?? [];

        if (($response['status'] ?? null) !== 'SUCCESS' || empty($response['GatewayPageURL'])) {
            throw new RuntimeException($response['failedreason'] ?? 'Could not start an SSLCommerz session.');
        }

        $payment->update([
            'gateway' => 'sslcommerz',
            'gateway_tran_id' => $tranId,
            'gateway_session_key' => $response['sessionkey'] ?? null,
        ]);

        return $response['GatewayPageURL'];
    }

    /**
     * Ask SSLCommerz to confirm a transaction by its validation id.
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

    private function host(): string
    {
        return config('services.sslcommerz.sandbox') ? self::SANDBOX_HOST : self::LIVE_HOST;
    }
}
