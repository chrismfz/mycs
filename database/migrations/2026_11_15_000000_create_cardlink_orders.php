<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The orders sent to the Cardlink/Eurobank vPOS (PR H2a, docs/payments.md «Cardlink»): what each order id pays (the
 * invoice, the customer, a Mass Pay group, the amount), so its return pays that and nothing else, and what the vPOS
 * answered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cardlink_orders', function (Blueprint $table) {
            $table->id();
            // The vPOS order id («<invoice>Inv<ymdHis><2 digits>»), unique per terminal; the payment's transaction id.
            $table->string('order_id', 50)->unique();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('payment_group', 26)->nullable(); // a Mass Pay group's ulid
            $table->decimal('amount', 17, 2);
            $table->string('currency_code', 3);
            // The last status the vPOS returned (CAPTURED, REFUSED, CANCELED, ERROR, AUTHORIZED); null: no return yet.
            $table->string('status', 20)->nullable();
            $table->string('tx_id', 64)->nullable();
            $table->string('pay_method', 30)->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cardlink_orders');
    }
};
