<?php

namespace App\Services\Money;

use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\PaymentGatewayEvent;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The daily consistency check of the money (docs/money-trail.md §5, MT-3). Read-only: it says what does not add up,
 * never fixes it (the journal is the record; staff decide). The same rules run as a test, and after every step of the
 * billing simulation (BillingCursorFuzzTest), so a code path that breaks one fails there before it reaches the cron.
 *
 * Each finding: the rule (a key, lang/*\/money.php «checks»), what it is about («invoice #12») and the figures.
 */
final class MoneyCheck
{
    /** A paid invoice is applied within minutes; one still waiting after this is stuck (BIL-9, the cron's paid_applied). */
    public const PAID_NOT_APPLIED_HOURS = 24;

    /** A gateway request closes with its response; a row still open after this died with it (a fatal error, a timeout). */
    public const GATEWAY_RECEIVED_MINUTES = 60;

    public const RULES = [
        'paid_balance', 'open_settled', 'cancelled_applied', 'cancelled_holds_money', 'paid_not_applied',
        'paid_for_cancelled', 'open_for_cancelled',
        'credit_journal', 'credit_negative', 'payment_not_journaled', 'journal_without_payment', 'refund_journal',
        'over_refunded', 'duplicate_gateway_id', 'gateway_received', 'line_totals',
    ];

    /** @var list<array{rule: string, subject: string, detail: string}> */
    private array $findings = [];

    /**
     * Every rule, or the ones named.
     *
     * @param  list<string>|null  $rules
     * @return list<array{rule: string, subject: string, detail: string}>
     */
    public function run(?array $rules = null): array
    {
        $this->findings = [];
        foreach ($rules ?? self::RULES as $rule) {
            $this->{lcfirst(str_replace('_', '', ucwords($rule, '_')))}();
        }

        return $this->findings;
    }

    /**
     * Σ of what the journal did to each credit balance, «user|currency» => cents (credit_in − credit_out, reversals
     * the other way). The balance in credits is its cache (mycs:credits-check rebuilds it from here).
     *
     * @return array<string, int>
     */
    public static function journalCredits(?int $clientId = null, ?string $currency = null): array
    {
        $totals = [];
        MoneyEvent::query()->with('reverses')
            ->whereIn('kind', [MoneyEventKind::CreditIn->value, MoneyEventKind::CreditOut->value, MoneyEventKind::Reversal->value])
            ->whereNotNull('client_id')
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId)->where('currency_code', $currency))
            ->orderBy('id')
            ->each(function (MoneyEvent $event) use (&$totals) {
                $effect = $event->creditEffectCents();
                if ($effect !== 0) {
                    $key = $event->client_id . '|' . $event->currency_code;
                    $totals[$key] = ($totals[$key] ?? 0) + $effect;
                }
            });

        return $totals;
    }

    private function add(string $rule, string $subject, string $detail): void
    {
        $this->findings[] = ['rule' => $rule, 'subject' => $subject, 'detail' => $detail];
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** What is left to pay, in cents (Invoice::remaining: lines and notes, less the money that stayed on it). */
    private static function remainingCents(Invoice $invoice): int
    {
        return RecordPaymentService::toCents($invoice->remaining);
    }

    /**
     * Each line's stored total is its net × quantity plus its tax, and its tax its rate on that (Δ1): a line written
     * around the model (raw SQL, an import) with a wrong or missing total or tax would make every balance built on it
     * wrong.
     */
    private function lineTotals(): void
    {
        InvoiceItem::query()
            ->where(fn ($query) => $query->whereRaw('ROUND(ROUND(price * quantity, 2) + tax_amount, 2) <> line_total')
                // and its tax is its rate on its net amount (0 when not taxed)
                ->orWhereRaw('tax_amount <> CASE WHEN taxed THEN ROUND(ROUND(price * quantity, 2) * tax_rate / 100, 2) ELSE 0 END'))
            ->orderBy('id')
            ->each(fn (InvoiceItem $item) => $this->add('line_totals', "invoice #{$item->invoice_id} line #{$item->id}",
                'total ' . number_format((float) $item->line_total, 2, '.', '') . ', price ' . number_format((float) $item->price, 2, '.', '')
                . ' × ' . $item->quantity . ', tax ' . number_format((float) $item->tax_amount, 2, '.', '')
                . ' at ' . rtrim(rtrim((string) $item->tax_rate, '0'), '.') . '%' . ($item->taxed ? '' : ' (not taxed)')));
    }

    /** A paid invoice owes nothing and holds nothing over (an overpayment becomes credit). */
    private function paidBalance(): void
    {
        Invoice::query()->whereIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_REFUNDED])->with(['items', 'adjustmentNotes', 'transactions'])
            ->chunkById(500, function ($invoices) {
                foreach ($invoices as $invoice) {
                    $remaining = self::remainingCents($invoice);
                    if ($remaining !== 0) {
                        $this->add('paid_balance', "invoice #{$invoice->id}", ($remaining > 0 ? 'still to pay ' : 'paid over ')
                            . self::money(abs($remaining)) . " {$invoice->currency_code}");
                    }
                }
            });
    }

    /**
     * An open invoice whose payments cover it, or a pending one with nothing to pay (a zero invoice, 2b), should have
     * become paid: staff close it («Close as paid»). A draft without payments is still being written.
     */
    private function openSettled(): void
    {
        Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)
            ->where(fn ($query) => $query
                ->whereHas('transactions', fn ($query) => $query->where('status', InvoiceTransactionStatus::Succeeded->value))
                ->orWhere(fn ($query) => $query->where('status', Invoice::STATUS_UNPAID)->whereHas('items')))
            ->with(['items', 'adjustmentNotes', 'transactions'])
            ->chunkById(500, function ($invoices) {
                foreach ($invoices as $invoice) {
                    $remaining = self::remainingCents($invoice);
                    if ($remaining <= 0) {
                        $this->add('open_settled', "invoice #{$invoice->id}", "{$invoice->status}, left to pay "
                            . self::money($remaining) . " {$invoice->currency_code} (Close as paid, or by hand: a credit purchase, notes over its total)");
                    }
                }
            });
    }

    /** A cancelled invoice renewed a service (a period given while the invoice gave up its money). */
    private function cancelledApplied(): void
    {
        Invoice::query()->where('status', Invoice::STATUS_CANCELLED)
            ->whereHas('items', fn ($query) => $query->whereNotNull('applied_at'))
            ->orderBy('id')->pluck('id')
            ->each(fn ($id) => $this->add('cancelled_applied', "invoice #{$id}", 'cancelled, with a line that renewed its service'));
    }

    /**
     * A cancelled or refunded invoice keeps money: payments on it, less refunds and what became credit, not zero. The
     * customer's money stuck on a closed invoice (cancelled before 2c refused it, or imported): refund it, back or to
     * credit.
     */
    private function cancelledHoldsMoney(): void
    {
        Invoice::query()->whereIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED])
            ->whereHas('transactions', fn ($query) => $query->where('status', InvoiceTransactionStatus::Succeeded->value))
            ->with('transactions')
            ->chunkById(500, function ($invoices) {
                foreach ($invoices as $invoice) {
                    $kept = (int) $invoice->transactions->where('status', InvoiceTransactionStatus::Succeeded)
                        ->sum(fn ($payment) => RecordPaymentService::toCents($payment->amount) - RecordPaymentService::toCents($payment->refunded_amount)
                            - RecordPaymentService::toCents($payment->credited_amount));
                    if ($kept !== 0) {
                        $this->add('cancelled_holds_money', "invoice #{$invoice->id}", "{$invoice->status}, with " . self::money($kept)
                            . " {$invoice->currency_code} of payments still on it (refund it or move it to credit)");
                    }
                }
            });
    }

    /**
     * A paid invoice that renews an ended service (cancelled or terminated), or pays an upgrade cancelled before it was
     * applied (its service ended, or it expired): the payment bought nothing (never revived, never applied) and its
     * money stays on the invoice. Staff refund it or move it to credit. Only money MyCS took (a payment event
     * that is not an import) and still on it: an imported paid invoice, applied before MyCS kept applied_at, is history.
     */
    private function paidForCancelled(): void
    {
        // A debt kept when its service ended (Δ3a) is paid as owed: not money that bought nothing.
        Invoice::query()->where('status', Invoice::STATUS_PAID)->whereNull('kept_as_debt_at')
            ->whereHas('items', fn ($query) => $query->whereNull('applied_at')->where(fn ($line) => $line
                ->where(fn ($renewal) => $renewal->where('reference_type', Service::class)
                    // A charge of it (an imported setup fee, #507) is never applied: it renews nothing.
                    ->where(fn ($line) => $line->whereNull('kind')->orWhere('kind', '!=', InvoiceItem::KIND_CHARGE))
                    ->whereIn('reference_id', Service::query()->whereIn('status', Service::ENDED_STATUSES)->select('id')))
                ->orWhere(fn ($upgrade) => $upgrade->where('reference_type', ServiceUpgrade::class)
                    ->whereIn('reference_id', ServiceUpgrade::query()->where('status', ServiceUpgrade::STATUS_CANCELLED)->select('id')))))
            ->whereHas('transactions', fn ($query) => $query->where('status', InvoiceTransactionStatus::Succeeded->value)
                ->whereExists(self::liveEventsOf(MoneyEventKind::Payment)->where('source', '!=', MoneyTrail::SOURCE_IMPORT)))
            ->with('transactions')
            ->chunkById(500, function ($invoices) {
                foreach ($invoices as $invoice) {
                    $kept = (int) $invoice->transactions->where('status', InvoiceTransactionStatus::Succeeded)
                        ->sum(fn ($payment) => RecordPaymentService::toCents($payment->amount) - RecordPaymentService::toCents($payment->refunded_amount)
                            - RecordPaymentService::toCents($payment->credited_amount));
                    if ($kept !== 0) {
                        $this->add('paid_for_cancelled', "invoice #{$invoice->id}", 'paid, for a service that has ended or an upgrade cancelled: the payment bought nothing, '
                            . self::money($kept) . " {$invoice->currency_code} still on it (refund it or move it to credit)");
                    }
                }
            });
    }

    /** Paid, its lines still not applied (BIL-9): the cron's paid_applied has not managed it for a day. */
    private function paidNotApplied(): void
    {
        Invoice::query()->where('status', Invoice::STATUS_PAID)->whereNotNull('apply_pending_since')
            ->where('apply_pending_since', '<', now()->subHours(self::PAID_NOT_APPLIED_HOURS))
            ->orderBy('id')->get(['id', 'apply_pending_since'])
            ->each(fn (Invoice $invoice) => $this->add('paid_not_applied', "invoice #{$invoice->id}",
                'paid, not applied since ' . $invoice->apply_pending_since->toDateTimeString()));
    }

    /**
     * An open invoice that bills nothing but ended services (cancelled or terminated): a cancellation that stopped before its invoices, or
     * one left open for staff (gateway money on it) and not yet handled.
     */
    private function openForCancelled(): void
    {
        Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)->whereNull('kept_as_debt_at') // a debt kept (Δ3a)
            ->whereHas('items', fn ($query) => $query->where('reference_type', Service::class))
            ->whereDoesntHave('items', fn ($query) => $query->where(fn ($line) => $line->whereNull('reference_type')
                ->orWhere('reference_type', '!=', Service::class)->orWhereNotIn('reference_id',
                    Service::query()->whereIn('status', Service::ENDED_STATUSES)->select('id'))))
            ->orderBy('id')->pluck('id')
            ->each(fn ($id) => $this->add('open_for_cancelled', "invoice #{$id}", 'open, and every service it bills has ended (cancelled or terminated)'));
    }

    /** Every credit balance is what the journal adds up to. */
    private function creditJournal(): void
    {
        // One snapshot for both reads (REPEATABLE READ): a move between them would show as a false mismatch.
        [$journal, $balances] = DB::transaction(fn () => [
            self::journalCredits(),
            Credit::query()->get(['client_id', 'currency_code', 'amount'])
                ->mapWithKeys(fn (Credit $credit) => [$credit->client_id . '|' . $credit->currency_code => (int) round((float) $credit->amount * 100)]),
        ]);
        foreach ($balances->keys()->merge(array_keys($journal))->unique()->sort()->values() as $key) {
            $balance = $balances[$key] ?? 0;
            $expected = $journal[$key] ?? 0;
            if ($balance !== $expected) {
                [$clientId, $currency] = explode('|', $key, 2);
                $this->add('credit_journal', "customer #{$clientId} {$currency}", 'balance ' . self::money($balance)
                    . ', journal ' . self::money($expected) . ' (mycs:credits-check --write rebuilds it from the journal)');
            }
        }
    }

    /** A negative credit: money the customer owes (staff took back credit already spent, MT-1c). */
    private function creditNegative(): void
    {
        Credit::query()->where('amount', '<', 0)->orderBy('id')->get(['client_id', 'currency_code', 'amount'])
            ->each(fn (Credit $credit) => $this->add('credit_negative', "customer #{$credit->client_id} {$credit->currency_code}",
                "credit {$credit->amount}: the customer owes it"));
    }

    /**
     * Payment events not undone by a reversal.
     *
     * @return \Illuminate\Database\Eloquent\Builder<MoneyEvent>
     */
    private static function livePaymentEvents(): \Illuminate\Database\Eloquent\Builder
    {
        return MoneyEvent::query()->where('kind', MoneyEventKind::Payment->value)
            ->whereNotExists(fn (Builder $reversal) => $reversal->from('money_events as reversals')
                ->whereColumn('reversals.reverses_id', 'money_events.id'));
    }

    /**
     * Live events of $kind (not reversed) of the transaction in the outer query (invoice_transactions.id): for a
     * sub-select, so that the journal is compared a chunk of transactions at a time, in the database.
     *
     * @return \Illuminate\Database\Eloquent\Builder<MoneyEvent>
     */
    private static function liveEventsOf(MoneyEventKind $kind): \Illuminate\Database\Eloquent\Builder
    {
        return MoneyEvent::query()->where('kind', $kind->value)
            ->whereColumn('money_events.invoice_transaction_id', 'invoice_transactions.id')
            ->whereNotExists(fn (Builder $reversal) => $reversal->from('money_events as reversals')
                ->whereColumn('reversals.reverses_id', 'money_events.id'));
    }

    /** Every succeeded payment has its one payment event in the journal, for its amount. */
    private function paymentNotJournaled(): void
    {
        InvoiceTransaction::query()->where('status', InvoiceTransactionStatus::Succeeded->value)
            ->select(['id', 'invoice_id', 'amount'])
            ->selectSub(self::liveEventsOf(MoneyEventKind::Payment)->selectRaw('COUNT(*)'), 'journal_events')
            ->selectSub(self::liveEventsOf(MoneyEventKind::Payment)->selectRaw('COALESCE(SUM(amount), 0)'), 'journal_amount')
            ->chunkById(500, function ($transactions) {
                foreach ($transactions as $transaction) {
                    $journaled = RecordPaymentService::toCents($transaction->getAttribute('journal_amount'));
                    $events = (int) $transaction->getAttribute('journal_events');
                    if ($events !== 1 || $journaled !== RecordPaymentService::toCents($transaction->amount)) {
                        $this->add('payment_not_journaled', "transaction #{$transaction->id} (invoice #{$transaction->invoice_id})",
                            "amount {$transaction->amount}, {$events} payment event(s) for " . self::money($journaled));
                    }
                }
            });
    }

    /** Every payment event (not reversed) has its succeeded payment: a payment deleted reverses its events. */
    private function journalWithoutPayment(): void
    {
        self::livePaymentEvents()
            ->whereNotExists(fn (Builder $payment) => $payment->from('invoice_transactions')
                ->whereColumn('invoice_transactions.id', 'money_events.invoice_transaction_id')
                ->where('invoice_transactions.status', InvoiceTransactionStatus::Succeeded->value))
            ->select(['id', 'invoice_id', 'invoice_transaction_id'])
            ->chunkById(500, function ($events) {
                foreach ($events as $event) {
                    $this->add('journal_without_payment', "money event #{$event->id} (invoice #{$event->invoice_id})",
                        $event->invoice_transaction_id === null ? 'no transaction' : "transaction #{$event->invoice_transaction_id} gone or not succeeded");
                }
            });
    }

    /** What a payment says it refunded is what the journal's refunds (not reversed) add up to. */
    private function refundJournal(): void
    {
        InvoiceTransaction::query()
            ->where(fn ($query) => $query->where('refunded_amount', '>', 0)->orWhereExists(fn (Builder $refund) => $refund
                ->from('money_events')->where('kind', MoneyEventKind::Refund->value)
                ->whereColumn('money_events.invoice_transaction_id', 'invoice_transactions.id')))
            ->select(['id', 'invoice_id', 'refunded_amount'])
            ->selectSub(self::liveEventsOf(MoneyEventKind::Refund)->selectRaw('COALESCE(SUM(amount), 0)'), 'journal_refunded')
            ->chunkById(500, function ($transactions) {
                foreach ($transactions as $transaction) {
                    $journaled = RecordPaymentService::toCents($transaction->getAttribute('journal_refunded'));
                    if ($journaled !== RecordPaymentService::toCents($transaction->refunded_amount)) {
                        $this->add('refund_journal', "transaction #{$transaction->id} (invoice #{$transaction->invoice_id})",
                            "refunded {$transaction->refunded_amount}, journal " . self::money($journaled));
                    }
                }
            });
    }

    /** Never more back (refunded, or moved to credit) than the payment brought. */
    private function overRefunded(): void
    {
        InvoiceTransaction::query()->where(fn ($query) => $query->where('refunded_amount', '>', 0)->orWhere('credited_amount', '>', 0))
            ->select(['id', 'invoice_id', 'amount', 'refunded_amount', 'credited_amount'])
            ->chunkById(500, function ($transactions) {
                foreach ($transactions as $transaction) {
                    $out = RecordPaymentService::toCents($transaction->refunded_amount) + RecordPaymentService::toCents($transaction->credited_amount);
                    if ($out > RecordPaymentService::toCents($transaction->amount)) {
                        $this->add('over_refunded', "transaction #{$transaction->id} (invoice #{$transaction->invoice_id})",
                            "amount {$transaction->amount}, refunded {$transaction->refunded_amount}, to credit {$transaction->credited_amount}");
                    }
                }
            });
    }

    /**
     * A payment id a gateway recorded (its journal entry keeps the provider's id) succeeded once: another succeeded
     * payment with the same id, by a gateway or typed by staff on any customer, is the same money recorded twice. Ids
     * that only staff typed (a «cash» reference) are not compared: they are not the provider's.
     */
    private function duplicateGatewayId(): void
    {
        DB::table('invoice_transactions')->where('status', InvoiceTransactionStatus::Succeeded->value)
            ->whereIn('transaction_id', self::livePaymentEvents()->whereNotNull('external_reference')->select('external_reference'))
            ->select('transaction_id', DB::raw('COUNT(*) as times'))
            ->groupBy('transaction_id')->havingRaw('COUNT(*) > 1')
            ->orderBy('transaction_id')->get()
            ->each(fn ($row) => $this->add('duplicate_gateway_id', "payment id {$row->transaction_id}",
                "succeeded {$row->times} times (" . InvoiceTransaction::query()->where('transaction_id', $row->transaction_id)
                    ->where('status', InvoiceTransactionStatus::Succeeded->value)->orderBy('id')->pluck('id')
                    ->map(fn ($id) => "#{$id}")->implode(', ') . ')'));
    }

    /** A gateway request whose row never closed: the request died (a fatal error, a timeout) and its money is unknown. */
    private function gatewayReceived(): void
    {
        PaymentGatewayEvent::query()->where('outcome', PaymentGatewayEvent::OUTCOME_RECEIVED)
            ->where('created_at', '<', now()->subMinutes(self::GATEWAY_RECEIVED_MINUTES))
            ->orderBy('id')->get(['id', 'gateway', 'endpoint', 'created_at'])
            ->each(fn (PaymentGatewayEvent $event) => $this->add('gateway_received', "gateway log #{$event->id}",
                "{$event->gateway} {$event->endpoint} at {$event->created_at}: the request never finished"));
    }
}
