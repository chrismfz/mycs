<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a service line's payment was applied to its service (docs/billing-cursor.md): processing the
 * same paid line again (an invoice flipped paid -> pending -> paid) buys nothing, so it must not
 * unsuspend a service either. Null for lines never processed (and for the WHMCS import).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->timestamp('applied_at')->nullable()->after('period_end');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('applied_at');
        });
    }
};
