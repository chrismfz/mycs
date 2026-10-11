<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The billing period a service line pays for (docs/billing-cursor.md). period_start may already
 * exist from the domains spike (spike/domains), so it is added only when missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_items', 'period_start')) {
                $table->date('period_start')->nullable()->after('description');
            }
            $table->date('period_end')->nullable()->after('period_start');
            $table->index(['reference_type', 'reference_id', 'period_start'], 'invoice_items_reference_period_index');
            // The morph index is a prefix of the new one.
            $table->dropIndex('invoice_items_reference_type_reference_id_index');
        });
    }

    public function down(): void
    {
        // This migration owns period_start too (the domains spike's own down() is a no-op).
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->index(['reference_type', 'reference_id'], 'invoice_items_reference_type_reference_id_index');
            $table->dropIndex('invoice_items_reference_period_index');
            $table->dropColumn(['period_start', 'period_end']);
        });
    }
};
