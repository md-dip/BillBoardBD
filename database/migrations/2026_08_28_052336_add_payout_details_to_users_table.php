<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an owner wants their payouts sent - set by the owner
     * themselves on the Owner Payouts page, read by admin when they
     * manually trigger a payout so they know where to send the money.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('payout_method')->nullable()->after('phone'); // bkash | nagad | bank
            $table->string('payout_account_name')->nullable()->after('payout_method');
            $table->string('payout_account_number')->nullable()->after('payout_account_name');
            $table->string('payout_bank_name')->nullable()->after('payout_account_number');
            $table->string('payout_branch')->nullable()->after('payout_bank_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'payout_method', 'payout_account_name', 'payout_account_number',
                'payout_bank_name', 'payout_branch',
            ]);
        });
    }
};
