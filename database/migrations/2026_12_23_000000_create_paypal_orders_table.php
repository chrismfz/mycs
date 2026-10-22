<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PayPal orders this site made (S0-6): only these are captured or recorded here, and only while their payee is still
 * the one PayPal set when they were made. Kept in the database, not the cache: the app flushes its cache (a currency
 * saved, a catalogue import), and an approved order must still be taken (ADV-4 of #328).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paypal_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 64)->unique();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->string('payee_merchant_id', 64)->nullable();
            $table->string('payee_email', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_orders');
    }
};
