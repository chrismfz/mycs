<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        });
    }

    public function down(): void
    {
        // period_start belongs to the domains spike's migration when that one ran first: leave it to it.
        $spikeOwnsStart = Schema::hasTable('migrations')
            && DB::table('migrations')->where('migration', 'like', '%add_period_start_to_invoice_items')->exists();

        Schema::table('invoice_items', function (Blueprint $table) use ($spikeOwnsStart) {
            $table->dropIndex('invoice_items_reference_period_index');
            $table->dropColumn($spikeOwnsStart ? ['period_end'] : ['period_start', 'period_end']);
        });
    }
};
