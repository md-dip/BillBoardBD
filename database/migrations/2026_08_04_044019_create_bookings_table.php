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
    Schema::create('bookings', function (Blueprint $table) {
        $table->id();
        $table->foreignId('billboard_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->date('start_date');
        $table->date('end_date');
        $table->decimal('total_amount', 12, 2);    // money = decimal, never float
        $table->decimal('advance_amount', 12, 2);
        $table->string('status')->default('held');
        $table->text('rejection_reason')->nullable();

        // Campaign details (filled in step 2, so nullable at hold time)
        $table->string('brand_name')->nullable();
        $table->string('ad_category')->nullable();
        $table->text('campaign_description')->nullable();
        $table->string('creative_path')->nullable();

        // The 15-min slot lock: while this is in the future, dates are held
        $table->timestamp('expires_at')->nullable();

        $table->timestamps();
        $table->index(['billboard_id', 'status']); // we query this pair constantly
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
