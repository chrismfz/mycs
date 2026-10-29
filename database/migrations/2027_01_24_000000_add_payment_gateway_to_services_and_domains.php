<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each service's and domain's own payment method, as WHMCS's tblhosting/tbldomains.paymentmethod (owner 2026-10-29):
 * its renewals are invoiced apart, with it preferred, and a saved method of another gateway never charges them. None:
 * the account's (its saved method, as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['services', 'domains'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                // SQLite adds a foreign key by rebuilding the table, which drops domains' generated live_fqdn (its one live
                // row per name): there, a plain column (gateways are soft-deleted, the key never acts).
                if ($name === 'domains' && Schema::getConnection()->getDriverName() === 'sqlite') {
                    $table->unsignedBigInteger('payment_gateway_id')->nullable()->after('currency_code')->index();

                    return;
                }
                $table->foreignId('payment_gateway_id')->nullable()->after('currency_code')->constrained('extensions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['services', 'domains'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                if ($name === 'domains' && Schema::getConnection()->getDriverName() === 'sqlite') {
                    $table->dropIndex(['payment_gateway_id']);
                    $table->dropColumn('payment_gateway_id');

                    return;
                }
                $table->dropConstrainedForeignId('payment_gateway_id');
            });
        }
    }
};
