<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\PaymentCompletionService;
use App\Services\SslCommerzGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Real client-actor checkout through SSLCommerz (hosted redirect flow).
 *
 *   checkout()  authenticated - the SPA asks for a GatewayPageURL and sends the
 *               browser there.
 *   success() / fail() / cancel()  public - SSLCommerz redirects the browser
 *               back here (form POST); we re-validate, settle, and bounce the
 *               browser into the SPA with ?payment=<result>.
 *   ipn()       public, server-to-server - same settlement, idempotent.
 */
class PaymentGatewayController extends Controller
{
    public function __construct(
        private readonly SslCommerzGateway $gateway,
        private readonly PaymentCompletionService $completion,
    ) {}

    /** SPA → { gateway_url }. Requires auth:sanctum. */
    public function checkout(Request $request, Payment $payment): JsonResponse
    {
        if ($payment->booking->user_id !== $request->user()->id) {
            return $this->fail403();
        }

        if ($payment->status === 'paid') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This payment has already been paid.',
            ], 422);
        }

        if (! in_array($payment->payment_type, ['advance', 'balance'], true)) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'This payment cannot be paid online.',
            ], 422);
        }

        try {
            $url = $this->gateway->startSession($payment);
        } catch (Throwable $e) {
            Log::error('SSLCommerz session init failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);

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
            $this->completion->markPaid($resolved['payment'], $this->gatewayAttributes($resolved['validation']));

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
            $this->completion->markPaid($resolved['payment'], $this->gatewayAttributes($resolved['validation']));
        }

        return response('OK');
    }

    /**
     * Tie a callback back to the payment that started it and confirm the money
     * really moved. Returns null (→ treated as a failed payment) on any mismatch.
     *
     * @return array{payment: Payment, validation: array<string, mixed>}|null
     */
    private function resolveValidatedPayment(Request $request): ?array
    {
        $paymentId = $request->input('value_a');
        $tranId = $request->input('tran_id');
        $valId = $request->input('val_id');

        if (! $paymentId || ! $tranId) {
            return null;
        }

        $payment = Payment::query()
            ->where('id', $paymentId)
            ->where('gateway_tran_id', $tranId)
            ->first();

        if (! $payment) {
            Log::warning('SSLCommerz callback with unknown tran_id', ['tran_id' => $tranId, 'value_a' => $paymentId]);

            return null;
        }

        // Already settled by an earlier callback / the IPN - nothing to validate,
        // markPaid() will no-op.
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
            Log::warning('SSLCommerz validation rejected', [
                'payment_id' => $payment->id,
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

        return redirect()->away($base.'/dashboard?payment='.$status);
    }

    private function fail403(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data' => null,
            'message' => 'Forbidden: this is not your payment.',
        ], 403);
    }
}
