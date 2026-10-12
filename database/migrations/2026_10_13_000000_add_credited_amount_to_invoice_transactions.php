<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The part of a payment the invoice could not take and that became account credit (docs/payments.md, PR 2a).
 * It no longer counts towards the invoice balance and cannot be refunded a second time. Also an index for
 * the idempotency lookup (gateway + transaction id): without it the locking read scans a gateway's rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_transactions', function (Blueprint $table) {
            $table->decimal('credited_amount', 17, 2)->default(0)->after('refunded_amount');
            $table->index(['transaction_id', 'gateway_id'], 'invoice_transactions_transaction_gateway_index');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_transactions', function (Blueprint $table) {
            $table->dropIndex('invoice_transactions_transaction_gateway_index');
            $table->dropColumn('credited_amount');
        });
    }
};
