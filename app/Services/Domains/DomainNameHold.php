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
 * pending order once it is paid (its line on a paid invoice, free as ordered, or sent to its registrar). Expired rows and
 * unpaid orders do not: another customer may order the name, as in WHMCS. The database keeps one holder per name
 * (`live_fqdn`, unique), so whoever pays first holds it:
 *
 * - claim(): a pending order paid (or about to be sent) takes the name, or is refused because another row holds it.
 *   Refused, it goes the way a registrar's refusal does (refuse(): staff's Pending Module Action, and they refund or
 *   credit its money); never sent.
 * - closeOthers(): once a row holds the name, the rows of it that no longer do (an expired one, an unpaid order of
 *   anyone) are closed as cancelled with a note for staff; an unpaid order's open invoice line is cancelled as staff's
 *   «Cancel Order» does. A row sent to its registrar, busy, or with money on its invoice is left to staff, told.
 */
final class DomainNameHold
{
    /** Its note on the domain (for staff) and the meta key that says by which row it was closed. */
    public const META = 'name_taken';

    /**
     * The pending order $domain, paid or about to be sent, holds its name from now on: true, or false when another row
     * holds the name already (whoever paid first). Inside a caller's transaction, a savepoint: a refusal undoes nothing
     * of the caller's.
     */
    public static function claim(Domain $domain): bool
    {
        if ($domain->holdsName()) {
            return true;
        }
        if (!$domain->status->isPending()) {
            return false;
        }
        try {
            $held = DB::transaction(function () use ($domain) {
                $fresh = Domain::query()->lockForUpdate()->find($domain->id);
                if ($fresh === null || !$fresh->status->isPending()) {
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
        // Under its registrar lock, as staff's Cancel Order (ADV-2 of #180): one being sent now, or sent already, or with
        // a charge not confirmed, may be done at the registrar: staff's.
        $lock = Cache::lock("domain-registrar:{$other->id}", 60);
        if (!$lock->get()) {
            $left["domain #{$other->id}"] = new DisplayException("Domain #{$other->id} {$other->fqdn}: busy, not closed; its name is held by #{$holder->id}.");

            return false;
        }
        try {
            $sent = !empty($other->meta['sent_at']) || $other->registrarLogs()->whereIn('action', ['register', 'transfer'])
                ->whereIn('status', [DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::STATUS_OK])->exists();
            if ($sent) {
                $left["domain #{$other->id}"] = new DisplayException("Domain #{$other->id} {$other->fqdn}: sent to its registrar, not closed; its name is held by #{$holder->id}. Decide by hand.");

                return false;
            }
            // Paid and refused (another paid first, refuse()): its money is staff's to refund or credit, from its Pending
            // Module Action; never closed here under them.
            if ($other->status->isPending() && DomainOrderFulfilment::paid($other, DomainOrderFulfilment::lineOf($other))) {
                $left["domain #{$other->id}"] = new DisplayException("Domain #{$other->id} {$other->fqdn}: paid, but its name is held by #{$holder->id}: refund or credit its order (Cancel Order).");

                return false;
            }

            return DB::transaction(function () use ($holder, $other, &$left) {
                $fresh = Domain::query()->lockForUpdate()->find($other->id);
                // Read again: one ended, paid or held meanwhile is not closed; nor when the holder no longer holds.
                if ($fresh === null || $fresh->status->isTerminal() || $fresh->holdsName()
                    || !Domain::query()->whereKey($holder->id)->where('live_fqdn', $holder->fqdn)->exists()) {
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
