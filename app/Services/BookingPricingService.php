<?php

namespace App\Services;

use App\Models\Billboard;
use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Single source of truth for booking price math, so the amount a hold is
 * created with can never drift from the formula used anywhere else. Amounts
 * are always computed here, server-side, and never trusted from the client.
 */
class BookingPricingService
{
    /**
     * @return array{days: int, total: float, advance: float}
     */
    public function calculate(Billboard $billboard, string $startDate, string $endDate): array
    {
        // Inclusive of both ends: a one-day booking (start == end) is 1 day.
        $days = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;

        if ($billboard->pricing_mode === 'monthly') {
            $total = (float) $billboard->monthly_rate * ($days / 30);
        } else {
            $total = (float) $billboard->daily_rate * $days;
        }

        // Advance % lives in the settings table, never hard-coded.
        $advancePercentage = (float) Setting::get('advance_percentage', 30);
        $advance = $total * ($advancePercentage / 100);

        return [
            'days' => $days,
            'total' => round($total, 2),
            'advance' => round($advance, 2),
        ];
    }
}