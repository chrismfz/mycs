<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Δ1 (docs/decisions.md, Σ7b): amounts are net and each line carries its tax, as in WHMCS (`TaxPerLineItem`).
 * - products.taxable: WHMCS `tblproducts.tax` (a product sold without VAT).
 * - clients.tax_exempt: WHMCS `tblclients.taxexempt`.
 * - invoice_items: taxed, tax_rate (percent, the one in force when the line was made), tax_amount and line_total (net ×
 *   quantity + tax), rounded per line. A line made before keeps the total it had: tax 0, line_total = price × quantity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('taxable')->default(true);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('tax_exempt')->default(false);
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->boolean('taxed')->default(true);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 17, 2)->default(0);
            $table->decimal('line_total', 17, 2)->default(0);
        });

        DB::table('invoice_items')->update(['line_total' => DB::raw('ROUND(price * quantity, 2)')]);
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['taxed', 'tax_rate', 'tax_amount', 'line_total']);
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('tax_exempt');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('taxable');
        });
    }
};
