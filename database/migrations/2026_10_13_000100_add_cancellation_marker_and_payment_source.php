<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/payments.md, PR 2a (second review round):
 * - adjustment_notes.is_cancellation: the credit note a cancellation writes. Reopening the invoice voids it, so the
 *   invoice is payable again (otherwise its balance stays 0: any transaction pays it and a payment becomes credit).
 * - invoice_transactions.source_transaction_id: a payment made from the credit another transaction left (a PayPal
 *   subscription sale paying the other services' invoices). It lets an interrupted allocation resume on a webhook
 *   retry without spending more than that sale left, and keeps the money trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adjustment_notes', function (Blueprint $table) {
            $table->boolean('is_cancellation')->default(false)->after('status');
        });
        Schema::table('invoice_transactions', function (Blueprint $table) {
            $table->foreignId('source_transaction_id')->nullable()->after('credited_amount')
                ->constrained('invoice_transactions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_transaction_id');
        });
        Schema::table('adjustment_notes', function (Blueprint $table) {
            $table->dropColumn('is_cancellation');
        });
    }
};
