<?php

namespace App\Services\Owner;

use App\Models\ListingPayment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SSLCommerz v4 hosted-checkout client for the owner's one-time board listing
 * fee. A deliberate sibling of App\Services\Client\SslCommerzGateway - the
 * actor split keeps an Owner service from depending on a Client one, and the
 * surface is small (start a session, validate a callback).
 */
class ListingFeeGateway
{
    private const SANDBOX_HOST = 'https://sandbox.sslcommerz.com';

    private const LIVE_HOST = 'https://securepay.sslcommerz.com';

    /**
     * Open a checkout session for a listing payment and return the hosted-page
     * URL. Persists the generated tran_id + session key so the later callback
     * can be tied back to exactly this attempt.
     */
    public function startSession(ListingPayment $payment): string
    {
        $config = config('services.sslcommerz');

        if (empty($config['store_id']) || empty($config['store_passwd'])) {
            throw new RuntimeException('SSLCommerz store credentials are not configured.');
        }

        $payment->loadMissing('billboard', 'owner');
        $billboard = $payment->billboard;
        $owner = $payment->owner;

        $tranId = 'BBD-LIST-'.$payment->id.'-'.Str::upper(Str::random(12));

        $payload = [
            'store_id' => $config['store_id'],
            'store_passwd' => $config['store_passwd'],
            'total_amount' => number_format((float) $payment->amount, 2, '.', ''),
            'currency' => 'BDT',
            'tran_id' => $tranId,
            'value_a' => (string) $payment->id,          // passthrough - our listing payment id
            'value_b' => 'listing_fee',

            'success_url' => url('/api/listing-payments/sslcommerz/success'),
            'fail_url' => url('/api/listing-payments/sslcommerz/fail'),
            'cancel_url' => url('/api/listing-payments/sslcommerz/cancel'),
            'ipn_url' => url('/api/listing-payments/sslcommerz/ipn'),

            'shipping_method' => 'NO',
            'product_name' => Str::limit('Billboard listing fee - '.($billboard?->title ?? 'board'), 250, ''),
            'product_category' => 'listing',
            'product_profile' => 'general',
            'num_of_item' => 1,

            'cus_name' => $owner?->name ?: 'BillboardBD owner',
            'cus_email' => $owner?->email ?: 'owner@billboardbd.test',
            'cus_add1' => $billboard?->address ?: 'Dhaka',
            'cus_city' => 'Dhaka',
            'cus_postcode' => '1230',
            'cus_country' => 'Bangladesh',
            'cus_phone' => $owner?->phone ?: '01700000000',
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
