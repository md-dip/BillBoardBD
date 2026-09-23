<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 'booked' is no longer a valid billboards.status value - the status column
 * was never kept in sync with actual bookings (see Billboard::activeBookings()
 * for the real, date-based availability check), so it only ever misled owners
 * and admins into thinking a board was unavailable when it wasn't. Every board
 * is now either 'available' or 'hidden'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('billboards')->where('status', 'booked')->update(['status' => 'available']);
    }

    /**
     * Not reversible: which boards were 'booked' before this ran is not recorded.
     */
    public function down(): void
    {
        // no-op
    }
};
