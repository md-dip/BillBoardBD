<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Real payment-gateway (SSLCommerz) bookkeeping. All additive and nullable -
     * a payment stays 'pending' through checkout and only flips to 'paid' after
     * the callback is re-validated server-side, so the existing status values
     * are untouched.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('gateway')->nullable()->after('method');              // 'sslcommerz'
            $table->string('gateway_tran_id')->nullable()->unique()->after('gateway'); // our unique tran_id
            $table->string('gateway_val_id')->nullable()->after('gateway_tran_id');    // SSLCommerz val_id
            $table->string('gateway_session_key')->nullable()->after('gateway_val_id');
            $table->json('gateway_payload')->nullable()->after('gateway_session_key'); // raw validation response
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['gateway_tran_id']);
            $table->dropColumn([
                'gateway', 'gateway_tran_id', 'gateway_val_id',
                'gateway_session_key', 'gateway_payload',
            ]);
        });
    }
};
