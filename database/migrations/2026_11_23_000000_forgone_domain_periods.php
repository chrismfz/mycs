<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Domains under Δ3 (D7a ADV-4, owner 2026-10-17): a domain's renewal line on a cancelled invoice counts as billed only if
 * its period was given up on (forgone_at), as a service's. Those cancelled before this counted as billed whatever their
 * date: they are marked, so the cron does not bill them now.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoice_items')
            ->where('reference_type', 'App\\Models\\Domain')->whereNull('kind')->whereNull('forgone_at')
            ->whereNotNull('period_start')->whereNotNull('period_end')
            ->whereIn('invoice_id', DB::table('invoices')->where('status', 'cancelled')->select('id'))
            ->update(['forgone_at' => DB::raw('COALESCE(updated_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        // Data only: the marks stay (they say what billedLine read before).
    }
};
