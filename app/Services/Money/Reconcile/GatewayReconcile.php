<?php

namespace App\Services\Money\Reconcile;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\GatewayRefund;
use App\Models\GatewayTransaction;
use App\Models\InvoiceTransaction;
use App\Services\Invoice\RecordPaymentService;
use App\Support\Redact;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * The daily comparison of a gateway's own list of transactions with what was recorded here (owner 2026-11-12,
 * docs/payments-tracking.md «Συμφωνία gateway»): what bank alerts give for transfers, for PayPal. It reads the gateway
 * (GETs only) and this site, and keeps every transaction it read in gateway_transactions with how it stands here (the
 * «PayPal transactions» page), for the money check to repeat what differs and staff to see to it. It never records, credits, refunds or cancels anything: staff decide.
 *
 * By the gateway's id only, three kinds: at the gateway and not here; here as the gateway's payment and not in its
 * list; on both sides with another amount or currency. A difference that is no longer one closes by itself.
 */
final class GatewayReconcile
{
    public const STATE_KEY = 'gateway_reconcile';

    public const DEFAULT_DAYS = 7;

    /** As far back as one run looks (a rehearsal's --days): the gateway is asked a month at a time. */
    public const MAX_DAYS = 90;

    /** A payment the gateway took this recently may still be on its way here (its webhook): not judged yet. */
    public const WEBHOOK_GRACE_MINUTES = 60;

    /** A payment recorded here this close to the end of the gateway's list may not be in it yet. */
    public const REPORT_MARGIN_MINUTES = 15;

    /** When the gateway does not say up to when its list is complete. */
    public const UNSAID_LAG_HOURS = 6;

    /** The money check says so when a gateway's list was not read for this long (a daily run missed, then a second). */
    public const STALE_HOURS = 36;

    /** A day more is read than judged: a payment recorded here just after the start was taken just before it. */
    private const LOOKUP_MARGIN_DAYS = 1;

    /**
     * The enabled gateways whose list can be read and whose «Reconcile from» is filled.
     *
     * @return list<array{gateway: Gateway, extension: ListsTransactions}>
     */
    public static function gateways(): array
    {
        $found = [];
        foreach (Gateway::query()->where('enabled', true)->with('settings')->orderBy('id')->get() as $gateway) {
            $class = '\\MyCS\\Extensions\\Gateways\\' . $gateway->extension . '\\' . $gateway->extension;
            // Asked of its settings first: a gateway that is not compared is never built for it (the money check runs this).
            if (trim((string) $gateway->settings->firstWhere('key', 'reconcile_from')?->value) === ''
                || !class_exists($class) || !is_subclass_of($class, ListsTransactions::class)) {
                continue;
            }
            $extension = ExtensionHelper::getExtension('gateway', (string) $gateway->extension, $gateway->settings);
            if ($extension instanceof ListsTransactions) {
                $found[] = ['gateway' => $gateway, 'extension' => $extension];
            }
        }

        return $found;
    }

    /** @return array<string, array{read_at: ?string, error: ?string, told: bool}> what was last read of each gateway, by its extension */
    public static function state(): array
    {
        return (array) Cache::get(self::STATE_KEY, []);
    }

    /**
     * Read one gateway's list and bring gateway_transactions up to it. A list that cannot be read changes nothing but
     * the state (the error, kept short and without ids: the same text from day to day).
     *
     * @return array{read: bool, error: ?string, first_failure: bool, new: int, resolved: int, open: int}
     */
    public function run(Gateway $gateway, ListsTransactions $extension, ?int $days = null, ?CarbonImmutable $now = null): array
    {
        $name = (string) $gateway->extension;
        $now ??= CarbonImmutable::now();
        $state = self::state();
        $was = $state[$name] ?? ['read_at' => null, 'error' => null, 'told' => false];
        try {
            $from = self::moment($gateway->settings->firstWhere('key', 'reconcile_from')?->value);
            $set = (int) $gateway->settings->firstWhere('key', 'reconcile_days')?->value;
            $days ??= $set > 0 ? $set : self::DEFAULT_DAYS; // empty or not a number: the default, never one day
            $days = max(1, min(self::MAX_DAYS, $days));
            $start = $now->subDays($days)->startOfDay()->max($from);
            if ($start->gte($now)) { // «from» is still to come (set ahead of the cut-over): nothing to compare yet
                $state[$name] = ['read_at' => $now->toIso8601String(), 'error' => null, 'told' => false];
                Cache::forever(self::STATE_KEY, $state);

                return ['read' => true, 'error' => null, 'first_failure' => false, 'new' => 0, 'resolved' => 0, 'open' => $this->open($name)];
            }
            $statement = $extension->transactions($start->subDays(self::LOOKUP_MARGIN_DAYS), $now);
            // Within the same catch: a comparison that breaks half way is said to staff as a list not read.
            [$new, $resolved] = $this->compare($name, $statement, $start, $now);
        } catch (Throwable $e) {
            report($e);
            $state[$name] = ['read_at' => $was['read_at'] ?? null, 'error' => self::short($e), 'told' => true];
            Cache::forever(self::STATE_KEY, $state);

            return ['read' => false, 'error' => mb_substr(Redact::text($e->getMessage()), 0, 300), 'first_failure' => !$was['told'],
                'new' => 0, 'resolved' => 0, 'open' => $this->open($name)];
        }

        $state[$name] = ['read_at' => $now->toIso8601String(), 'error' => null, 'told' => false];
        Cache::forever(self::STATE_KEY, $state);

        return ['read' => true, 'error' => null, 'first_failure' => false, 'new' => $new, 'resolved' => $resolved, 'open' => $this->open($name)];
    }

