<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\Billboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BillboardController extends Controller
{
    public function index()
    {
        // Only admin-approved listings are public.
        $billboards = Billboard::query()->where('listing_status', 'approved')->get();

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
        $haversine = '(6371 * acos(
            cos(radians(?)) * cos(radians(latitude)) *
            cos(radians(longitude) - radians(?)) +
            sin(radians(?)) * sin(radians(latitude))
        ))';

        $billboards = DB::table(DB::raw("(
            SELECT *, {$haversine} AS distance_km
            FROM billboards
        ) AS b"))
            ->select('*')
            ->addBinding([$lat, $lng, $lat], 'select')
            ->where('listing_status', 'approved')
            ->where('distance_km', '<=', $radius)
            ->orderBy('distance_km')
            ->get()
            // raw rows skip the Eloquent accessor, so mirror photo_url here too
            ->map(function ($b) {
                $b->photo_url = match (true) {
                    empty($b->photo) => null,
                    str_starts_with($b->photo, 'http'), str_starts_with($b->photo, '/') => $b->photo,
                    default => Storage::disk('public')->url($b->photo),
                };

                return $b;
            });

        return response()->json([
            'success' => true,
            'data' => $billboards,
        ]);
    }

    public function show(Billboard $billboard)
    {
        // A board that isn't approved yet must not be reachable by URL.
        if ($billboard->listing_status !== 'approved') {
            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Billboard not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $billboard,
        ]);
    }
}
