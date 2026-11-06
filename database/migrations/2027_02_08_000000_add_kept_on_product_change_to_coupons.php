<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a coupon stays with a service when an upgrade changes its product (WHMCS «Lifetime Promotion», ADV-650-1):
 * on, as MyCS always kept it where it covers the new product; off, the new product renews at its full price, as WHMCS
 * does with a promotion that is not lifetime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('kept_on_product_change')->default(true)->after('once_per_client');
        });
    }
};