    private function open(string $gateway): int
    {
        return GatewayTransaction::query()->where('gateway', $gateway)->where('status', GatewayTransaction::OPEN)->count();
    }

    /** @return array{int, int} new differences (or open again), resolved */
    private function compare(string $gateway, GatewayStatement $statement, CarbonImmutable $start, CarbonImmutable $now): array
    {
        $zone = (string) config('app.timezone');
        $settled = $now->subMinutes(self::WEBHOOK_GRACE_MINUTES);
        // In the site's zone: created_at is compared as its wall clock, and PayPal says its moment in its own (ADV-810-6).
        $until = ($statement->completeUntil ?? $now->subHours(self::UNSAID_LAG_HOURS))->setTimezone($zone)->min($now)->subMinutes(self::REPORT_MARGIN_MINUTES);
        $new = $resolved = 0;
        $seen = [];
        $listed = []; // every payment the gateway lists, completed or not

        foreach ($statement->lines as $line) {
            if ($line->what === GatewayLine::PAYMENT) {
                $listed[self::key($line->id)] = true;
            }
            if (!$line->completed || $line->at->lt($start) || $line->at->gt($settled)) {
                continue;
            }
            $judged = $this->judge($gateway, $line);
            if ($judged !== null) {
                $seen[] = $this->keep($gateway, $line->what, $line->id, $judged + [
                    'occurred_at' => $line->at->setTimezone($zone), 'amount' => $line->amount, 'currency_code' => $line->currency,
                    'agreement' => $line->agreement, 'parent_reference' => $line->parent,
                ], $now, $new, $resolved);
            }
        }

        if ($until->gt($start)) {
            // A row the gateway's own list once gave is at the gateway, whatever this run's span reaches: its payment was
            // taken days before it was recorded here (recorded late by staff, an eCheck), and created_at only stands in
            // for the gateway's date (ADV-810-1, ADV-810-2).
            $onceListed = GatewayTransaction::query()->where('gateway', $gateway)->where('what', GatewayLine::PAYMENT)
                ->where('kind', '!=', GatewayTransaction::NOT_AT_GATEWAY)->pluck('reference')
                ->mapWithKeys(fn ($reference) => [self::key((string) $reference) => true])->all();
            foreach ($this->recordedPayments($gateway)->whereBetween('created_at', [$start, $until])->with('invoice:id,currency_code')->get() as $payment) {
                $key = self::key((string) $payment->transaction_id);
                if (!isset($listed[$key]) && !isset($onceListed[$key])) {
                    $seen[] = $this->keep($gateway, GatewayLine::PAYMENT, (string) $payment->transaction_id, [
                        'kind' => GatewayTransaction::NOT_AT_GATEWAY, 'occurred_at' => $payment->created_at, 'amount' => $payment->amount,
                        'currency_code' => $payment->invoice?->currency_code, 'agreement' => null, 'parent_reference' => null,
                        'recorded_amount' => $payment->amount, 'recorded_currency' => $payment->invoice?->currency_code, 'invoice_id' => $payment->invoice_id,
                    ], $now, $new, $resolved);
                }
            }
        }

        // An open difference this run did not come upon again: judged from what is recorded here now, so that one staff
        // dealt with closes whatever its age.
        foreach (GatewayTransaction::query()->where('gateway', $gateway)->where('status', GatewayTransaction::OPEN)->whereNotIn('id', $seen)->orderBy('id')->get() as $row) {
            if ($row->kind === GatewayTransaction::NOT_AT_GATEWAY) {
                if (!$this->recordedPayments($gateway)->where('transaction_id', $row->reference)->exists()) {
                    $row->delete(); // gone from here too (deleted, edited): on neither side
                    $resolved++;
                } elseif ($until->gt($start) && $row->occurred_at->between($start, $until)) {
                    // Within what was read and not missing from it: the gateway lists it now. Older than that, it stays.
                    $row->update(['kind' => GatewayTransaction::MATCHED, 'status' => GatewayTransaction::OK, 'resolved_at' => $now]);
                    $resolved++;
                }

                continue;
            }
            $judged = $this->judge($gateway, new GatewayLine($row->what, $row->reference, CarbonImmutable::instance($row->occurred_at),
                (string) $row->amount, (string) $row->currency_code, true, $row->parent_reference, $row->agreement));
            if ($judged !== null) {
                $this->keep($gateway, $row->what, $row->reference, $judged, $now, $new, $resolved, seenNow: false);
            }
        }

        return [$new, $resolved];
    }

