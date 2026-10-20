<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Promotions as WHMCS's (nixpal: FREETRIAL, 25OffAgentNode, MOBIAN10/25): a price override (packages/pricing), for new or
// existing customers only, once per customer, requiring a product (in the order, or held already), on some billing cycles.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->string('customers', 16)->default('all'); // all | new | existing
            $table->boolean('once_per_client')->default(false);
            $table->boolean('requires_existing')->default(false);
            $table->json('billing_cycles')->nullable(); // ["month:1", "year:1", …]; null: every cycle
        });
        Schema::create('coupon_required_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unique(['coupon_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_required_products');
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn(['customers', 'once_per_client', 'requires_existing', 'billing_cycles']);
        });
    }
};
