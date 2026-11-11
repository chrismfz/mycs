<?php

namespace App\Services\Order;

use App\Enums\DomainStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\Service;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use App\Support\AfterCommit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Staff's «Cancel Order», «Cancel & Refund» and «Set as Fraud» (Σ1-b2, WHMCS). Its services end (cancelled, stock back,
 * pending upgrades withdrawn), as a cancellation request ends one; accounts already made stay unless staff ask to
 * terminate them. Their lines on open invoices are cancelled (CancelOpenInvoices: credit paid on them goes back). Paid
 * invoices are not touched, unless staff refund: then the payments of **the order's own invoice** go back (WHMCS
 * refunds tblorders.invoiceid: renewals and upgrades paid later were delivered, review R2/ADV-1), paid or paid in part,
 * before the open ones are cancelled (ADV-3): the gateway, by hand, or to credit; credit paid goes back to credit. What
 * a refund or a chargeback already gave back is not given twice (refundable_amount). An order invoice that also bills
 * something else is left to staff, as anything the invoices' own rules leave open.
 *
 * Two cases a refund leaves to staff (sweep 8, owner 2026-11-11, KISS):
 * - The order's invoice was split (#780) and a part still bills the order: «Cancel & Refund» is refused whole, before
 *   anything is cancelled (splitParts). Staff refund each invoice and cancel by hand; no refund over many invoices here.
 * - A domain of the order is registered already (registeredDomains): the refund goes ahead and does not undo the
 *   registration. The registrar is never called and the domain keeps its status: staff are told, with a notice and a
 *   «check this» task (ModuleAction::DOMAIN_REFUNDED) that names the domain.
 *
 * Only its items still its customer's: one moved to another customer since (MoveToClient) is that customer's now, never
 * cancelled with the first one's order (adversarial R2-1 of #357).
 *
 * Locks: its services, then the order, as a payment or a create finishing does (service, then the order through the
 * observer; review R8).
 */
final class CancelOrder
{
    public const REFUND_METHODS = ['gateway', 'manual', 'credit'];

    /**
     * @param  string|null  $refund  null (no refund), or one of REFUND_METHODS
     * @return array<string, Throwable> what was left to staff (invoices left open, refunds that failed or were not made)
     */
    public static function handle(Order $order, bool $terminate = false, ?string $refund = null, bool $fraud = false): array
    {
        if ($refund !== null && !in_array($refund, self::REFUND_METHODS, true)) {
            throw new \InvalidArgumentException("Unknown refund method {$refund}");
        }
        if ($refund !== null && ($parts = self::splitParts($order)) !== []) {
            throw new DisplayException("Not cancelled, nothing refunded: invoice #{$order->invoice_id} of this order was split, and #" . implode(', #', $parts)
                . ' ' . (count($parts) === 1 ? 'bills' : 'bill') . ' a part of it. «Cancel & Refund» refunds one invoice only: refund each invoice from its page and cancel the services by hand («Cancel Order»).');
        }
        $reason = __('invoices.cancellation_reasons.order_cancelled');

        // Its pending domains under their registrar locks (adversarial ADV-2/2b of #180): one being sent now, or sent
        // already (a transfer stays pending for days), or with a charge not confirmed, is done (or may be) at the
        // registrar at our cost: kept for staff, never cancelled and refunded here.
        $locks = [];
        $kept = [];
        foreach (Domain::query()->where('order_id', $order->id)->where('client_id', $order->client_id)->whereIn('status', [DomainStatus::PendingRegister->value, DomainStatus::PendingTransfer->value])->get() as $domain) {
            $lock = Cache::lock("domain-registrar:{$domain->id}", 60);
            $sent = !empty($domain->meta['sent_at']) || $domain->registrarLogs()->whereIn('action', ['register', 'transfer'])
                ->whereIn('status', [DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::STATUS_OK])->exists();
            if ($sent || !$lock->get()) {
                $kept[$domain->id] = $domain->fqdn;

                continue;
            }
            $locks[] = $lock;
        }
        try {
            [$services, $domains] = self::cancel($order, $terminate, $fraud, array_keys($kept));
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
        $left = [];
        foreach ($kept as $id => $fqdn) {
            $left["domain #{$id}"] = new DisplayException("{$fqdn} was sent to its registrar (or is being sent): not cancelled. Decide by hand (keep it, or delete it at the registrar).");
        }

        // The refund first: an open invoice paid in part holds money CancelOpenInvoices would leave open. Not with a
        // domain kept: what it paid is spent at the registrar (refund its part by hand).
        if ($refund !== null && $kept !== []) {
            $left['refund'] = new DisplayException('Not refunded: a domain of this order was sent to its registrar. Refund by hand what is due.');
        } elseif ($refund !== null) {
            $left += self::refund($order, $refund);
            $left += self::tellOfRegisteredDomains($order);
        }
        foreach ($services as $service) {
            $left += CancelOpenInvoices::handle($service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get(), $reason, $service);
            $left += CancelPendingUpgrades::forService($service, $reason);
        }
        foreach ($domains as $domain) {
            $open = Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)
                ->whereHas('items', fn ($line) => $line->where('reference_type', Domain::class)->where('reference_id', $domain->id))->get();
            $left += CancelOpenInvoices::handle($open, $reason, $domain);
        }

        return $left;
    }

