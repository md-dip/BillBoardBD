<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'payout_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreignId('payout_id')->nullable()->after('owner_payable')->constrained()->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'payout_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('payout_id');
            });
        }
    }
};
