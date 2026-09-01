<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('number')->unique();               // INV-000042-A / INV-000042-F
            $table->string('kind');                           // 'advance' | 'final'

            // Everything below is frozen at issue time - an invoice is a
            // historical document, it must not shift if the admin later
            // changes the commission rate or a new payment lands.
            $table->decimal('subtotal', 12, 2);              // full booking total
            $table->decimal('commission_rate', 5, 2);        // % applied, from settings at pay time
            $table->decimal('commission_amount', 12, 2);
            $table->decimal('owner_payable', 12, 2);
            $table->decimal('amount_paid', 12, 2);           // cumulative paid when issued
            $table->decimal('balance_due', 12, 2);           // subtotal - amount_paid
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique(['booking_id', 'kind']);          // one advance + one final per booking
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
