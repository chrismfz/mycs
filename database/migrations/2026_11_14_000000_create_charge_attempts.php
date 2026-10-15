<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Charges of a saved payment method (PR H, docs/payments.md «Αυτόματη χρέωση»): one row per attempt, written under the
 * payment locks before the gateway is called. Its key is the gateway's idempotency key (Stripe Idempotency-Key, PayPal
 * PayPal-Request-Id); a started attempt holds the invoice, so a second click or the cron cannot charge it twice (PAY-3).
 * The agreement counts the failures in a row (decision 5: optional cap).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('key')->unique();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_agreement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_group_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 17, 2);
            $table->string('currency_code', 3);
            // cron, client, change (a billing cycle change), group (Mass Pay)
            $table->string('source', 16);
            // started, succeeded, failed, unknown (no answer: a timeout, a 5xx), action_required
            $table->string('status', 16);
            $table->string('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'started_at']);
        });

        Schema::table('billing_agreements', function (Blueprint $table) {
            $table->unsignedInteger('failed_count')->default(0)->after('expiry');
            $table->timestamp('last_failed_at')->nullable()->after('failed_count');
        });
    }

    public function down(): void
    {
        Schema::table('billing_agreements', function (Blueprint $table) {
            $table->dropColumn(['failed_count', 'last_failed_at']);
        });
        Schema::dropIfExists('charge_attempts');
    }
};
