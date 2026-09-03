<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\ListingPayment;
use App\Services\Owner\ListingFeeGateway;
use App\Services\Owner\ListingSubmissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Owner-actor checkout for the one-time board listing fee, through SSLCommerz
 * (hosted redirect flow). Mirrors Client\PaymentGatewayController.
 *
 *   checkout()  authenticated - the SPA asks for a GatewayPageURL and sends the
 *               browser there.
 *   success() / fail() / cancel()  public - SSLCommerz redirects the browser
 *               back here (form POST); we re-validate, settle, and bounce the
 *               browser into the SPA with ?listing=<result>.
 *   ipn()       public, server-to-server - same settlement, idempotent.
 */
class ListingPaymentController extends Controller
{
    public function __construct(
        private readonly ListingFeeGateway $gateway,
        private readonly ListingSubmissionService $submissions,
    ) {}

    /** SPA → { gateway_url }. Requires auth:sanctum + role:owner. */
    public function checkout(Request $request, ListingPayment $listingPayment): JsonResponse
    {
        if ($listingPayment->owner_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Forbidden: this is not your listing payment.',
            ], 403);
        }

        if ($listingPayment->status === 'paid') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This listing fee has already been paid.',
            ], 422);
        }

        try {
            $url = $this->gateway->startSession($listingPayment);
        } catch (Throwable $e) {
            Log::error('SSLCommerz listing session init failed', ['listing_payment_id' => $listingPayment->id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Could not reach the payment gateway. Please try again.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => ['gateway_url' => $url],
            'message' => null,
        ]);
    }

    /** Browser POST from SSLCommerz after a completed payment. */
    public function success(Request $request): RedirectResponse
    {
        $resolved = $this->resolveValidatedPayment($request);

        if ($resolved) {
            $this->submissions->markFeePaid($resolved['payment'], $this->gatewayAttributes($resolved['validation']));

            return $this->backToApp('success');
        }

        return $this->backToApp('failed');
    }

    /** Browser POST - payment declined / errored on the hosted page. */
    public function fail(Request $request): RedirectResponse
    {
        return $this->backToApp('failed');
    }

    /** Browser POST - customer backed out of the hosted page. */
    public function cancel(Request $request): RedirectResponse
    {
        return $this->backToApp('cancelled');
    }

    /** Server-to-server notification. Must always answer 200 so retries stop. */
    public function ipn(Request $request): Response
    {
        $resolved = $this->resolveValidatedPayment($request);

        if ($resolved) {
            $this->submissions->markFeePaid($resolved['payment'], $this->gatewayAttributes($resolved['validation']));
        }

        return response('OK');
    }

    /**
     * Tie a callback back to the listing payment that started it and confirm the
     * money really moved. Returns null (→ treated as a failed payment) on any
     * mismatch.
     *
     * @return array{payment: ListingPayment, validation: array<string, mixed>}|null
     */
    private function resolveValidatedPayment(Request $request): ?array
    {
        $paymentId = $request->input('value_a');
        $tranId = $request->input('tran_id');
        $valId = $request->input('val_id');

        if (! $paymentId || ! $tranId) {
            return null;
        }

        $payment = ListingPayment::query()
            ->where('id', $paymentId)
            ->where('gateway_tran_id', $tranId)
            ->first();

        if (! $payment) {
            Log::warning('SSLCommerz listing callback with unknown tran_id', ['tran_id' => $tranId, 'value_a' => $paymentId]);

            return null;
        }

        // Already settled by an earlier callback / the IPN - markFeePaid() will no-op.
        if ($payment->status === 'paid') {
            return ['payment' => $payment, 'validation' => []];
        }

        if (! $valId) {
            return null;
        }

        $v = $this->gateway->validate($valId);

        $statusOk = in_array(strtoupper($v['status'] ?? ''), ['VALID', 'VALIDATED'], true);
        $tranOk = ($v['tran_id'] ?? null) === $tranId;
        $currencyOk = strtoupper($v['currency'] ?? '') === 'BDT';
        $amountOk = abs(((float) ($v['amount'] ?? 0)) - (float) $payment->amount) < 0.01;

        if (! ($statusOk && $tranOk && $currencyOk && $amountOk)) {
            Log::warning('SSLCommerz listing validation rejected', [
                'listing_payment_id' => $payment->id,
                'tran_id' => $tranId,
                'status' => $v['status'] ?? null,
                'amount' => $v['amount'] ?? null,
                'currency' => $v['currency'] ?? null,
            ]);

            return null;
        }

        return ['payment' => $payment, 'validation' => $v];
    }

    /**
     * @param  array<string, mixed>  $v  the validation response
     * @return array<string, mixed>
     */
    private function gatewayAttributes(array $v): array
    {
        return [
            'method' => $this->normaliseMethod($v['card_type'] ?? null),
            'transaction_ref' => $v['bank_tran_id'] ?? ($v['tran_id'] ?? null),
            'gateway' => 'sslcommerz',
            'gateway_val_id' => $v['val_id'] ?? null,
            'gateway_payload' => $v ?: null,
        ];
    }

    private function normaliseMethod(?string $cardType): string
    {
        $t = strtolower((string) $cardType);

        return match (true) {
            str_contains($t, 'bkash') => 'bkash',
            str_contains($t, 'nagad') => 'nagad',
            str_contains($t, 'rocket'), str_contains($t, 'dbbl') => 'rocket',
            str_contains($t, 'visa') => 'visa',
            str_contains($t, 'master') => 'mastercard',
            str_contains($t, 'amex') => 'amex',
            $t !== '' => $t,
            default => 'sslcommerz',
        };
    }

    private function backToApp(string $status): RedirectResponse
    {
        $base = rtrim((string) config('services.sslcommerz.frontend_url'), '/');

        return redirect()->away($base.'/owner/billboards?listing='.$status);
    }
}
