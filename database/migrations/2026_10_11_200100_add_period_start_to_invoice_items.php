<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The period a renewal item pays for starts here (the billing cursor). Paymenter
 * advanced services.expires_at on payment without remembering which period an invoice
 * covered; domain renewals need it to adopt instead of renewing twice (docs/domains-spike.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->date('period_start')->nullable()->after('reference_type');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('period_start');
        });
    }
};
