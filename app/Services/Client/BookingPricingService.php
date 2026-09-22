<?php

namespace App\Services\Client;

use App\Models\Billboard;
use App\Models\Setting;
use Illuminate\Support\Carbon;


class BookingPricingService
{
    /**
     * @return array{days: int, total: float, advance: float, balance: float}
     */
    public function calculate(Billboard $billboard, string $startDate, string $endDate): array
    {
        $days = Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) + 1;

        if ($billboard->pricing_mode === 'monthly') {
            $total = (float) $billboard->monthly_rate * ($days / 30);
        } else {
            $total = (float) $billboard->daily_rate * $days;
        }

        $advancePercentage = (float) Setting::get('advance_percentage', 30);
        $advance = $total * ($advancePercentage / 100);
        $balance = $total - $advance;

        return [
            'days' => $days,
            'total' => round($total, 2),
            'advance' => round($advance, 2),
            'balance' => round($balance, 2),
        ];
    }
}