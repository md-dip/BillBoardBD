<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A rejected board's listing fee is now refunded by the admin by hand,
     * through a second SSLCommerz checkout, rather than being flipped to
     * 'refunded' the instant the rejection is saved.
     *
     * That second trip to the gateway has its own tran_id, val_id and bank
     * reference, and they cannot share the columns the OWNER's original payment
     * already fills - overwriting `gateway_tran_id` would lose the reference
     * that proves the fee was ever collected. So the refund leg gets its own
     * set of columns alongside them.
     *
     * Bookings need none of this: a booking refund is already a second row in
     * `payments` (payment_type = 'refund'), which carries the gateway columns
     * it inherited from the table.
     */
    public function up(): void
    {
        Schema::table('listing_payments', function (Blueprint $table) {
            $table->string('refund_method')->nullable()->after('refunded_at');
            $table->string('refund_transaction_ref')->nullable()->after('refund_method');
            $table->string('refund_gateway_tran_id')->nullable()->unique()->after('refund_transaction_ref');
            $table->string('refund_gateway_val_id')->nullable()->after('refund_gateway_tran_id');
            $table->string('refund_gateway_session_key')->nullable()->after('refund_gateway_val_id');
            $table->json('refund_gateway_payload')->nullable()->after('refund_gateway_session_key');
        });
    }

    public function down(): void
    {
        Schema::table('listing_payments', function (Blueprint $table) {
            $table->dropColumn([
                'refund_method', 'refund_transaction_ref', 'refund_gateway_tran_id',
                'refund_gateway_val_id', 'refund_gateway_session_key', 'refund_gateway_payload',
            ]);
        });
    }
};
