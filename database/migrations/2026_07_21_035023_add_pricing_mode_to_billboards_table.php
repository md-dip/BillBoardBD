<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
         Schema::table('billboards', function (Blueprint $table) {
            $table->string('pricing_mode')->default('daily')->after('daily_rate');
            $table->decimal('monthly_rate', 12, 2)->nullable()->after('pricing_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
         Schema::table('billboards', function (Blueprint $table) {
            $table->dropColumn(['pricing_mode', 'monthly_rate']);
        });
    }
};
