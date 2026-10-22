<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A service's SSL certificate at Openprovider (extension OpenproviderSsl): one order per service, its status and dates as
 * Openprovider tells them (its product and years as paid, kept at payment) (the certificate's expiry, the paid order's end, its next reissue), the certificate, and what
 * the customer must publish to validate. `requested_at` is set before an order is asked for and cleared only by a
 * refusal: while it is set and no order is known, no second order is asked for; a lost answer's order is the one with this
 * exact `csr`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssl_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('domain_registrar_connections');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedInteger('product_id');
            $table->unsignedTinyInteger('period')->default(1);
            $table->string('common_name')->nullable();
            $table->json('host_names')->nullable();
            $table->text('csr')->nullable();
            $table->string('validation_method', 16)->nullable();
            $table->string('approver_email')->nullable();
            $table->string('organization_handle', 64)->nullable();
            $table->string('status', 16)->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('reissue_at')->nullable();
            $table->timestamp('order_ends_at')->nullable();
            $table->text('certificate')->nullable();
            $table->text('intermediate')->nullable();
            $table->json('validation')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssl_orders');
    }
};