    /**
     * Succeeded payments recorded on the gateway (one staff removed since too), with the gateway's id.
     *
     * @return Builder<InvoiceTransaction>
     */
    private function recordedPayments(string $gateway): Builder
    {
        return InvoiceTransaction::query()->where('status', InvoiceTransactionStatus::Succeeded->value)->where('is_credit_transaction', false)
            ->whereNotNull('transaction_id')->where('transaction_id', '!=', '')
            ->whereHas('gateway', fn ($gw) => $gw->withTrashed()->where('extension', $gateway))
            ->orderBy('id');
    }

    /**
     * How a line of the gateway stands here: recorded the same, not recorded, or with another amount or currency. Null
     * while its payment is on its way (processing): judged once it is settled.
     *
     * @return array{kind: string, recorded_amount: ?string, recorded_currency: ?string, invoice_id: ?int}|null
     */
    private function judge(string $gateway, GatewayLine $line): ?array
    {
        $missing = ['kind' => GatewayTransaction::NOT_RECORDED, 'recorded_amount' => null, 'recorded_currency' => null, 'invoice_id' => null];
        $cents = RecordPaymentService::toCents($line->amount);

        if ($line->what === GatewayLine::PAYMENT) {
            // The gateway's own row, succeeded or still processing (its webhook completes it), or one staff recorded by
            // hand with no gateway: never another gateway's row with the same id (as the legacy IPN reads it, #694).
            /** @var Collection<int, InvoiceTransaction> $rows */
            $rows = InvoiceTransaction::query()->where('transaction_id', $line->id)->where('is_credit_transaction', false)
                ->where(fn ($ours) => $ours
                    ->where(fn ($own) => $own->whereHas('gateway', fn ($gw) => $gw->withTrashed()->where('extension', $gateway))
                        ->whereIn('status', [InvoiceTransactionStatus::Succeeded->value, InvoiceTransactionStatus::Processing->value]))
                    ->orWhere(fn ($hand) => $hand->whereNull('gateway_id')->where('status', InvoiceTransactionStatus::Succeeded->value)))
                ->with('invoice:id,currency_code')->orderBy('id')->get();
            if ($rows->isEmpty()) {
                return $missing;
            }
            $succeeded = $rows->where('status', InvoiceTransactionStatus::Succeeded);
            $first = $succeeded->first();
            if ($first === null) {
                return null; // on its way
            }
            $recorded = (int) $succeeded->sum(fn (InvoiceTransaction $row) => RecordPaymentService::toCents($row->amount));
            $currency = strtoupper((string) $first->invoice?->currency_code);

            return ['kind' => $recorded === $cents && $currency === $line->currency ? GatewayTransaction::MATCHED : GatewayTransaction::MISMATCH,
                'recorded_amount' => self::money($recorded), 'recorded_currency' => $currency ?: null, 'invoice_id' => $first->invoice_id];
        }

        if ($line->what === GatewayLine::REFUND) {
            // Recorded only when it was: a row that recorded nothing (another currency, nothing left on its payment) or
            // waits for a payment that is not here is staff's (the legacy IPN's rule, #694 F8).
            $refund = GatewayRefund::query()->where('gateway', $gateway)->where('reference', $line->id)
                ->whereIn('origin', GatewayRefund::RECORDED_REFUND_ORIGINS)
                ->where(fn ($recorded) => $recorded->where('recorded_amount', '>', 0)->orWhereIn('origin', [GatewayRefund::ORIGIN_HAND, GatewayRefund::ORIGIN_IMPORT]))
                ->first();
            if ($refund === null) {
                return $missing;
            }
            $recorded = RecordPaymentService::toCents($refund->amount);
            $currency = strtoupper((string) $refund->currency_code);

            return ['kind' => $recorded === $cents && ($currency === '' || $currency === $line->currency) ? GatewayTransaction::MATCHED : GatewayTransaction::MISMATCH,
                'recorded_amount' => self::money($recorded), 'recorded_currency' => $currency ?: null, 'invoice_id' => self::invoiceOf($refund)];
        }

        // A reversal or chargeback is recorded here under its dispute's id: known by the payment it took back.
        $known = GatewayRefund::query()->where('gateway', $gateway)
            ->where(fn ($it) => $it->where('reference', $line->id)
                ->when($line->parent !== null, fn ($query) => $query->orWhere(fn ($ofPayment) => $ofPayment->where('payment_reference', $line->parent)
                    ->whereIn('origin', [GatewayRefund::ORIGIN_CHARGEBACK, GatewayRefund::ORIGIN_CHARGEBACK_WON]))))
            ->first();

        return $known === null ? $missing
            : ['kind' => GatewayTransaction::MATCHED, 'recorded_amount' => null, 'recorded_currency' => null, 'invoice_id' => self::invoiceOf($known)];
    }

