<?php

namespace App\Services\Service;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a service, whatever its status (audit sweep DATA-2, SVC-7, AUTH-9): one way for every path (its page,
 * the bulk actions, the order's services, the API). As WHMCS, a record staff remove, e.g. an account already
 * terminated on the server (WHM): with $terminate the server's account goes first (not for a pending one: it was
 * never created), and if the server refuses nothing is deleted (the username would be lost with a live account).
 *
 * Not while an open invoice of it (or of its pending upgrades) holds money a gateway or a transfer paid: staff refund
 * it or move it to credit first, or it would bill a service that is gone (R4/R5 of #82). Its gateway subscription is
 * cancelled first (it would keep charging, and its payments would find no service).
 *
 * Then, under the service's lock (service → upgrade → customer → invoice, docs/money-trail.md §2): its unpaid lines and
 * pending upgrades are cancelled, also one the cron billed a moment ago; its options and properties go; a service that
 * had not ended gives its stock back. Paid lines stay, as the history of what was paid.
 */
class DeleteService
{
    public function handle(Service $service, bool $terminate = false): void
    {
        if ($this->holdsMoney($service)) {
            throw new DisplayException(__('An open invoice of this service holds a payment: refund it or move it to credit first.'));
        }
        if ($terminate && !$service->isEnded() && $service->status !== Service::STATUS_PENDING && $service->product?->server_id !== null) {
            ExtensionHelper::terminateServer($service); // throws when the server refuses: nothing is deleted
        }
        if ($service->subscription_id && !$service->isEnded()) {
            ExtensionHelper::cancelSubscription($service); // throws when no gateway cancels it: nothing is deleted
        }

        $reason = __('invoices.cancellation_reasons.service_deleted');
        DB::transaction(function () use ($service, $reason) {
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }
            $left = CancelPendingUpgrades::forService($locked, $reason);
            $left += CancelOpenInvoices::handle($locked->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get(), $reason, $locked);
            if ($left !== []) {
                // Money landed meanwhile: nothing is deleted.
                throw new DisplayException(__('An open invoice of this service holds a payment: refund it or move it to credit first.'));
            }
            if (!$locked->isEnded() && $locked->product?->stock !== null) {
                $locked->product->increment('stock', $locked->quantity);
            }
            $locked->properties()->delete();
            $locked->delete(); // its options go in ServiceObserver::deleted
        });
    }

    /** An open invoice of it, or of a pending upgrade of it, with money from a gateway or a transfer (processing too). */
    private function holdsMoney(Service $service): bool
    {
        $money = fn ($invoices) => $invoices->whereIn('invoices.status', Invoice::OPEN_STATUSES)->whereHas('transactions', fn ($payments) => $payments
            ->where(fn ($paid) => $paid->where('status', InvoiceTransactionStatus::Succeeded)->where('is_credit_transaction', false))
            ->orWhere('status', InvoiceTransactionStatus::Processing));
        $upgradeInvoices = ServiceUpgrade::query()->where('service_id', $service->id)->where('status', ServiceUpgrade::STATUS_PENDING)->pluck('invoice_id');

        return $service->invoices()->where(fn ($invoices) => $money($invoices))->exists()
            || Invoice::query()->whereIn('id', $upgradeInvoices)->where(fn ($invoices) => $money($invoices))->exists();
    }
}
