<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('billboards', 'permit_expiry_date')) {
            Schema::table('billboards', function (Blueprint $table) {
                $table->date('permit_expiry_date')->nullable();
            });

            // Seed a reasonable default so the Permits page has something to show
            \DB::table('billboards')->whereNull('permit_expiry_date')->update([
                'permit_expiry_date' => now()->addYear()->toDateString(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('billboards', 'permit_expiry_date')) {
            Schema::table('billboards', function (Blueprint $table) {
                $table->dropColumn('permit_expiry_date');
            });
        }
    }
};