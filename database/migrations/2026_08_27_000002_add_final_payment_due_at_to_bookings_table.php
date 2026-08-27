<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('bookings', 'final_payment_due_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->timestamp('final_payment_due_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'final_payment_due_at')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('final_payment_due_at');
            });
        }
    }
};
