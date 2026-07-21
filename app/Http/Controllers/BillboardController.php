<?php

namespace App\Http\Controllers;

use App\Models\Billboard;

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
}