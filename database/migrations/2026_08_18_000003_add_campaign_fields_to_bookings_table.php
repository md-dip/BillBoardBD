<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (!Schema::hasColumn('bookings', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
            if (!Schema::hasColumn('bookings', 'brand_name')) {
                $table->string('brand_name')->nullable();
            }
            if (!Schema::hasColumn('bookings', 'ad_category')) {
                $table->string('ad_category')->nullable();
            }
            if (!Schema::hasColumn('bookings', 'campaign_description')) {
                $table->text('campaign_description')->nullable();
            }
            if (!Schema::hasColumn('bookings', 'creative_path')) {
                $table->string('creative_path')->nullable();
            }
            if (!Schema::hasColumn('bookings', 'expires_at')) {
                $table->timestamp('expires_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // No-op — safe rollback
    }
};