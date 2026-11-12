<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A gateway's own list of transactions as gateways:reconcile read it (owner 2026-11-12; docs/payments-tracking.md),
 * each with whether this site has it recorded the same: a transaction a row, by the gateway's id. Ids, dates and
 * amounts only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 64);
            $table->string('what', 16);
            $table->string('reference', 100);
            $table->string('kind', 24);
            $table->dateTime('occurred_at');
            $table->decimal('amount', 12, 2);
            $table->string('currency_code', 3)->nullable();
            $table->string('agreement', 100)->nullable();
            $table->string('parent_reference', 100)->nullable();
            // What this site has for it.
            $table->decimal('recorded_amount', 12, 2)->nullable();
            $table->string('recorded_currency', 3)->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('status', 16)->default('ok')->index();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'what', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_transactions');
    }
};