    /**
     * Write a transaction as it stands now. A difference is open when new, when it was fine before, or when it is
     * another difference than the one staff marked known (recorded since, with a wrong amount: theirs to see again).
     * One that is a difference no more is fine, and says since when.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function keep(string $gateway, string $what, string $reference, array $attributes, CarbonImmutable $now, int &$new, int &$resolved, bool $seenNow = true): int
    {
        $row = GatewayTransaction::query()->firstOrNew(['gateway' => $gateway, 'what' => $what, 'reference' => $reference]);
        if (in_array($attributes['kind'], GatewayTransaction::DIFFERENCES, true)) {
            if (!$row->exists || $row->status === GatewayTransaction::OK
                || ($row->status === GatewayTransaction::ACKNOWLEDGED && $row->kind !== $attributes['kind'])) {
                $row->fill(['status' => GatewayTransaction::OPEN, 'resolved_at' => null, 'acknowledged_by' => null, 'acknowledged_at' => null]);
                $new++;
            }
        } elseif ($row->exists && $row->status !== GatewayTransaction::OK) {
            $resolved += $row->status === GatewayTransaction::OPEN ? 1 : 0;
            $row->fill(['status' => GatewayTransaction::OK, 'resolved_at' => $now]);
        } elseif (!$row->exists) {
            $row->status = GatewayTransaction::OK;
        }
        $row->fill($attributes + ($seenNow ? ['last_seen_at' => $now] : []))->save();

        return (int) $row->id;
    }

    private static function invoiceOf(GatewayRefund $refund): ?int
    {
        $id = $refund->invoice_transaction_id === null ? null : InvoiceTransaction::query()->whereKey($refund->invoice_transaction_id)->value('invoice_id');

        return $id === null ? null : (int) $id;
    }

    /** An id as the database compares it (MariaDB: whatever its case, and the spaces after it). */
    private static function key(string $id): string
    {
        return strtoupper(trim($id));
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** «Reconcile from»: a date or a date and time, in the site's time zone. */
    public static function moment(mixed $value): CarbonImmutable
    {
        $value = trim((string) $value);
        try {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', $value)) {
                throw new InvalidArgumentException('not a date');
            }

            return CarbonImmutable::parse($value, (string) config('app.timezone'));
        } catch (Throwable) {
            throw new InvalidArgumentException('«Reconcile from» is not a date (YYYY-MM-DD or YYYY-MM-DD HH:MM).');
        }
    }

    /** The error without what changes from one try to the next (a debug id, a time): the HTTP status and the gateway's name for it. */
    private static function short(Throwable $e): string
    {
        if ($e instanceof ConnectionException) {
            return 'no connection';
        }
        if ($e instanceof InvalidArgumentException) {
            return $e->getMessage();
        }
        $public = get_object_vars($e);
        if (is_int($public['status'] ?? null)) {
            return trim('HTTP ' . $public['status'] . ' ' . (is_string($public['name'] ?? null) ? $public['name'] : ''));
        }

        return class_basename($e);
    }
}
