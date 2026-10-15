<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHMCS «Separate Invoices» on the client (Δ3, PR C): its renewals are billed one invoice per service, not grouped by
 * due date (myip: 3 clients; nixpal: none).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('separate_invoices')->default(false)->after('tax_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('separate_invoices');
        });
    }
};
