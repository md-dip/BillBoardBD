<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner-submitted billboards now go through a paid, admin-reviewed listing
     * flow. `listing_status` drives public visibility (only 'approved' boards
     * show on the map/list); the rest track the review + permit paperwork.
     * Additive + nullable; every pre-existing board is backfilled to 'approved'
     * so nothing disappears.
     */
    public function up(): void
    {
        Schema::table('billboards', function (Blueprint $table) {
            if (! Schema::hasColumn('billboards', 'listing_status')) {
                // pending_payment -> pending_review -> approved | rejected
                $table->string('listing_status')->default('approved')->index()->after('status');
            }
            if (! Schema::hasColumn('billboards', 'permit_document')) {
                $table->string('permit_document')->nullable()->after('permit_expiry_date');
            }
            if (! Schema::hasColumn('billboards', 'listing_rejection_reason')) {
                $table->text('listing_rejection_reason')->nullable()->after('permit_document');
            }
            if (! Schema::hasColumn('billboards', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('listing_rejection_reason');
            }
            if (! Schema::hasColumn('billboards', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('submitted_at');
            }
            if (! Schema::hasColumn('billboards', 'reviewed_by')) {
                $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            }
        });

        // Everything that already exists was, by definition, already live.
        DB::table('billboards')->update(['listing_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('billboards', function (Blueprint $table) {
            if (Schema::hasColumn('billboards', 'reviewed_by')) {
                $table->dropConstrainedForeignId('reviewed_by');
            }
            foreach (['listing_status', 'permit_document', 'listing_rejection_reason', 'submitted_at', 'reviewed_at'] as $column) {
                if (Schema::hasColumn('billboards', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
