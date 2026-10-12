<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/money-trail.md §1 (MT-1a): the append-only journal of every money movement. Rows are never updated or
 * deleted (App\Models\MoneyEvent refuses both). The customer is linked with restrict, so deleting a customer can
 * never take the journal with it. Invoices and transactions are referenced without a foreign key: the history
 * outlives them. No personal data: ids, amounts, dates and the provider's ids only (§9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_events', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->decimal('amount', 17, 2);
            $table->string('currency_code', 3);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->unsignedBigInteger('invoice_transaction_id')->nullable()->index();
            $table->decimal('credit_balance_after', 17, 2)->nullable();
            $table->string('source', 50);
            $table->string('cause', 50)->nullable();
            $table->string('external_reference')->nullable();
            $table->string('group_reference')->nullable()->index();
            $table->nullableMorphs('actor');
            $table->text('reason')->nullable();
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('money_events')->restrictOnDelete();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['user_id', 'currency_code', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_events');
    }
};
