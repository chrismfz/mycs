<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A period given up on (Δ3, decided 2026-10-15): the renewal line of an invoice cancelled as such (by staff) or refunded
 * is marked, and the cron does not bill its period again (docs/billing-cursor.md §Δ3a). Lines already on such invoices
 * are marked too; not those the app cancelled because their service ended or changed cycle (their reason is one of
 * invoices.cancellation_reasons): a service that goes on is billed again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->timestamp('forgone_at')->nullable()->after('applied_at');
        });
        // An unpaid invoice kept as a debt when its service ended (Δ3a, «keep overdue invoices of ended services»): still
        // payable (client area, credit), not an anomaly for the money check.
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('kept_as_debt_at')->nullable()->after('cancellation_reason');
        });

        $appReasons = collect(['en', 'el'])->flatMap(fn (string $locale) => array_values((array) trans('invoices.cancellation_reasons', [], $locale)))
            ->filter(fn ($reason) => is_string($reason))->values()->all();
        DB::table('invoice_items')
            ->where('reference_type', 'App\\Models\\Service')
            ->whereNotNull('period_start')->whereNotNull('period_end')
            ->whereIn('invoice_id', DB::table('invoices')->whereIn('status', ['cancelled', 'refunded'])
                ->where(fn ($reason) => $reason->whereNull('cancellation_reason')->orWhereNotIn('cancellation_reason', $appReasons))
                ->select('id'))
            ->update(['forgone_at' => DB::raw('COALESCE(updated_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('forgone_at');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('kept_as_debt_at');
        });
    }
};
