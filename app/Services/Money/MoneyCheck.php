<?php

namespace App\Services\Money;

use App\Enums\InvoiceTransactionStatus;
use App\Enums\MoneyEventKind;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\PaymentGatewayEvent;
use App\Models\Service;
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
        'paid_balance', 'open_settled', 'cancelled_applied', 'paid_not_applied', 'open_for_cancelled',
        'credit_journal', 'credit_negative', 'payment_not_journaled', 'journal_without_payment', 'refund_journal',
        'over_refunded', 'duplicate_gateway_id', 'gateway_received',
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
    public static function journalCredits(?int $userId = null, ?string $currency = null): array
    {
        $totals = [];
        MoneyEvent::query()->with('reverses')
            ->whereIn('kind', [MoneyEventKind::CreditIn->value, MoneyEventKind::CreditOut->value, MoneyEventKind::Reversal->value])
            ->whereNotNull('user_id')
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId)->where('currency_code', $currency))
            ->orderBy('id')
            ->each(function (MoneyEvent $event) use (&$totals) {
                $effect = $event->creditEffectCents();
                if ($effect !== 0) {
                    $key = $event->user_id . '|' . $event->currency_code;
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

    /** A paid invoice owes nothing and holds nothing over (an overpayment becomes credit). */
    private function paidBalance(): void
    {
        Invoice::query()->where('status', Invoice::STATUS_PAID)->with(['items', 'adjustmentNotes', 'transactions'])
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

    /** An open invoice whose payments cover it should have become paid. */
    private function openSettled(): void
    {
        Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)
            ->whereHas('transactions', fn ($query) => $query->where('status', InvoiceTransactionStatus::Succeeded->value))
            ->with(['items', 'adjustmentNotes', 'transactions'])
            ->chunkById(500, function ($invoices) {
                foreach ($invoices as $invoice) {
                    $remaining = self::remainingCents($invoice);
                    if ($remaining <= 0) {
                        $this->add('open_settled', "invoice #{$invoice->id}", "{$invoice->status}, left to pay "
                            . self::money($remaining) . " {$invoice->currency_code}");
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
     * An open invoice that bills nothing but cancelled services: a cancellation that stopped before its invoices, or
     * one left open for staff (gateway money on it) and not yet handled.
     */
    private function openForCancelled(): void
    {
        Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)
            ->whereHas('items', fn ($query) => $query->where('reference_type', Service::class))
            ->whereDoesntHave('items', fn ($query) => $query->where(fn ($line) => $line->whereNull('reference_type')
                ->orWhere('reference_type', '!=', Service::class)->orWhereNotIn('reference_id',
                    Service::query()->where('status', Service::STATUS_CANCELLED)->select('id'))))
            ->orderBy('id')->pluck('id')
            ->each(fn ($id) => $this->add('open_for_cancelled', "invoice #{$id}", 'open, and every service it bills is cancelled'));
    }

    /** Every credit balance is what the journal adds up to. */
    private function creditJournal(): void
    {
        // One snapshot for both reads (REPEATABLE READ): a move between them would show as a false mismatch.
        [$journal, $balances] = DB::transaction(fn () => [
            self::journalCredits(),
            Credit::query()->get(['user_id', 'currency_code', 'amount'])
                ->mapWithKeys(fn (Credit $credit) => [$credit->user_id . '|' . $credit->currency_code => (int) round((float) $credit->amount * 100)]),
        ]);
        foreach ($balances->keys()->merge(array_keys($journal))->unique()->sort()->values() as $key) {
            $balance = $balances[$key] ?? 0;
            $expected = $journal[$key] ?? 0;
            if ($balance !== $expected) {
                [$userId, $currency] = explode('|', $key, 2);
                $this->add('credit_journal', "customer #{$userId} {$currency}", 'balance ' . self::money($balance)
                    . ', journal ' . self::money($expected) . ' (mycs:credits-check --write rebuilds it from the journal)');
            }
        }
    }

    /** A negative credit: money the customer owes (staff took back credit already spent, MT-1c). */
    private function creditNegative(): void
    {
        Credit::query()->where('amount', '<', 0)->orderBy('id')->get(['user_id', 'currency_code', 'amount'])
            ->each(fn (Credit $credit) => $this->add('credit_negative', "customer #{$credit->user_id} {$credit->currency_code}",
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

    /** Every succeeded payment has its one payment event in the journal, for its amount. */
    private function paymentNotJournaled(): void
    {
        $events = self::livePaymentEvents()->whereNotNull('invoice_transaction_id')
            ->get(['invoice_transaction_id', 'amount'])->groupBy('invoice_transaction_id');
        InvoiceTransaction::query()->where('status', InvoiceTransactionStatus::Succeeded->value)->orderBy('id')
            ->get(['id', 'invoice_id', 'amount'])
            ->each(function (InvoiceTransaction $transaction) use ($events) {
                $mine = $events->get($transaction->id, collect());
                $journaled = (int) $mine->sum(fn ($event) => RecordPaymentService::toCents($event->amount));
                if ($mine->count() !== 1 || $journaled !== RecordPaymentService::toCents($transaction->amount)) {
                    $this->add('payment_not_journaled', "transaction #{$transaction->id} (invoice #{$transaction->invoice_id})",
                        "amount {$transaction->amount}, " . $mine->count() . ' payment event(s) for ' . self::money($journaled));
                }
            });
    }

    /** Every payment event (not reversed) has its succeeded payment: a payment deleted reverses its events. */
    private function journalWithoutPayment(): void
    {
        $succeeded = InvoiceTransaction::query()->where('status', InvoiceTransactionStatus::Succeeded->value)->pluck('id')->flip();
        self::livePaymentEvents()->orderBy('id')->get(['id', 'invoice_id', 'invoice_transaction_id'])
            ->reject(fn (MoneyEvent $event) => $event->invoice_transaction_id !== null && $succeeded->has($event->invoice_transaction_id))
            ->each(fn (MoneyEvent $event) => $this->add('journal_without_payment', "money event #{$event->id} (invoice #{$event->invoice_id})",
                $event->invoice_transaction_id === null ? 'no transaction' : "transaction #{$event->invoice_transaction_id} gone or not succeeded"));
    }

    /** What a payment says it refunded is what the journal's refunds (not reversed) add up to. */
    private function refundJournal(): void
    {
        $refunds = MoneyEvent::query()->where('kind', MoneyEventKind::Refund->value)->whereNotNull('invoice_transaction_id')
            ->whereNotExists(fn (Builder $reversal) => $reversal->from('money_events as reversals')
                ->whereColumn('reversals.reverses_id', 'money_events.id'))
            ->get(['invoice_transaction_id', 'amount'])->groupBy('invoice_transaction_id')
            ->map(fn ($events) => (int) $events->sum(fn ($event) => RecordPaymentService::toCents($event->amount)));
        InvoiceTransaction::query()->where(fn ($query) => $query->where('refunded_amount', '>', 0)
            ->orWhereIn('id', $refunds->keys()))->orderBy('id')->get(['id', 'invoice_id', 'refunded_amount'])
            ->each(function (InvoiceTransaction $transaction) use ($refunds) {
                $journaled = $refunds->get($transaction->id, 0);
                if ($journaled !== RecordPaymentService::toCents($transaction->refunded_amount)) {
                    $this->add('refund_journal', "transaction #{$transaction->id} (invoice #{$transaction->invoice_id})",
                        "refunded {$transaction->refunded_amount}, journal " . self::money($journaled));
                }
            });
    }

    /** Never more back (refunded, or moved to credit) than the payment brought. */
    private function overRefunded(): void
    {
        InvoiceTransaction::query()->where(fn ($query) => $query->where('refunded_amount', '>', 0)->orWhere('credited_amount', '>', 0))
            ->orderBy('id')->get(['id', 'invoice_id', 'amount', 'refunded_amount', 'credited_amount'])
            ->each(function (InvoiceTransaction $transaction) {
                $out = RecordPaymentService::toCents($transaction->refunded_amount) + RecordPaymentService::toCents($transaction->credited_amount);
                if ($out > RecordPaymentService::toCents($transaction->amount)) {
                    $this->add('over_refunded', "transaction #{$transaction->id} (invoice #{$transaction->invoice_id})",
                        "amount {$transaction->amount}, refunded {$transaction->refunded_amount}, to credit {$transaction->credited_amount}");
                }
            });
    }

    /** A gateway's payment id succeeded once: twice is money recorded twice. */
    private function duplicateGatewayId(): void
    {
        DB::table('invoice_transactions')->where('status', InvoiceTransactionStatus::Succeeded->value)
            ->whereNotNull('gateway_id')->whereNotNull('transaction_id')->where('transaction_id', '!=', '')
            ->select('gateway_id', 'transaction_id', DB::raw('COUNT(*) as times'))
            ->groupBy('gateway_id', 'transaction_id')->havingRaw('COUNT(*) > 1')
            ->orderBy('gateway_id')->orderBy('transaction_id')->get()
            ->each(fn ($row) => $this->add('duplicate_gateway_id', "gateway #{$row->gateway_id}",
                "one payment id succeeded {$row->times} times (" . InvoiceTransaction::query()->where('gateway_id', $row->gateway_id)
                    ->where('transaction_id', $row->transaction_id)->where('status', InvoiceTransactionStatus::Succeeded->value)
                    ->orderBy('id')->pluck('id')->map(fn ($id) => "#{$id}")->implode(', ') . ')'));
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
