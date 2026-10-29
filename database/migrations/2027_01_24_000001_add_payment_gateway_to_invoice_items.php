<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payment method a line's service or domain had when it was billed (#541, round 2): a saved method of another gateway
 * never charges its invoice, whatever the item says later (an open invoice keeps the way it was made with). None: the
 * account's. A plain column: a gateway is soft-deleted, and a deleted one counts as none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_gateway_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn('payment_gateway_id');
        });
    }
};
