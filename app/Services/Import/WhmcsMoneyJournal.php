<?php

namespace App\Services\Import;

use App\Enums\AdjustmentNoteType;
use App\Enums\MoneyEventKind;
use App\Models\Credit;
use App\Models\Invoice;
use App\Services\Money\MoneyTrail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;

/**
 * The money journal of a WHMCS import (docs/importer.md «Το ημερολόγιο των χρημάτων»), so that the import passes the same
 * checks as money recorded here (MoneyCheck, mycs:credits-check): every payment and refund has its event, and every
 * credit balance is the sum of its moves.
 *
 * WHMCS keeps the credit applied to an invoice on the invoice (tblinvoices.credit) and the history of each balance in
 * tblcredit. Here the credit applied is a credit payment on the invoice, an overpayment the part of a payment the
 * invoice could not take (credited_amount), as RecordPaymentService records them. The moves WHMCS has no invoice for
 * (staff's, a refund to credit) come from tblcredit; where WHMCS's balance is not what its history adds up to (moves
 * made around it), one adjustment per customer keeps WHMCS's balance, the one the customer sees, and is listed for staff.
 *
 * Every event is the import's (source `import`): never taken for money recorded after the cut-over.
 */
class WhmcsMoneyJournal
{
    /** Kinds of tblcredit rows, by WHMCS's own descriptions: those with an invoice are rebuilt from the invoices. */
    public const FROM_INVOICES = ['applied', 'removed', 'overpayment', 'mass_pay', 'add_funds'];

    /** @var array<int, array{client_id: int, currency_code: string, status: string, paid_at: ?string, created_at: ?string}> */
    private array $invoices = [];

    /** @var array<int, true> credit payments WHMCS dated by the day only (tblcredit.date): ordered after that day's money in */
    private array $dayOnly = [];

    /** @var list<array{transaction_id: int, cents: int, to_credit: bool, from_credit: int, at: ?string}> the refunds imported (ImportFromWhmcs::importRefunds) */
    private array $refunds = [];

    /** @var array<int, true> tblcredit rows a refund to credit stands for */
    private array $creditRowsUsed = [];

    /** @var list<array<string, mixed>> the events, in the order written (as RecordPaymentService writes them) */
    private array $moves = [];

    /** @var array<int, true> Mass Pay parents (not invoices here) */
    private array $parents = [];

    /** @var array{payments: int, refunds: int, credit_payments: int, overpayments: int, purchases: int, negative_invoices: int, refund_notes: int, from_history: int, no_invoice: int, no_money: int, unplaced: list<string>} */
    public array $summary = ['payments' => 0, 'refunds' => 0, 'credit_payments' => 0, 'overpayments' => 0, 'purchases' => 0,
        'negative_invoices' => 0, 'refund_notes' => 0, 'from_history' => 0, 'no_invoice' => 0, 'no_money' => 0, 'unplaced' => []];

    public function __construct(private PDO $whmcs) {}

    /** A table or column the source has not (an older WHMCS, a test fixture), as against any other failure. */
    public static function isMissing(PDOException $e): bool
    {
        return in_array((string) $e->getCode(), ['42S02', '42S22'], true)
            || str_contains($e->getMessage(), 'no such table') || str_contains($e->getMessage(), 'no such column');
    }

    /** What a tblcredit row is, by its description (WHMCS writes them in English, whatever the language). */
    public static function kind(string $description): string
    {
        return match (true) {
            str_starts_with($description, 'Credit Applied to Invoice') => 'applied',
            str_starts_with($description, 'Credit Removed from Invoice') => 'removed',
            str_contains($description, 'Overpayment') => 'overpayment',
            str_starts_with($description, 'Mass Invoice Payment') => 'mass_pay',
            str_starts_with($description, 'Add Funds Invoice') => 'add_funds',
            str_contains($description, 'Refund') => 'refund',
            str_starts_with($description, 'Upgrade/Downgrade') => 'downgrade',
            default => 'other',
        };
    }

