<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mass Pay for customers (PR F2, decision 7): the unpaid invoices a customer chose to pay at once, paid through a gateway
 * as one amount on the invoice due first (the lead). No container invoice (WHMCS Mass Pay): when the money arrives, what
 * the lead cannot take pays the others in their order, as allocations of that payment (RecordPaymentService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_groups', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            // Who chose them: a login of the account (or staff in «Login as client»).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency_code', 3);
            // The invoice the gateway is paid on: due first.
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('total', 17, 2);
            $table->string('status')->default('open')->index();
            // The payment that paid it.
            $table->foreignId('invoice_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });

        Schema::create('payment_group_invoice', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            // What was left on it when the customer chose it.
            $table->decimal('amount', 17, 2);
            $table->unique(['payment_group_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_group_invoice');
        Schema::dropIfExists('payment_groups');
    }
};
