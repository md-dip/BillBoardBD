<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Billboard;
use App\Models\Booking;
use App\Models\ListingPayment;
use App\Models\Payment;
use App\Services\Admin\ListingRefundService;
use App\Services\Admin\RefundGateway;
use App\Services\Shared\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin-actor checkout for paying a refund out by hand, through SSLCommerz
 * (hosted redirect flow). Mirrors Client\PaymentGatewayController and
 * Owner\ListingPaymentController.
 *
 *   bookingCheckout() / listingCheckout()
 *               authenticated, admin only - the SPA asks for a GatewayPageURL
 *               and sends the browser there.
 *   success() / fail() / cancel()  public - SSLCommerz redirects the browser
 *               back here (form POST); we re-validate, settle, and bounce the
 *               browser back into the admin panel with ?refund=<result>.
 *   ipn()       public, server-to-server - same settlement, idempotent.
 *
 * Both refund kinds share these callbacks; `value_b` says which one came back.
 */
class RefundController extends Controller
{
    public function __construct(
        private readonly RefundGateway $gateway,
        private readonly RefundService $refunds,
        private readonly ListingRefundService $listingRefunds,
    ) {}

    /** SPA -> { gateway_url } for a rejected booking's advance. */
    public function bookingCheckout(Booking $booking): JsonResponse
    {
        $refund = $this->refunds->pendingRefundFor($booking);

        if (! $refund) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'There is no refund outstanding on this booking.',
            ], 422);
        }

        return $this->startSession(
            fn () => $this->gateway->startBookingRefund($refund),
            ['booking_id' => $booking->id, 'refund_payment_id' => $refund->id],
        );
    }

    /** SPA -> { gateway_url } for a rejected board's listing fee. */
    public function listingCheckout(Billboard $billboard): JsonResponse
    {
        $payment = $this->listingRefunds->pendingRefundFor($billboard);

        if (! $payment) {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'There is no listing fee outstanding on this board.',
            ], 422);
        }

        return $this->startSession(
            fn () => $this->gateway->startListingRefund($payment),
            ['billboard_id' => $billboard->id, 'listing_payment_id' => $payment->id],
        );
    }

    /** Browser POST from SSLCommerz after a completed refund. */
    public function success(Request $request): RedirectResponse
    {
        $resolved = $this->resolveValidatedRefund($request);

        if (! $resolved) {
            return $this->backToApp($request, 'failed');
        }

        $this->settle($resolved);

        return $this->backToApp($request, 'success');
    }

    /** Browser POST - the refund was declined / errored on the hosted page. */
    public function fail(Request $request): RedirectResponse
    {
        return $this->backToApp($request, 'failed');
    }

    /** Browser POST - admin backed out of the hosted page. */
    public function cancel(Request $request): RedirectResponse
    {
        return $this->backToApp($request, 'cancelled');
    }

    /** Server-to-server notification. Must always answer 200 so retries stop. */
    public function ipn(Request $request): Response
    {
        if ($resolved = $this->resolveValidatedRefund($request)) {
            $this->settle($resolved);
        }

        return response('OK');
    }

    /**
     * Open the hosted session, turning a gateway outage into a 502 rather than
     * a stack trace. Shared by both checkout endpoints.
     *
     * @param  callable(): string  $open
     * @param  array<string, mixed>  $context  what to log if it fails
     */
    private function startSession(callable $open, array $context): JsonResponse
    {
        try {
            $url = $open();
        } catch (Throwable $e) {
            Log::error('SSLCommerz refund session init failed', [...$context, 'error' => $e->getMessage()]);

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

    /**
     * Hand the settled row to whichever service owns it. Both are idempotent,
     * so a callback racing the IPN is a no-op the second time.
     *
     * @param  array{kind: string, row: Payment|ListingPayment, validation: array<string, mixed>}  $resolved
     */
    private function settle(array $resolved): void
    {
        if ($resolved['kind'] === RefundGateway::LISTING_REFUND) {
            $this->listingRefunds->markRefunded(
                $resolved['row'],
                $this->listingGatewayAttributes($resolved['validation']),
            );

            return;
        }

        $this->refunds->markRefunded(
            $resolved['row'],
            $this->gatewayAttributes($resolved['validation']),
        );
    }

    /**
     * Tie a callback back to the refund that started it and confirm the money
     * really moved. Returns null (treated as a failed refund) on any mismatch.
     *
     * @return array{kind: string, row: Payment|ListingPayment, validation: array<string, mixed>}|null
     */
    private function resolveValidatedRefund(Request $request): ?array
    {
        $rowId = $request->input('value_a');
        $kind = $request->input('value_b');
        $tranId = $request->input('tran_id');
        $valId = $request->input('val_id');

        if (! $rowId || ! $tranId || ! in_array($kind, [RefundGateway::BOOKING_REFUND, RefundGateway::LISTING_REFUND], true)) {
            return null;
        }

        $row = $kind === RefundGateway::LISTING_REFUND
            ? ListingPayment::query()->where('id', $rowId)->where('refund_gateway_tran_id', $tranId)->first()
            : Payment::query()->where('id', $rowId)->where('payment_type', 'refund')->where('gateway_tran_id', $tranId)->first();

        if (! $row) {
            Log::warning('SSLCommerz refund callback with unknown tran_id', [
                'tran_id' => $tranId, 'value_a' => $rowId, 'value_b' => $kind,
            ]);

            return null;
        }

        // Already settled by an earlier callback / the IPN - nothing left to
        // validate, and markRefunded() would no-op anyway.
        if ($row->status === 'refunded') {
            return ['kind' => $kind, 'row' => $row, 'validation' => []];
        }

        if (! $valId) {
            return null;
        }

        $v = $this->gateway->validate($valId);

        $statusOk = in_array(strtoupper($v['status'] ?? ''), ['VALID', 'VALIDATED'], true);
        $tranOk = ($v['tran_id'] ?? null) === $tranId;
        $currencyOk = strtoupper($v['currency'] ?? '') === 'BDT';
        $amountOk = abs(((float) ($v['amount'] ?? 0)) - (float) $row->amount) < 0.01;

        if (! ($statusOk && $tranOk && $currencyOk && $amountOk)) {
            Log::warning('SSLCommerz refund validation rejected', [
                'value_a' => $rowId,
                'value_b' => $kind,
                'tran_id' => $tranId,
                'status' => $v['status'] ?? null,
                'amount' => $v['amount'] ?? null,
                'currency' => $v['currency'] ?? null,
            ]);

            return null;
        }

        return ['kind' => $kind, 'row' => $row, 'validation' => $v];
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

    /**
     * The same columns for a listing fee, prefixed - a board has one payment
     * row and the owner's original references have to survive on it.
     *
     * @param  array<string, mixed>  $v  the validation response
     * @return array<string, mixed>
     */
    private function listingGatewayAttributes(array $v): array
    {
        return [
            'refund_method' => $this->normaliseMethod($v['card_type'] ?? null),
            'refund_transaction_ref' => $v['bank_tran_id'] ?? ($v['tran_id'] ?? null),
            'refund_gateway_val_id' => $v['val_id'] ?? null,
            'refund_gateway_payload' => $v ?: null,
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

    /**
     * Back into the admin panel, on the page the refund was started from - the
     * Rejected bookings tab, or the board Listing refunds tab.
     */
    private function backToApp(Request $request, string $status): RedirectResponse
    {
        $base = rtrim((string) config('services.sslcommerz.frontend_url'), '/');

        $page = $request->input('value_b') === RefundGateway::LISTING_REFUND
            ? '/admin/billboards'
            : '/admin/bookings';

        return redirect()->away($base.$page.'?refund='.$status);
    }
}
