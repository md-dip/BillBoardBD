<?php

namespace App\Http\Controllers;

use App\Models\Billboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillboardController extends Controller
{
    public function index()
    {
        $billboards = Billboard::all();

        return response()->json([
            'success' => true,
            'data' => $billboards,
        ]);
    }

    public function nearby(Request $request)
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['required', 'numeric', 'min:0.1', 'max:100'],
        ]);

        $lat = $validated['lat'];
        $lng = $validated['lng'];
        $radius = $validated['radius'];

        // Haversine formula: distance in km between two lat/lng points on Earth.
        // 6371 = Earth's mean radius in kilometers.
        // We compute distance in an inner subquery so WHERE can filter on it.
        $haversine = "(6371 * acos(
            cos(radians(?)) * cos(radians(latitude)) *
            cos(radians(longitude) - radians(?)) +
            sin(radians(?)) * sin(radians(latitude))
        ))";

        $billboards = DB::table(DB::raw("(
            SELECT *, {$haversine} AS distance_km
            FROM billboards
        ) AS b"))
            ->select('*')
            ->addBinding([$lat, $lng, $lat], 'select')
            ->where('distance_km', '<=', $radius)
            ->orderBy('distance_km')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $billboards,
        ]);
    }
}