<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A domain line without a period and without a kind is a «charge» (code review F1 of #174): it never counts as the
 * domain's renewal nor renews it. Lines made before say so too (adversarial ADV-8: none on the live sites at the time).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoice_items')->where('reference_type', 'App\\Models\\Domain')->whereNull('kind')
            ->whereNull('period_start')->whereNull('period_end')->update(['kind' => 'charge']);
    }

    public function down(): void
    {
        // Data only: they stay charges (a charge never billed or renewed anything).
    }
};
