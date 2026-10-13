<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The gateway log (docs/money-trail.md §4, MT-2a): one row for every request that reaches a gateway's routes
 * (extensions.gateways.*), with what came of it. Field names only, never values or secrets. Kept forever (§6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 50)->index();
            $table->string('endpoint', 100);
            $table->string('method', 10);
            $table->string('event_type', 100)->nullable();
            $table->json('fields')->nullable();
            $table->boolean('signature_verified')->nullable();
            $table->string('outcome', 20)->default('received')->index();
            $table->string('reason', 50)->nullable();
            $table->decimal('amount', 17, 2)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->string('external_id')->nullable()->index();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->unsignedBigInteger('invoice_transaction_id')->nullable()->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_events');
    }
};
