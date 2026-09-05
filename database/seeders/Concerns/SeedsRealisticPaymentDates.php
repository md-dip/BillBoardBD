<?php

namespace Database\Seeders\Concerns;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Dates demo money the way a real booking pays it, instead of stamping every
 * row now().
 *
 * This matters well beyond the seeders: revenue is recognised on the date the
 * cash was collected (Admin\ReportController::ledger()), so payments all
 * stamped now() pile the platform's entire history into the current month and
 * the admin dashboard's revenue-by-month chart collapses into one bar, with
 * every earlier month showing nothing.
 *
 * A live booking pays in two moves, both tied to the campaign it buys:
 *
 *   advance - paid the moment the client submits the request, weeks before
 *             the artwork goes up.
 *   balance - settled just before the campaign starts, which is what
 *             final_payment_due_at is counting down to.
 *
 * Dating both off the campaign's start_date spreads the demo's revenue across
 * the months those campaigns were actually sold in. Nothing is ever dated in
 * the future: a campaign that has not started yet was still booked and paid
 * for today.
 */
trait SeedsRealisticPaymentDates
{
    /** Days before a campaign starts that the client submits it and pays the advance. */
    private const ADVANCE_LEAD_DAYS = 21;

    /** Days before a campaign starts that the remaining balance clears. */
    private const BALANCE_LEAD_DAYS = 3;

    /** Days after a rejected booking's advance lands that the refund goes back out. */
    private const REFUND_TURNAROUND_DAYS = 2;

    /**
     * The month the platform opened for business. No demo money is dated
     * before it, so the books start clean at a known point rather than
     * trailing off into a history the platform did not have.
     */
    private const PLATFORM_LAUNCH = '2026-07-01';

    private function advancePaidAt(string $startDate): Carbon
    {
        return $this->collectedBefore($startDate, self::ADVANCE_LEAD_DAYS);
    }

    private function balancePaidAt(string $startDate): Carbon
    {
        return $this->collectedBefore($startDate, self::BALANCE_LEAD_DAYS);
    }

    private function collectedBefore(string $startDate, int $leadDays): Carbon
    {
        $collectedAt = Carbon::parse($startDate)->subDays($leadDays);

        // A campaign booked for a future month was still paid for today.
        // Clamped to the start of the day so re-seeding lands on the same
        // date every time instead of nudging the row forward each run.
        if ($collectedAt->isFuture()) {
            $collectedAt = Carbon::today();
        }

        // Nothing predates the launch, whatever the campaign dates say.
        return $collectedAt->max(Carbon::parse(self::PLATFORM_LAUNCH));
    }

    /**
     * Writes the timeline onto a payment row that already exists.
     *
     * The seeders create their rows with firstOrCreate(), which never touches
     * an existing one, so re-running a seeder would otherwise leave every row
     * from the first run stamped with the now() it was created at.
     *
     * Two rows are deliberately left alone: anything still pending (no money
     * has been collected, so there is no date to write) and anything carrying
     * a transaction_ref, which only a real gateway payment has - re-seeding
     * must not rewrite the history of a payment someone actually made through
     * SSLCommerz.
     */
    private function redatePayment(Payment $payment, Carbon $collectedAt): void
    {
        if ($payment->status === 'pending' || $payment->transaction_ref !== null) {
            return;
        }

        $payment->forceFill([
            'paid_at' => $collectedAt,
            'refunded_at' => $payment->status === 'refunded'
                ? $collectedAt->copy()->addDays(self::REFUND_TURNAROUND_DAYS)
                : $payment->refunded_at,
        ])->save();
    }

    /**
     * Moves an invoice onto the date of the payment it was issued for.
     *
     * InvoiceService stamps issued_at with now(), which is right in the live
     * flow - it runs the instant the money clears - but leaves a seeded
     * invoice claiming it was raised months after the payment it bills for.
     * The payment's own date is passed in rather than the computed one, so an
     * invoice for a payment redatePayment() left alone still matches it.
     */
    private function redateInvoice(Invoice $invoice, ?Carbon $issuedAt): void
    {
        if (! $issuedAt) {
            return;
        }

        $invoice->forceFill(['issued_at' => $issuedAt])->save();
    }

    /**
     * Keeps the seeded payout runs on the far side of the payments they cover.
     *
     * Once the advances above move onto the campaign timeline, a payout with a
     * hardcoded date can end up settling money that had not been collected
     * yet. So each seeded run is re-dated from its own contents: the 10th of
     * the month after its latest covered payment, matching the "payouts settle
     * on the 10th" note the Payouts page already shows.
     *
     * Only the seeder's own runs are touched - they are the ones referenced
     * PAYOUT-YYYY-MM. A payout an admin made through the app carries the
     * reference that admin typed, and is left exactly as they entered it.
     */
    private function realignSeededPayouts(User $owner): void
    {
        $payouts = Payout::query()
            ->where('owner_id', $owner->id)
            ->where('reference', 'like', 'PAYOUT-%')
            ->with('payments')
            ->get();

        foreach ($payouts as $payout) {
            $latest = $payout->payments->max('paid_at');

            if (! $latest) {
                continue;
            }

            $settledAt = $this->payoutRunAfter($latest);

            $payout->forceFill([
                'paid_at' => $settledAt,
                'reference' => 'PAYOUT-'.$settledAt->format('Y-m'),
            ])->save();
        }
    }

    /** The 10th of the month after the given payment - never later than today. */
    private function payoutRunAfter(Carbon $collectedAt): Carbon
    {
        $settledAt = $collectedAt->copy()->addMonthNoOverflow()->startOfMonth()->setDay(10);

        return $settledAt->isFuture() ? Carbon::today() : $settledAt;
    }
}
