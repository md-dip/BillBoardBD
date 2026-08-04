<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Key/value store for business rules the booking math depends on:
     * commission_rate, advance_percentage, hold_minutes. Kept in the DB (not
     * hard-coded) so the admin can change the commission or advance % without
     * a redeploy, and so every price is computed from one source of truth.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();   // e.g. 'advance_percentage'
            $table->string('value');           // e.g. '30'
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};