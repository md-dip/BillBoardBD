<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The one-time board listing fee an owner pays through SSLCommerz when they
     * submit a new billboard for review. Separate from `payments` (which is
     * booking-coupled) - same money/gateway column shape so the owner checkout
     * flow can mirror the client one without entangling the two.
     */
    public function up(): void
    {
        Schema::create('listing_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending'); // pending | paid | refunded
            $table->string('method')->nullable();
            $table->string('transaction_ref')->nullable();

            $table->string('gateway')->nullable();                        // 'sslcommerz'
            $table->string('gateway_tran_id')->nullable()->unique();      // our unique tran_id
            $table->string('gateway_val_id')->nullable();                 // SSLCommerz val_id
            $table->string('gateway_session_key')->nullable();
            $table->json('gateway_payload')->nullable();                  // raw validation response

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_payments');
    }
};
