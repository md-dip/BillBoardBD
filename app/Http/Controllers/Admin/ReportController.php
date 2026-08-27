<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Billboard;
use App\Models\Booking;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function revenue(): JsonResponse
    {
        $rows = DB::table('payments')
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->join('billboards', 'billboards.id', '=', 'bookings.billboard_id')
            ->where('payments.payment_type', 'advance')
            ->where('payments.status', 'paid')
            ->selectRaw(
                "billboards.id as billboard_id, billboards.title as billboard_title, ".
                "to_char(payments.created_at, 'YYYY-MM') as month, ".
                'SUM(bookings.total_amount) as gross, '.
                'SUM(payments.commission_amount) as commission, '.
                'SUM(payments.owner_payable) as owner_payable'
            )
            ->groupBy('billboards.id', 'billboards.title', DB::raw("to_char(payments.created_at, 'YYYY-MM')"))
            ->orderBy('month')
            ->get();

        $totals = [
            'gross' => round((float) $rows->sum('gross'), 2),
            'commission' => round((float) $rows->sum('commission'), 2),
            'owner_payable' => round((float) $rows->sum('owner_payable'), 2),
        ];

        return response()->json([
            'success' => true,
            'data' => ['rows' => $rows, 'totals' => $totals],
            'message' => null,
        ]);
    }

    public function occupancy(): JsonResponse
    {
        $bookings = Booking::query()->whereIn('status', ['confirmed', 'paid_in_full', 'pending_proof_review', 'active'])->get();

        $bookedDays = [];

        foreach ($bookings as $booking) {
            $period = CarbonPeriod::create($booking->start_date, $booking->end_date);

            foreach ($period as $date) {
                $month = $date->format('Y-m');
                $bookedDays[$booking->billboard_id][$month] = ($bookedDays[$booking->billboard_id][$month] ?? 0) + 1;
            }
        }

        $billboards = Billboard::query()->whereIn('id', array_keys($bookedDays))->pluck('title', 'id');

        $result = [];

        foreach ($bookedDays as $billboardId => $months) {
            foreach ($months as $month => $days) {
                $daysInMonth = Carbon::createFromFormat('Y-m', $month)->daysInMonth;

                $result[] = [
                    'billboard_id' => $billboardId,
                    'billboard_title' => $billboards[$billboardId] ?? null,
                    'month' => $month,
                    'booked_days' => $days,
                    'days_in_month' => $daysInMonth,
                    'occupancy_rate' => round(($days / $daysInMonth) * 100, 1),
                ];
            }
        }

        usort($result, fn ($a, $b) => $a['month'] <=> $b['month']);

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => null,
        ]);
    }
}