    /**
     * The invoices split off the order's own invoice that still bill something of the order (a service or a domain of
     * it), not cancelled: with one, «Cancel & Refund» is refused.
     *
     * @return list<int>
     */
    public static function splitParts(Order $order): array
    {
        if ($order->invoice_id === null) {
            return [];
        }
        $services = Service::query()->where('order_id', $order->id)->select('id');
        $domains = Domain::query()->where('order_id', $order->id)->select('id');

        return Invoice::query()->where('split_from_id', $order->invoice_id)->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->whereHas('items', fn ($line) => $line->where(fn ($ours) => $ours
                ->where(fn ($q) => $q->where('reference_type', Service::class)->whereIn('reference_id', $services))
                ->orWhere(fn ($q) => $q->where('reference_type', Domain::class)->whereIn('reference_id', $domains))))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Its domains registered (or transferred in) already: neither pending nor ended. Their registration was paid at the
     * registrar; a refund of the order does not undo it.
     *
     * @return Collection<int, Domain>
     */
    public static function registeredDomains(Order $order): Collection
    {
        return Domain::query()->where('order_id', $order->id)->where('client_id', $order->client_id)
            ->whereNotIn('status', [DomainStatus::PendingRegister->value, DomainStatus::PendingTransfer->value, ...DomainStatus::terminalValues()])
            ->orderBy('id')->get();
    }

    /**
     * After a refund: each registered domain told to staff, as a notice and as a «check this» task that stays until they
     * close it. Nothing is done at the registrar and the domain keeps its status.
     *
     * @return array<string, Throwable>
     */
    private static function tellOfRegisteredDomains(Order $order): array
    {
        $left = [];
        foreach (self::registeredDomains($order) as $domain) {
            $message = "{$domain->fqdn} is already registered at the registrar: the refund of order #{$order->id} does not undo the registration."
                . " It stays {$domain->status->value}: keep it (and bill it), or delete it at the registrar, by hand.";
            ModuleAction::query()->create(['domain_id' => $domain->id, 'action' => ModuleAction::DOMAIN_REFUNDED, 'status' => ModuleAction::STATUS_OPEN,
                'attempts' => 0, 'last_attempt_at' => now(), 'given_up_at' => now(), 'last_error' => $message]);
            $left["domain #{$domain->id}"] = new DisplayException($message);
        }

        return $left;
    }

    /**
     * @param  list<int>  $kept  its domains not to cancel (sent to their registrar)
     * @return array{0: list<Service>, 1: list<Domain>}
     */
    private static function cancel(Order $order, bool $terminate, bool $fraud, array $kept): array
    {
        return DB::transaction(function () use ($order, $terminate, $fraud, $kept) {
            $live = Service::query()->where('order_id', $order->id)->where('client_id', $order->client_id)->orderBy('id')->lockForUpdate()->get();
            // Its domains not registered or transferred yet (D8b, code review H2): cancelled with it, the name free again.
            $pending = Domain::query()->where('order_id', $order->id)->where('client_id', $order->client_id)->orderBy('id')->whereNotIn('id', $kept ?: [0])
                ->whereIn('status', [DomainStatus::PendingRegister->value, DomainStatus::PendingTransfer->value])->lockForUpdate()->get()
                ->reject(fn (Domain $domain) => !empty($domain->meta['sent_at'])); // sent meanwhile
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['status' => $fraud ? Order::STATUS_FRAUD : Order::STATUS_CANCELLED])->save();
            $order->setRawAttributes($locked->getAttributes(), true);

            $ended = [];
            foreach ($live as $service) {
                // Read again: an addon its parent ended earlier in this loop is ended already (ADV-634-2), its stock back.
                $service->refresh();
                if ($service->isEnded()) {
                    continue;
                }
                $running = in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true);
                $service->update(['status' => Service::STATUS_CANCELLED]);
                if ($running && $terminate && $service->hasServerActions()) {
                    AfterCommit::job(new TerminateJob($service, false));
                }
                if ($service->product?->stock !== null) {
                    $service->product->increment('stock', $service->quantity);
                }
                $ended[] = $service;
            }
            foreach ($pending as $domain) {
                $domain->update(['status' => DomainStatus::Cancelled]);
            }

            return [$ended, $pending->values()->all()];
        });
    }

    /** @return array<string, Throwable> */
    private static function refund(Order $order, string $method): array
    {
        $invoice = $order->invoice_id === null ? null : Invoice::query()->find($order->invoice_id);
        if ($invoice === null) {
            return []; // nothing was billed with it (a free order)
        }
        // Its own lines: its services' and its domains' (D8b, code review H2).
        $ours = Service::query()->where('order_id', $order->id)->where('client_id', $order->client_id)->pluck('id')->all();
        $ourDomains = Domain::query()->where('order_id', $order->id)->where('client_id', $order->client_id)->pluck('id')->all();
        $others = $invoice->items()->where(fn ($items) => $items->whereNull('reference_type')
            ->orWhere(fn ($q) => $q->whereNotIn('reference_type', [Service::class, Domain::class]))
            ->orWhere(fn ($q) => $q->where('reference_type', Service::class)->whereNotIn('reference_id', $ours ?: [0]))
            ->orWhere(fn ($q) => $q->where('reference_type', Domain::class)->whereNotIn('reference_id', $ourDomains ?: [0])))->exists();
        if ($others) {
            return ["refund #{$invoice->id}" => new DisplayException("Invoice #{$invoice->id} also bills something else: refund its part by hand.")];
        }
        $left = [];
        foreach ($invoice->transactions()->where('status', InvoiceTransactionStatus::Succeeded)->get() as $payment) {
            $amount = (float) $payment->refundable_amount;
            if ($amount <= 0) {
                continue; // refunded already (here, at the gateway's dashboard) or taken back by a chargeback
            }
            try {
                self::refundOne($payment, $amount, $method);
            } catch (Throwable $e) {
                report($e);
                $left["refund #{$invoice->id}/{$payment->id}"] = new DisplayException("Payment {$payment->id} of invoice #{$invoice->id} was not refunded: {$e->getMessage()}");
            }
        }

        return $left;
    }

    private static function refundOne(InvoiceTransaction $payment, float $amount, string $method): void
    {
        if ($payment->is_credit_transaction) {
            ExtensionHelper::refundToCredit($payment, $amount); // credit paid goes back to credit

            return;
        }
        match ($method) {
            'gateway' => ExtensionHelper::refund($payment, $amount),
            'manual' => ExtensionHelper::refundLocally($payment, $amount),
            'credit' => ExtensionHelper::refundToCredit($payment, $amount),
            default => throw new \InvalidArgumentException("Unknown refund method {$method}"),
        };
    }
}
