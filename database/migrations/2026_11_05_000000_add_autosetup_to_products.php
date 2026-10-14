<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a product's service is set up (Σ1-b, WHMCS's tblproducts.autosetup): on order, on payment, when staff accept the
 * order, or never (staff do it). Both companies use all four (myip: shared cPanel on order, dedicated and colocation
 * never, SSL on accept); every product so far is set up on payment, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('autosetup', 8)->default('payment')->after('server_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('autosetup');
        });
    }
};
