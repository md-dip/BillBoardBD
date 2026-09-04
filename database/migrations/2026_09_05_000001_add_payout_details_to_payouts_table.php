<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payouts', 'payout_details')) {
            Schema::table('payouts', function (Blueprint $table) {
                // Snapshot of the owner's payout account (method / account name /
                // number / bank / branch) captured at pay time, so a historical
                // receipt never re-renders from the live users row the owner can
                // edit at any moment. Additive, nullable, no backfill.
                $table->json('payout_details')->nullable()->after('note');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payouts', 'payout_details')) {
            Schema::table('payouts', function (Blueprint $table) {
                $table->dropColumn('payout_details');
            });
        }
    }
};
