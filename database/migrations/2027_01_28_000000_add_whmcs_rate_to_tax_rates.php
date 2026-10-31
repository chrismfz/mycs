<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rate WHMCS charged a country, as the import wrote it: a rate that still equals it was never touched here, so
 * `mycs:vat-rates:seed --update-imported` may bring it to the checked list (WHMCS's may be out of date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->decimal('whmcs_rate', 5, 2)->nullable()->after('rate');
        });
    }

    public function down(): void
    {
        Schema::table('tax_rates', function (Blueprint $table) {
            $table->dropColumn('whmcs_rate');
        });
    }
};
