<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/money-trail.md §1 (MT-1a): the append-only journal of every money movement. Rows are never updated or
 * deleted (App\Models\MoneyEvent refuses both). The customer is linked with restrict, so deleting a customer can
 * never take the journal with it. Invoices and transactions are referenced without a foreign key: the history
 * outlives them. No personal data: ids, amounts, dates and the provider's ids only (§9).
 *
 * The customer is nullable only for money that lands on an invoice left without one (a customer deleted before this
 * journal: invoices.user_id is set null on delete). Existing credit balances get an opening event, so the journal
 * adds up to credits.amount from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The journal is the account's: users then; clients once they are apart (docs/accounts.md, PR 2a), when replayed.
        $owner = Schema::hasTable('clients') ? 'client_id' : 'user_id';
        $owners = Schema::hasTable('clients') ? 'clients' : 'users';

        Schema::create('money_events', function (Blueprint $table) use ($owner, $owners) {
            $table->id();
            $table->string('kind', 20);
            $table->decimal('amount', 17, 2);
            $table->string('currency_code', 3);
            $table->foreignId($owner)->nullable()->constrained($owners)->restrictOnDelete();
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

            $table->index([$owner, 'currency_code', 'id']);
        });

        $now = now();
        DB::table('credits')->where('amount', '!=', 0)->orderBy('id')->each(function (object $credit) use ($now, $owner) {
            DB::table('money_events')->insert([
                'kind' => (float) $credit->amount > 0 ? 'credit_in' : 'credit_out',
                'amount' => abs((float) $credit->amount),
                'currency_code' => $credit->currency_code,
                $owner => $credit->{$owner},
                'credit_balance_after' => $credit->amount,
                'source' => 'system',
                'cause' => 'opening_balance',
                'occurred_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_events');
    }
};
