<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the customer last opened a gateway's checkout on the invoice (SavedMethodCharge::openCheckout, sweep 3
 * L-NO-DOUBLE-CHARGE-2): the cron does not charge a saved method of it for CHECKOUT_HOLD_MINUTES after, as the checkout
 * may still take the same money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('checkout_opened_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('checkout_opened_at');
        });
    }
};
