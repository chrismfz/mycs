<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A paid invoice that became open again (a chargeback, a deleted payment, staff's «back to unpaid»), with when it had
 * been paid and what its lines were worth then (sweep 6, S6-TAX-EKDOSI-3): the year's income report keeps it in the
 * year it was paid and takes it off in the year it was reopened (IncomeReport). The invoice itself forgets both: its
 * paid_at is given again when it is paid again, and its lines can change while it is open. And when it was paid again:
 * a dispute won gives its paid_at the first payment's date back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_reopenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->dateTime('paid_at');
            $table->dateTime('reopened_at');
            $table->dateTime('paid_again_at')->nullable();
            $table->decimal('net', 17, 2);
            $table->decimal('total', 17, 2);
            $table->index('reopened_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reopenings');
    }
};
