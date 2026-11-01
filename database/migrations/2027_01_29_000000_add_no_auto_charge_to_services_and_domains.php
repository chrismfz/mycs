<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A service's or domain's own «Do not charge automatically» (owner 2026-11-01, #568): its renewal invoices are paid by
 * the customer, the account's other ones are still charged. Set by the customer on its page, by staff, and by the import
 * for an item WHMCS never charged that a saved method of its gateway would charge here (ADV-568-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['services', 'domains'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->boolean('no_auto_charge')->default(false)->after('payment_gateway_id')->index(); // AutoCharge reads the few set
            });
        }
    }

    public function down(): void
    {
        foreach (['services', 'domains'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['no_auto_charge']);
                $table->dropColumn('no_auto_charge');
            });
        }
    }
};
