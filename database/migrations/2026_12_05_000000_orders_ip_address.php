<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The address an order was placed from (WHMCS `tblorders.ipaddress`): staff see it on the order and in the «New Order»
 * email, to tell an odd or fraudulent order (owner 2026-10-19, Pending Orders as WHMCS). Set at the customer's
 * checkout, imported from WHMCS; none for an order staff create. 45: an IPv6 address in full.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('ip_address');
        });
    }
};
