<?php

namespace App\Services\Domains;

use App\Admin\Resources\DomainResource;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Helpers\NotificationHelper;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Services\Domains\Ordering\DomainOrderFulfilment;
use App\Services\Invoice\CancelOpenInvoices;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Who holds a domain name (owner 2026-11-08, docs/domains.md D-25). A row active, in grace or in redemption holds it; a
 * pending order once it is paid (its line on a paid invoice, free as ordered, or sent to its registrar), and an expired
 * row until the end of its registry's recovery window (owner 2026-11-08: expiry + grace + redemption, its TLD's or its
 * own days, Domain::recoveryEndsOn; 30 days when not known) or while a renewal of it is paid and waiting for its
 * registrar. An expired row past its window and unpaid orders do not: another customer may order the name, as in WHMCS. The database keeps one holder per name (`live_fqdn`, unique), so whoever
 * pays first holds it:
 *
 * - claim(): a pending order paid (or about to be sent), or an expired row's renewal paid, takes the name, or is refused
 *   because another row holds it. A refused order goes the way a registrar's refusal does (refuse(): staff's Pending
 *   Module Action, and they refund or credit its money); never sent. A refused renewal goes to staff before its charge.
 * - release(): its payment refunded or set back to unpaid, a row not sent holds the name no more; nor an expired row
 *   past its recovery window (the cron's domains_superseded step, daily: `live_fqdn` cannot read the date).
 * - closeOthers(): once a row holds the name, the rows of it that no longer do (an expired one past its window, an
 *   unpaid order of anyone) are closed as cancelled with a note for staff; an unpaid order's open invoice line is cancelled as staff's
 *   «Cancel Order» does. A pending order sent to its registrar, busy, or with money on its invoice (or paid meanwhile) is left to staff, told.
 */
final class DomainNameHold
{
    /** Its note on the domain (for staff) and the meta key that says by which row it was closed. */
    public const META = 'name_taken';

    /**
     * The pending order $domain, paid or about to be sent, holds its name from now on: true, or false when another row
     * holds the name already (whoever paid first). So does an expired row inside its recovery window (D-25) or whose
     * renewal is paid and waiting for its registrar (ADV-DNH-1: its customer paid first; the registry still has it as
     * theirs). Inside a caller's transaction,
     * a savepoint: a refusal undoes nothing of the caller's.
     */
    public static function claim(Domain $domain): bool
    {
        if ($domain->holdsName()) {
            return true;
        }
        if (!self::mayHold($domain->status)) {
            return false;
        }
        try {
            $held = DB::transaction(function () use ($domain) {
                $fresh = Domain::query()->lockForUpdate()->find($domain->id);
                if ($fresh === null || !self::mayHold($fresh->status)
                    || ($fresh->status === DomainStatus::Expired && !$fresh->inRecoveryWindow() && !self::renewalWaiting($fresh))) {
                    return false;
                }
                if ($fresh->name_held_at === null) {
                    $fresh->forceFill(['name_held_at' => now()])->save();
                }

                return $fresh->name_held_at;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
        if ($held === false) {
            return false;
        }
        $domain->forceFill(['name_held_at' => $held])->syncOriginalAttribute('name_held_at');

        return true;
    }

    /** A status whose row holds its name only by name_held_at: a pending order paid, an expired row recoverable or renewed. */
    private static function mayHold(DomainStatus $status): bool
    {
        return $status->isPending() || $status === DomainStatus::Expired;
    }

    /**
     * A paid renewal of $domain waiting for its registrar (its line not applied yet, its invoice paid): an expired row
     * with one holds its name (ADV-DNH-1).
     */
    public static function renewalWaiting(Domain $domain): bool
    {
        return $domain->exists && DomainRenewalService::waitingLines()->where('reference_id', $domain->id)->exists();
    }

    /**
     * Its payment no longer stands (ADV-DNH-4: refunded, charged back, set back to unpaid): a pending order not sent, or
     * an expired row whose renewal is no longer paid, holds its name no more, so another customer may order it. One
     * sent to its registrar, a renewal's transfer, or free as ordered keeps it. Under its registrar lock (one being sent
     * now keeps it): busy, false, and the cron's domains_superseded step tries again. True when released.
     */
    public static function release(int $domainId): bool
    {
        $lock = Cache::lock("domain-registrar:{$domainId}", 60);
        if (!$lock->get()) {
            return false;
        }
        try {
            return DB::transaction(function () use ($domainId) {
                $fresh = Domain::query()->lockForUpdate()->find($domainId);
                if ($fresh === null || $fresh->name_held_at === null || self::entitled($fresh)) {
                    return false;
                }
                $fresh->forceFill(['name_held_at' => null])->save(); // audited

                return true;
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * It still has what gave it its hold: paid (or free), sent, a renewal's transfer; an expired row inside its recovery
     * window (D-25, owner 2026-11-08) or with its renewal paid.
     */
    private static function entitled(Domain $domain): bool
    {
        if ($domain->status === DomainStatus::Expired) {
            // A renewal staff closed «not renewed» keeps no hold (S6-DOMAINS-1): the name is free once the window ends.
            // Their Retry takes it back (claim()), if no one has it by then.
            return $domain->inRecoveryWindow() || DomainRenewalService::waitingLines()->where('reference_id', $domain->id)->get()
                ->contains(fn (InvoiceItem $line) => !DomainRenewalService::closedUnrenewed($line));
        }
        if (!$domain->status->isPending()) {
            return true; // holds by its status, or not at all: nothing to release
        }

        return !empty($domain->meta['renew_transfer']) || self::sent($domain)
            || DomainOrderFulfilment::paid($domain, DomainOrderFulfilment::lineOf($domain));
    }

    /** Sent to its registrar (or a charge there not confirmed): it may be done there. */
    private static function sent(Domain $domain): bool
    {
        return !empty($domain->meta['sent_at']) || $domain->registrarLogs()->whereIn('action', ['register', 'transfer'])
            ->whereIn('status', [DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::STATUS_OK])->exists();
    }

    /** A register, transfer, renew or restore at its registrar with no confirmed outcome: it may be under way there. */
    private static function unconfirmedCharge(Domain $domain): bool
    {
        return $domain->registrarLogs()->whereIn('action', ['register', 'transfer', 'renew', 'restore'])
            ->where('status', DomainRegistrarLog::STATUS_PENDING)->exists();
    }

    /**
     * The rows holding their name by a payment that no longer stands (ADV-DNH-4), or expired past their recovery window
     * (D-25): what release() would free, for the cron (daily; a refund written past the model, a release that found its
     * row busy).
     *
     * @return list<int>
     */
    public static function staleHolds(): array
    {
        return Domain::query()->whereNotNull('name_held_at')->whereIn('status', [DomainStatus::PendingRegister->value,
            DomainStatus::PendingTransfer->value, DomainStatus::Expired->value])->orderBy('id')->get()
            ->reject(fn (Domain $domain) => self::entitled($domain))->map(fn (Domain $domain) => (int) $domain->id)->values()->all();
    }

    /**
     * Expired rows inside their recovery window that hold nothing (REV2-DNH-2: set back past the model, or their days raised
     * after a release), latest expiry first: what claim() gives their name back to, for the cron (daily), unless another
     * row holds it by then.
     *
     * @return list<int>
     */
    public static function unheldRecoverable(): array
    {
        return Domain::query()->with('domainTld')->where('status', DomainStatus::Expired->value)->whereNull('name_held_at')
            ->whereNotNull('expires_at')->orderByDesc('expires_at')->orderByDesc('id')->get()
            ->filter(fn (Domain $domain) => $domain->inRecoveryWindow())->map(fn (Domain $domain) => (int) $domain->id)->values()->all();
    }

    /** The row that holds $fqdn, other than $except, if any. */
    public static function holder(string $fqdn, ?int $except = null): ?Domain
    {
        return Domain::query()->where('live_fqdn', $fqdn)->when($except !== null, fn ($q) => $q->whereKeyNot($except))->first();
    }

    /** Paid, but its name is another's: staff's Pending Module Action, as a registrar's refusal (never sent). */
    public static function refuse(Domain $domain, ?InvoiceItem $line): ModuleAction
    {
        $action = $domain->status === DomainStatus::PendingTransfer ? ModuleAction::DOMAIN_TRANSFER : ModuleAction::DOMAIN_REGISTER;
        $holder = self::holder($domain->fqdn, (int) $domain->id);

        return ModuleAction::domainFailed($domain, $line, $domain->effectiveRegistrarConnection()?->registrar,
            new RuntimeException(__('domains.order.name_held', ['domain' => $domain->fqdn, 'holder' => $holder !== null ? '#' . $holder->id : '?'])), $action);
    }

    /**
     * The rows of $holderId's name that no longer hold it, closed (cancelled, noted); staff told what was closed and what
     * was left to them.
     *
     * @return array{closed: list<int>, left: array<string, Throwable>}
     */
    public static function closeOthers(int $holderId, bool $tellStaff = true): array
    {
        $closed = [];
        $left = [];
        $holder = Domain::query()->find($holderId);
        if ($holder === null || !$holder->holdsName()) {
            return ['closed' => [], 'left' => []];
        }
        $others = Domain::query()->where('fqdn', $holder->fqdn)->whereKeyNot($holder->id)
            ->whereNotIn('status', DomainStatus::terminalValues())->orderBy('id')->get();
        foreach ($others as $other) {
            if ($other->holdsName()) {
                continue; // never: the database keeps one
            }
            try {
                if (self::closeOne($holder, $other, $left)) {
                    $closed[] = (int) $other->id;
                }
            } catch (Throwable $e) {
                report($e);
                $left["domain #{$other->id}"] = $e;
            }
        }
        if ($tellStaff && ($closed !== [] || $left !== [])) {
            self::tellStaff($holder, $closed, $left);
        }

        return ['closed' => $closed, 'left' => $left];
    }

    /** @param  array<string, Throwable>  $left */
    private static function closeOne(Domain $holder, Domain $other, array &$left): bool
    {
        // Under its registrar lock, as staff's Cancel Order (ADV-2 of #180): one being sent now is staff's (busy).
        $lock = Cache::lock("domain-registrar:{$other->id}", 60);
        if (!$lock->get()) {
            $left["domain #{$other->id}"] = new DisplayException("Domain #{$other->id} {$other->fqdn}: busy, not closed; its name is held by #{$holder->id}.");

            return false;
        }
        try {
            return DB::transaction(function () use ($holder, $other, &$left) {
                $fresh = Domain::query()->lockForUpdate()->find($other->id);
                // Read again: one ended or held meanwhile is not closed; nor when the holder no longer holds.
                if ($fresh === null || $fresh->status->isTerminal() || $fresh->holdsName()
                    || !Domain::query()->whereKey($holder->id)->where('live_fqdn', $holder->fqdn)->exists()) {
                    return false;
                }
                // A pending order sent to its registrar may be done there: staff's. Only a pending one (R-DOMAINS-OTHER-FIXES-1
                // of sweep 4): sent_at and the registration's log stay on a row for good once it is active, so an expired
                // row past its window, once ordered here, is closed as an imported one is (D-25). A charge at a registrar
                // not confirmed (a PENDING register/transfer/renew/restore log, e.g. RenewAsTransfer's or a late renewal's
                // on an expired row) is no leftover: it may be completing there, so it stays staff's on any row
                // (REV4-RDOF-1, G-DOMAINS-2 of sweep 5).
                if (($fresh->status->isPending() && self::sent($fresh)) || self::unconfirmedCharge($fresh)) {
                    $left["domain #{$fresh->id}"] = new DisplayException("Domain #{$fresh->id} {$fresh->fqdn}: sent to its registrar, not closed; its name is held by #{$holder->id}. Decide by hand.");

                    return false;
                }
                // Paid and refused (another paid first, refuse()): its money is staff's to refund or credit, from its
                // Pending Module Action; never closed here under them. Read under its lock (ADV-DNH-2: paid a moment ago).
                if ($fresh->status->isPending() && DomainOrderFulfilment::paid($fresh, DomainOrderFulfilment::lineOf($fresh))) {
                    $left["domain #{$fresh->id}"] = new DisplayException("Domain #{$fresh->id} {$fresh->fqdn}: paid, but its name is held by #{$holder->id}: refund or credit its order (Cancel Order).");

                    return false;
                }
                // An expired row whose renewal was paid (ADV-DNH-1; it holds by then, unless written past the model or
                // paid after the holder): that money is staff's to apply or refund, never closed under them.
                // An expired row inside its recovery window (D-25, owner 2026-11-08) is its customer's still: it holds by
                // then, unless written past the model (staff set it back from cancelled): never closed by another's order.
                if ($fresh->status === DomainStatus::Expired && $fresh->inRecoveryWindow()) {
                    $left["domain #{$fresh->id}"] = new DisplayException("Domain #{$fresh->id} {$fresh->fqdn}: expired and still recoverable at its registry (until "
                        . $fresh->recoveryEndsOn()?->toDateString() . "), but its name is held by #{$holder->id}: decide by hand.");

                    return false;
                }
                if ($fresh->status === DomainStatus::Expired && self::renewalWaiting($fresh)) {
                    $left["domain #{$fresh->id}"] = new DisplayException("Domain #{$fresh->id} {$fresh->fqdn}: expired with a renewal paid, but its name is held by #{$holder->id}: renew it or refund the renewal.");

                    return false;
                }
                if ($fresh->status->isPending()) {
                    // Its order line, as staff's «Cancel Order» (credit paid on it goes back): one with money from a
                    // gateway or a transfer on it is left open, and the domain with it, for staff.
                    $open = Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)
                        ->whereHas('items', fn ($line) => $line->where('reference_type', Domain::class)->where('reference_id', $fresh->id))->get();
                    $kept = $open->isEmpty() ? [] : CancelOpenInvoices::handle($open, __('invoices.cancellation_reasons.domain_name_taken'), $fresh);
                    if ($kept !== []) {
                        $left += $kept;
                        $left["domain #{$fresh->id}"] = new DisplayException("Domain #{$fresh->id} {$fresh->fqdn}: its order's invoice was left open (money on it), so it was not closed; its name is held by #{$holder->id}.");

                        return false;
                    }
                    // Paid while it was being closed (R-DOMAINS-OTHER-FIXES-2 of sweep 4): paid() above is a plain read, and
                    // a payment committed before CancelOpenInvoices took the invoice's lock makes it return «nothing to
                    // give up on». Read again under that lock (held to our commit; a locking read sees the latest): its
                    // money is staff's, from the Pending Module Action its payment opens (refuse()), never closed here.
                    // Only a paid invoice that still bills this domain (G-DOMAINS-1 of sweep 5): one whose domain line was
                    // removed above and the rest then paid by its credit paid nothing for the domain, which is closed.
                    if ($open->isNotEmpty() && Invoice::query()->whereKey($open->modelKeys())->where('status', Invoice::STATUS_PAID)
                        ->whereHas('items', fn ($line) => $line->where('reference_type', Domain::class)->where('reference_id', $fresh->id))
                        ->lockForUpdate()->exists()) {
                        $left["domain #{$fresh->id}"] = new DisplayException("Domain #{$fresh->id} {$fresh->fqdn}: paid while it was being closed, but its name is held by #{$holder->id}: refund or credit its order (Cancel Order).");

                        return false;
                    }
                }
                $was = $fresh->status->value;
                $note = sprintf('%s: closed (was %s): the name is held by domain #%d since then (D-25, who pays first).', now()->toDateString(), $was, $holder->id);
                $fresh->forceFill(['status' => DomainStatus::Cancelled, 'notes' => trim(($fresh->notes ?? '') . "\n" . $note),
                    'meta' => [self::META => ['by' => (int) $holder->id, 'was' => $was, 'at' => now()->toIso8601String()]] + ($fresh->meta ?? [])])->save(); // audited
                if ($was !== DomainStatus::Expired->value) {
                    $fresh->order?->refreshStatus(unpaid: true);
                }

                return true;
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<int>  $closed
     * @param  array<string, Throwable>  $left
     */
    private static function tellStaff(Domain $holder, array $closed, array $left): void
    {
        try {
            $url = DomainResource::getUrl('view', ['record' => $holder->id], panel: 'admin');
            $lines = array_map(fn (int $id) => e("Domain #{$id}: closed as cancelled."), $closed);
            foreach ($left as $why) {
                $lines[] = e($why->getMessage());
            }
            NotificationHelper::sendSystemEmailNotification("Domain name now held: {$holder->fqdn}",
                e("{$holder->fqdn} is held by domain #{$holder->id} (paid or registered first). Its other rows that no longer hold it:")
                . '<br>' . implode('<br>', $lines) . '<br><a href="' . e($url) . '">' . e($holder->fqdn) . '</a>');
        } catch (Throwable $e) {
            report($e); // the rows are what count
        }
    }
}