    /**
     * The credit each invoice took from its customer's balance (tblinvoices.credit), as a credit payment on it. A Mass
     * Pay parent is not an invoice here; a child takes only what its parents did not pay ($childCredit, from
     * importMassPay: the rest of its credit was the parent's money, already an allocation of it).
     *
     * Dated when WHMCS applied it (tblcredit, by the day only): at the time the invoice was paid if that was the same day.
     *
     * @param  array<int, true>  $parents  Mass Pay parents
     * @param  array<int, int>  $childCredit  child id => cents of its credit not paid by a parent
     * @param  array<int, true>  $imported  the invoices imported
     * @param  list<int>  $dayOnly  transactions the importer dated by a day only (a Mass Pay parent's own credit)
     */
    public function creditApplied(array $parents, array $childCredit, array $imported, array $dayOnly = []): void
    {
        $this->parents = $parents;
        $this->dayOnly = array_fill_keys($dayOnly, true);
        $rows = $this->rows('SELECT id, credit, date, datepaid FROM tblinvoices WHERE credit > 0 ORDER BY id');
        $appliedOn = $this->appliedDates();
        // Above every WHMCS payment and every allocation (importMassPay), as the allocations' ids.
        $nextId = max((int) ($this->rows('SELECT MAX(id) AS id FROM tblaccounts')[0]['id'] ?? 0), (int) DB::table('invoice_transactions')->max('id')) + 1;

        $rows = array_filter($rows, fn (array $row) => !isset($parents[(int) $row['id']]) && isset($imported[(int) $row['id']]));
        $insert = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $cents = array_key_exists($id, $childCredit) ? $childCredit[$id] : WhmcsMassPay::cents($row['credit']);
            if ($cents <= 0) {
                continue;
            }
            $paid = self::date($row['datepaid']);
            $at = $appliedOn[$id] ?? $paid ?? self::date($row['date']);
            if (isset($appliedOn[$id])) {
                if ($paid !== null && substr($paid, 0, 10) === substr($appliedOn[$id], 0, 10)) {
                    $at = $paid;
                } else {
                    $this->dayOnly[$nextId] = true;
                }
            } elseif ($paid === null) {
                $this->dayOnly[$nextId] = true; // the invoice's day alone (ADV-528-R2-1)
            }
            $insert[] = ['id' => $nextId++, 'invoice_id' => $id, 'amount' => $cents / 100, 'is_credit_transaction' => true,
                'created_at' => $at, 'updated_at' => $at];
        }
        foreach (array_chunk($insert, 1000) as $chunk) {
            DB::table('invoice_transactions')->insert($chunk);
        }
    }

    /**
     * What a paid (or since refunded) invoice took over its amount (WHMCS: «Overpayment», to the customer's credit) is
     * its payments' credited_amount, newest first, as RecordPaymentService records an overpayment: as much as WHMCS
     * recorded giving to credit for it (tblcredit), else what its refunds did not take back. Run before the refunds: a
     * later refund gives back what the invoice kept, never the credit the payment already gave (ADV-523-2).
     */
    public function overpayments(): void
    {
        $settled = DB::table('invoices')->whereIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_REFUNDED])->select('id');
        $totals = DB::table('invoice_items')->whereIn('invoice_id', $settled)
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(ROUND(line_total * 100)) cents')->pluck('cents', 'invoice_id');
        $paid = DB::table('invoice_transactions')->where('status', 'succeeded')->whereIn('invoice_id', $settled)
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(ROUND(amount * 100) - ROUND(refunded_amount * 100) - ROUND(credited_amount * 100)) cents')
            ->pluck('cents', 'invoice_id');

        // What WHMCS itself gave to credit for each invoice («Invoice #N Overpayment»), and what it refunded of each.
        $recorded = [];
        foreach ($this->rows("SELECT relid, SUM(amount) amount FROM tblcredit WHERE relid > 0 AND amount > 0 AND description LIKE '%Overpayment%' GROUP BY relid") as $row) {
            $recorded[(int) $row['relid']] = WhmcsMassPay::cents($row['amount']);
        }
        $refundedOf = [];
        $refunds = $this->rows('SELECT refundid, SUM(amountout) amount FROM tblaccounts WHERE refundid > 0 AND amountout > 0 GROUP BY refundid');
        $invoiceOf = DB::table('invoice_transactions')->whereIn('id', array_map(fn (array $row) => (int) $row['refundid'], $refunds))->pluck('invoice_id', 'id');
        foreach ($refunds as $row) {
            if (isset($invoiceOf[(int) $row['refundid']])) {
                $refundedOf[(int) $invoiceOf[(int) $row['refundid']]] = ($refundedOf[(int) $invoiceOf[(int) $row['refundid']]] ?? 0) + WhmcsMassPay::cents($row['amount']);
            }
        }

        foreach ($paid as $invoiceId => $cents) {
            // As much as WHMCS recorded giving to credit; with no record, what the refunds did not take back (paid twice,
            // one given back: no credit).
            $excess = (int) $cents - (int) ($totals[$invoiceId] ?? 0);
            $over = isset($recorded[$invoiceId]) ? min($excess, $recorded[$invoiceId]) : $excess - ($refundedOf[$invoiceId] ?? 0);
            if ($over <= 0) {
                continue;
            }
            $payments = DB::table('invoice_transactions')->where('invoice_id', $invoiceId)->where('status', 'succeeded')
                ->where('is_credit_transaction', false)->orderByDesc('created_at')->orderByDesc('id')
                ->get(['id', 'amount', 'refunded_amount', 'credited_amount']);
            foreach ($payments as $payment) {
                $credited = WhmcsMassPay::cents($payment->credited_amount);
                $take = min($over, WhmcsMassPay::cents($payment->amount) - WhmcsMassPay::cents($payment->refunded_amount) - $credited);
                if ($take <= 0) {
                    continue;
                }
                DB::table('invoice_transactions')->where('id', $payment->id)->update(['credited_amount' => ($credited + $take) / 100]);
                $over -= $take;
                if ($over === 0) {
                    break;
                }
            }
            if ($over > 0) {
                $this->summary['unplaced'][] = "invoice #{$invoiceId}: " . number_format($over / 100, 2, '.', '') . ' over its amount, on credit payments only';
            }
        }
    }

    /**
     * The refunds the importer put on the payments, for their events and notes; the tblcredit rows a refund to credit
     * stands for are not journaled again from the history.
     *
     * @param  list<array{transaction_id: int, cents: int, to_credit: bool, from_credit: int, at: ?string}>  $refunds
     * @param  list<int>  $creditRowsUsed
     */
    public function refunds(array $refunds, array $creditRowsUsed): void
    {
        $this->refunds = $refunds;
        $this->creditRowsUsed = array_fill_keys($creditRowsUsed, true);
    }

    /**
     * A paid invoice whose money went back stays settled: a credit note takes what its refunds left unpaid off its
     * total, as a refund here does (ExtensionHelper::afterRefund, back or to credit). A refund of a second payment (paid
     * twice, one given back) leaves it paid in full: no note. One WHMCS marked refunded with no money on it owes nothing
     * either.
     */
    public function refundNotes(): void
    {
        $now = now()->toDateTimeString();
        $settled = DB::table('invoices')->whereIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_REFUNDED])->select('id');
        $totals = DB::table('invoice_items')->whereIn('invoice_id', $settled)->groupBy('invoice_id')
            ->selectRaw('invoice_id, SUM(ROUND(line_total * 100)) cents')->pluck('cents', 'invoice_id');
        $payments = DB::table('invoice_transactions')->where('status', 'succeeded')->whereIn('invoice_id', $settled)->orderBy('id')
            ->get(['id', 'invoice_id', 'amount', 'refunded_amount', 'credited_amount', 'transaction_id'])->groupBy('invoice_id');
        $refundedInvoices = DB::table('invoices')->where('status', Invoice::STATUS_REFUNDED)->pluck('id')->flip();
        $byTransaction = collect($this->refunds)->groupBy('transaction_id');

        $notes = [];
        foreach ($totals as $invoiceId => $total) {
            $paid = $payments->get($invoiceId, collect());
            $kept = (int) $paid->sum(fn ($payment) => WhmcsMassPay::cents($payment->amount) - WhmcsMassPay::cents($payment->refunded_amount)
                - WhmcsMassPay::cents($payment->credited_amount));
            $refunds = $paid->flatMap(fn ($payment) => $byTransaction->get($payment->id, collect())
                ->map(fn (array $refund) => $refund + ['transid' => $payment->transaction_id]));
            $short = min((int) $total - $kept, $refunds->isEmpty() ? (int) $total : (int) $refunds->sum('cents'));
            if ($short <= 0 || ($refunds->isEmpty() && ($paid->isNotEmpty() || !isset($refundedInvoices[$invoiceId])))) {
                continue;
            }
            // In the words of the refund here (ExtensionHelper::refundToCredit, refundLocally).
            $notes[] = ['invoice_id' => $invoiceId, 'type' => AdjustmentNoteType::Credit->value, 'amount' => -$short / 100,
                'description' => $refunds->isEmpty() ? 'Refunded in WHMCS, with no payment on it'
                    : $refunds->unique(fn (array $refund) => $refund['transaction_id'] . '|' . (int) $refund['to_credit'])
                        ->map(fn (array $refund) => $refund['to_credit'] ? 'Refund to account credit for transaction #' . $refund['transaction_id']
                            : 'Refund for transaction #' . $refund['transaction_id'] . ' (' . ($refund['transid'] ?: 'manual') . ')')->implode(', '),
                'status' => 'active', 'is_refund' => $refunds->isNotEmpty(),
                'created_at' => $refunds->max('at') ?? $now, 'updated_at' => $now];
        }
        foreach (array_chunk($notes, 1000) as $chunk) {
            DB::table('adjustment_notes')->insert($chunk);
        }
        $this->summary['refund_notes'] = count($notes);
    }

    /**
     * Write the journal: every payment and refund, every credit move, then one adjustment per customer whose balance is
     * not what the moves add up to.
     *
     * @return list<array{client_id: int, currency_code: string, history: int, balance: int, last: ?string}> the adjustments, most recent customer first
     */
    public function write(): array
    {
        $this->invoices = DB::table('invoices')->get(['id', 'client_id', 'currency_code', 'status', 'paid_at', 'created_at'])
            ->mapWithKeys(fn ($invoice) => [(int) $invoice->id => ['client_id' => (int) $invoice->client_id,
                'currency_code' => (string) $invoice->currency_code, 'status' => (string) $invoice->status, 'paid_at' => $invoice->paid_at,
                'created_at' => $invoice->created_at]])
            ->all();

        $this->transactions();
        $this->negativeInvoices();
        $this->purchases();
        $this->history();
        $adjustments = $this->balances();

        $this->insert();

        return $adjustments;
    }

    /**
     * Each transaction's payment event; a credit payment's move out of credit; an overpayment's move in; each refund,
     * back to the customer (a refund event) or to their credit (a move in, as ExtensionHelper::refundToCredit).
     */
    private function transactions(): void
    {
        $refunds = collect($this->refunds)->groupBy('transaction_id');
        DB::table('invoice_transactions')->where('status', 'succeeded')->orderBy('id')
            ->select(['id', 'invoice_id', 'amount', 'credited_amount', 'is_credit_transaction', 'source_transaction_id', 'created_at'])
            ->chunk(1000, function ($transactions) use ($refunds) {
                foreach ($transactions as $transaction) {
                    $invoice = $this->invoices[(int) $transaction->invoice_id] ?? null;
                    $cents = WhmcsMassPay::cents($transaction->amount);
                    if ($invoice === null || $cents <= 0) {
                        $this->summary[$invoice === null ? 'no_invoice' : 'no_money']++;

                        continue;
                    }
                    $on = ['client_id' => $invoice['client_id'], 'currency_code' => $invoice['currency_code'],
                        'invoice_id' => (int) $transaction->invoice_id, 'invoice_transaction_id' => (int) $transaction->id];
                    // A payment WHMCS dated 0000-00-00: its invoice's date (a day only), never the day of the import.
                    $at = $transaction->created_at ?? $invoice['created_at'];
                    $dayOnly = isset($this->dayOnly[(int) $transaction->id]) || $transaction->created_at === null;
                    // A share of one sale paying other invoices is grouped under that sale (RecordPaymentService::spendCredit).
                    $group = $transaction->source_transaction_id === null ? null : 'transaction:' . $transaction->source_transaction_id;

                    if ($transaction->is_credit_transaction) {
                        $this->move(MoneyEventKind::CreditOut, $cents, MoneyTrail::CAUSE_CREDIT_PAYMENT, $at, $on, $group, dayOnly: $dayOnly);
                        $this->summary['credit_payments']++;
                    }
                    $this->add(MoneyEventKind::Payment, $cents, $at, $on + ['group_reference' => $group], dayOnly: $dayOnly);
                    $this->summary['payments']++;

                    $own = $refunds->get($transaction->id, collect());
                    // What it gave to credit when it came: its credited part now, less refunds to credit, plus what refunds took back.
                    $overpaid = WhmcsMassPay::cents($transaction->credited_amount) - (int) $own->where('to_credit', true)->sum('cents')
                        + (int) $own->sum('from_credit');
                    if ($overpaid > 0) {
                        $this->move(MoneyEventKind::CreditIn, $overpaid, MoneyTrail::CAUSE_OVERPAYMENT, $at, $on);
                        $this->summary['overpayments']++;
                    }
                    foreach ($own as $refund) {
                        if ($refund['from_credit'] > 0) {
                            $this->move(MoneyEventKind::CreditOut, $refund['from_credit'], MoneyTrail::CAUSE_OVERPAYMENT, $refund['at'] ?? $at, $on,
                                note: 'WHMCS: Removal of Credit from Invoice #' . $transaction->invoice_id . ', refunded');
                        }
                        $refund['to_credit']
                            ? $this->move(MoneyEventKind::CreditIn, $refund['cents'], MoneyTrail::CAUSE_REFUND_TO_CREDIT, $refund['at'] ?? $at, $on)
                            : $this->add(MoneyEventKind::Refund, $refund['cents'], $refund['at'] ?? $at, $on);
                        $this->summary['refunds']++;
                    }
                }
            });
    }

    /**
     * A negative invoice (staff gave money back on one, in WHMCS) took a negative credit: the customer's balance went up
     * by it. Its own balance stays below zero here (MoneyCheck's paid_balance, «paid over»: docs/importer.md).
     */
    private function negativeInvoices(): void
    {
        $credited = $this->appliedDates(false);
        foreach ($this->rows('SELECT id, credit, date, datepaid FROM tblinvoices WHERE credit < 0 ORDER BY id') as $row) {
            $invoice = $this->invoices[(int) $row['id']] ?? null;
            if ($invoice === null || isset($this->parents[(int) $row['id']])) {
                continue;
            }
            $this->move(MoneyEventKind::CreditIn, -WhmcsMassPay::cents($row['credit']), MoneyTrail::CAUSE_INVOICE_REDUCED,
                $credited[(int) $row['id']] ?? self::date($row['datepaid']) ?? self::date($row['date']),
                ['client_id' => $invoice['client_id'], 'currency_code' => $invoice['currency_code'], 'invoice_id' => (int) $row['id']],
                dayOnly: isset($credited[(int) $row['id']]));
            $this->summary['negative_invoices']++;
        }
    }

    /** A paid credit purchase (WHMCS «Add Funds») gave its customer the credit, each line once (GrantBoughtCreditService). */
    private function purchases(): void
    {
        $items = DB::table('invoice_items')->where('reference_type', Credit::class)->whereNull('applied_at')
            ->whereIn('invoice_id', DB::table('invoices')->where('status', Invoice::STATUS_PAID)->select('id'))
            ->orderBy('id')->get(['id', 'invoice_id', 'line_total']);
        foreach ($items as $item) {
            $invoice = $this->invoices[(int) $item->invoice_id];
            $cents = WhmcsMassPay::cents($item->line_total);
            // WHMCS's datepaid, else when its money came (a datepaid of 0000-00-00 in old data, ADV-523-5), else its date.
            $at = $invoice['paid_at'] ?? DB::table('invoice_transactions')->where('invoice_id', $item->invoice_id)->where('status', 'succeeded')
                ->max('created_at') ?? $invoice['created_at'];
            DB::table('invoice_items')->where('id', $item->id)->update(['applied_at' => $at ?? now()]);
            if ($cents <= 0) {
                continue;
            }
            $this->move(MoneyEventKind::CreditIn, $cents, MoneyTrail::CAUSE_PURCHASE, $at,
                ['client_id' => $invoice['client_id'], 'currency_code' => $invoice['currency_code'], 'invoice_id' => (int) $item->invoice_id]);
            $this->summary['purchases']++;
        }
    }

    /** The moves WHMCS kept with no invoice to rebuild them from: staff's, a refund to credit, a downgrade's. */
    private function history(): void
    {
        $currencies = $this->clientCurrencies();
        foreach ($this->rows('SELECT id, clientid, date, description, amount FROM tblcredit WHERE amount <> 0 ORDER BY date, id') as $row) {
            $kind = self::kind((string) $row['description']);
            $currency = $currencies[(int) $row['clientid']] ?? null;
            // WHMCS's «Mass Invoice Payment Credit for Invoice #N» names the child its parent's money went to. When no
            // parent of that child is one here (one WHMCS refunded in full comes as an invoice of its own, its children
            // paid from credit), the credit it gave is journaled (round 3 of #523); else the plan has it already.
            $child = $kind === 'mass_pay' && preg_match('/#(\d+)/', (string) $row['description'], $found) ? (int) $found[1] : null;
            $notParent = $child !== null && ($this->massPayParentsOf()[$child] ?? []) !== []
                && array_filter($this->massPayParentsOf()[$child], fn (int $parent) => isset($this->parents[$parent])) === [];
            $massPayOf = $child;
            if ((in_array($kind, self::FROM_INVOICES, true) && !$notParent) || $currency === null || isset($this->creditRowsUsed[(int) $row['id']])) {
                continue;
            }
            $cents = WhmcsMassPay::cents($row['amount']);
            $cause = match (true) {
                $notParent => MoneyTrail::CAUSE_OVERPAYMENT,
                $kind === 'refund' && $cents > 0 => MoneyTrail::CAUSE_REFUND_TO_CREDIT,
                $kind === 'downgrade' && $cents > 0 => MoneyTrail::CAUSE_DOWNGRADE,
                default => MoneyTrail::CAUSE_STAFF_ADJUSTMENT,
            };
            $this->move($cents > 0 ? MoneyEventKind::CreditIn : MoneyEventKind::CreditOut, abs($cents), $cause, self::date($row['date']),
                ['client_id' => (int) $row['clientid'], 'currency_code' => $currency]
                    + ($notParent && isset($this->invoices[$massPayOf]) ? ['invoice_id' => $massPayOf] : []),
                note: 'WHMCS: ' . trim((string) $row['description']),
                dayOnly: strlen(trim((string) $row['date'])) <= 10);
            $this->summary['from_history']++;
        }
    }

    /**
     * The balance after each move, in the order they were made; where the moves do not add up to the balance imported
     * (WHMCS's), one adjustment to it.
     *
     * @return list<array{client_id: int, currency_code: string, history: int, balance: int, last: ?string}>
     */
    private function balances(): array
    {
        $balances = DB::table('credits')->get(['client_id', 'currency_code', 'amount'])
            ->mapWithKeys(fn ($credit) => [$credit->client_id . '|' . $credit->currency_code => WhmcsMassPay::cents($credit->amount)])->all();
        $history = [];
        $first = [];
        $last = [];
        foreach (array_filter($this->moves, self::movesCredit(...)) as $move) {
            $key = $move['client_id'] . '|' . $move['currency_code'];
            $history[$key] = ($history[$key] ?? 0) + self::effect($move);
            if ($move['occurred_at'] !== null) {
                $first[$key] = min($first[$key] ?? $move['occurred_at'], $move['occurred_at']);
                $last[$key] = max($last[$key] ?? $move['occurred_at'], $move['occurred_at']);
            }
        }

        $adjustments = [];
        $now = now()->toDateTimeString();
        foreach (array_unique([...array_keys($balances), ...array_keys($history)]) as $key) {
            [$clientId, $currency] = explode('|', (string) $key, 2);
            $moved = $history[$key] ?? 0;
            $balance = $balances[$key] ?? 0;
            if ($moved === $balance) {
                continue;
            }
            // Credit WHMCS has no record of getting was there before it was used: the history opens with it. Credit
            // that went with no record is gone now.
            $difference = $balance - $moved;
            $this->move($difference > 0 ? MoneyEventKind::CreditIn : MoneyEventKind::CreditOut, abs($difference), MoneyTrail::CAUSE_OPENING_BALANCE,
                $difference > 0 ? ($first[$key] ?? $now) : $now, ['client_id' => (int) $clientId, 'currency_code' => $currency],
                note: 'WHMCS balance ' . number_format($balance / 100, 2, '.', '') . ', what its history adds up to '
                    . number_format($moved / 100, 2, '.', '') . ': the balance kept.');
            $this->moves[array_key_last($this->moves)]['opening'] = $difference > 0;
            $adjustments[] = ['client_id' => (int) $clientId, 'currency_code' => $currency, 'history' => $moved, 'balance' => $balance, 'last' => $last[$key] ?? null];
        }
        usort($adjustments, fn (array $a, array $b) => [$b['last'] ?? '', $a['client_id']] <=> [$a['last'] ?? '', $b['client_id']]);

        // The balance after each move, in time order per customer.
        $running = [];
        foreach ($this->inTimeOrder($this->moves) as $index) {
            if (!self::movesCredit($this->moves[$index])) {
                continue;
            }
            $key = $this->moves[$index]['client_id'] . '|' . $this->moves[$index]['currency_code'];
            $running[$key] = ($running[$key] ?? 0) + self::effect($this->moves[$index]);
            $this->moves[$index]['credit_balance_after'] = $running[$key] / 100;
        }

        return $adjustments;
    }

    /** @param  array<string, mixed>  $move */
    private static function movesCredit(array $move): bool
    {
        return in_array($move['kind'], [MoneyEventKind::CreditIn->value, MoneyEventKind::CreditOut->value], true);
    }

    /** @param  array<string, mixed>  $move */
    private static function effect(array $move): int
    {
        return ($move['kind'] === MoneyEventKind::CreditIn->value ? 1 : -1) * $move['cents'];
    }

    /**
     * The keys of $rows in time order, by day: an opening balance first; a move WHMCS dated by the day only before
     * that day's timed money if it brought credit, after it if it took some (it was spent from what came that day,
     * ADV-523-4); then by time, then as written. An undated one last.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    private function inTimeOrder(array $rows): array
    {
        $key = function (int $index) use ($rows): array {
            $row = $rows[$index];
            $at = $row['occurred_at'] ?? '9999-12-31 23:59:59';
            $band = match (true) {
                !empty($row['opening']) => 0,
                empty($row['day_only']) => 2,
                $row['kind'] === MoneyEventKind::CreditIn->value => 1,
                default => 3,
            };

            return [substr($at, 0, 10), $band, substr($at, 11), $index];
        };
        $order = array_keys($rows);
        usort($order, fn (int $a, int $b) => $key($a) <=> $key($b));

        return $order;
    }

    /** A move of credit. @param  array<string, mixed>  $on */
    private function move(MoneyEventKind $kind, int $cents, string $cause, mixed $at, array $on, ?string $group = null, ?string $note = null, bool $dayOnly = false): void
    {
        $this->add($kind, $cents, $at, $on + ['group_reference' => $group, 'cause' => $cause], $note, $dayOnly);
    }

    /** @param  array<string, mixed>  $on */
    private function add(MoneyEventKind $kind, int $cents, mixed $at, array $on, ?string $note = null, bool $dayOnly = false): void
    {
        $this->moves[] = ['kind' => $kind->value, 'amount' => $cents / 100, 'source' => MoneyTrail::SOURCE_IMPORT,
            'occurred_at' => $at === null ? null : Carbon::parse($at)->toDateTimeString()]
            + $on + ['client_id' => null, 'currency_code' => null, 'invoice_id' => null, 'invoice_transaction_id' => null,
                'group_reference' => null, 'cause' => null, 'credit_balance_after' => null, 'cents' => $cents, 'note' => $note, 'day_only' => $dayOnly];
    }

    /** Every event in time order (the journal's ids follow it); a move's note apart, by its event's id. */
    private function insert(): void
    {
        $now = now()->toDateTimeString();
        $rows = $this->moves;
        $pending = [];
        foreach ($this->inTimeOrder($rows) as $index) {
            $row = $rows[$index];
            $note = $row['note'] ?? null;
            unset($row['cents'], $row['note'], $row['opening'], $row['day_only']);
            $row['occurred_at'] ??= $now;
            if ($note === null) {
                $pending[] = $row;
                if (count($pending) === 1000) {
                    DB::table('money_events')->insert($pending);
                    $pending = [];
                }

                continue;
            }
            if ($pending !== []) {
                DB::table('money_events')->insert($pending);
                $pending = [];
            }
            // Free text apart from the journal, so it can be cleared (MoneyTrail::creditMove, §9).
            $id = DB::table('money_events')->insertGetId($row);
            DB::table('money_event_notes')->insert(['money_event_id' => $id, 'note' => $note, 'created_at' => $now, 'updated_at' => $now]);
        }
        if ($pending !== []) {
            DB::table('money_events')->insert($pending);
        }
    }

    /** @return array<int, string> invoice id => when credit was last applied to it (tblcredit; given back to the customer: !$taken) */
    private function appliedDates(bool $taken = true): array
    {
        $dates = [];
        foreach ($this->rows('SELECT relid, MAX(date) date FROM tblcredit WHERE relid > 0 AND amount ' . ($taken ? '<' : '>')
            . " 0 AND description LIKE 'Credit Applied to Invoice%' GROUP BY relid") as $row) {
            if (($date = self::date($row['date'])) !== null) {
                $dates[(int) $row['relid']] = $date;
            }
        }

        return $dates;
    }

    /** @var array<int, list<int>>|null child invoice => the WHMCS Mass Pay invoices that billed it */
    private ?array $massPayParentsOf = null;

    /** @return array<int, list<int>> */
    private function massPayParentsOf(): array
    {
        if ($this->massPayParentsOf === null) {
            $this->massPayParentsOf = [];
            foreach ($this->rows("SELECT invoiceid, relid FROM tblinvoiceitems WHERE type = 'Invoice' AND relid > 0") as $row) {
                $this->massPayParentsOf[(int) $row['relid']][] = (int) $row['invoiceid'];
            }
        }

        return $this->massPayParentsOf;
    }

    /** @return array<int, string> customer id => its currency, for the customers imported */
    private function clientCurrencies(): array
    {
        $imported = DB::table('clients')->pluck('id')->flip();
        $currencies = [];
        foreach ($this->rows('SELECT c.id, cur.code FROM tblclients c JOIN tblcurrencies cur ON cur.id = c.currency') as $row) {
            if (isset($imported[(int) $row['id']])) {
                $currencies[(int) $row['id']] = (string) $row['code'];
            }
        }

        return $currencies;
    }

    /**
     * Rows of a query; none from a WHMCS without the table or column (an old version, a test fixture). Any other failure
     * stops the import: an empty history read as «no moves» would turn into one adjustment per customer.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        try {
            return $this->whmcs->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if (self::isMissing($e)) {
                return [];
            }
            throw $e;
        }
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
