<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A WHMCS Mass Pay parent invoice, imported as a paid payment group (PR F3): its WHMCS id, so the number a customer
 * quotes (a transfer's reference) still finds the invoices it paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('whmcs_invoice_id')->nullable()->unique()->after('invoice_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_groups', function (Blueprint $table) {
            $table->dropUnique(['whmcs_invoice_id']);
            $table->dropColumn('whmcs_invoice_id');
        });
    }
};
