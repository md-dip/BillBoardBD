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
    Schema::create('payments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
        $table->decimal('amount', 12, 2);
        $table->string('payment_type');              // 'advance' or 'balance'
        $table->string('method')->nullable();        // bkash/nagad/bank/cash — null until paid
        $table->string('transaction_ref')->nullable();
        $table->string('status')->default('pending');
        $table->decimal('commission_amount', 12, 2); // platform's cut, frozen here
        $table->decimal('owner_payable', 12, 2);     // what the billboard owner gets
        $table->timestamp('paid_at')->nullable();
        $table->timestamp('refunded_at')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
