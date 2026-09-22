<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Billboard;
use App\Models\Booking;
use App\Services\Admin\AdminPanelCalculationService;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    public function __construct(private readonly AdminPanelCalculationService $calculations) {}

    /**
     * Platform money, per billboard per month
     */
    public function revenue(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->calculations->revenueSummary(),
            'message' => null,
        ]);
    }


    public function transactions(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->calculations->transactionsList(),
            'message' => null,
        ]);
    }

    /**
     * The admin Dashboard's four KPI tiles in one call: total revenue,
     * platform commission, pending bookings and permits expiring soon.
     *
     * The actual calculation lives in Services\Admin\AdminPanelCalculationService
     * (dashboardSummary()) - this method only shapes the HTTP response.
     */
    public function dashboard(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->calculations->dashboardSummary(),
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
