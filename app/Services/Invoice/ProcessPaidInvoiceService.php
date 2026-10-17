<?php

namespace App\Services\Invoice;

use App\Jobs\Domains\FulfilDomainOrderJob;
use App\Jobs\Domains\RenewDomainJob;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Service\RenewServiceService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessPaidInvoiceService
{
    /**
     * Apply a paid invoice: renew its services, do its upgrades. Each line is idempotent (applied_at, the upgrade's
     * status, read under locks), so the cron may run it again on an invoice whose apply_pending_since is still set.
     */
    public function handle(Invoice $invoice): void
    {
        // The marker this run applies: a newer one (paid -> pending -> paid meanwhile) is left for its own run.
        $marker = Invoice::query()->whereKey($invoice->getKey())->value('apply_pending_since');
        $failures = [];
        $invoice->items->each(function ($item) use (&$failures) {
            try {
                // A domain's renewal (D5b-2): renewed at its registrar by a job, after this commit; its line applied there
                // (applied_at), never here, and never this invoice's marker (D5b-1 review 1). Fees have nothing to do.
                if ($item->reference_type == Domain::class) {
                    if ($item->kind === null && $item->period_end !== null && $item->applied_at === null) {
                        $lineId = (int) $item->id;
                        DB::afterCommit(fn () => RenewDomainJob::dispatch($lineId));
                    }
                    // A domain ordered (D8b-2): registered or its transfer started at its registrar, after this commit.
                    if (in_array($item->kind, [InvoiceItem::KIND_REGISTER, InvoiceItem::KIND_TRANSFER], true) && $item->applied_at === null) {
                        $domainId = (int) $item->reference_id;
                        DB::afterCommit(fn () => FulfilDomainOrderJob::dispatch($domainId));
                    }

                    return;
                }
                if ($item->reference_type == Service::class) {
                    $service = $item->reference;
                    if (!$service || !($service instanceof Service)) {
                        return;
                    }
                    (new RenewServiceService)->handle($service, $item);
                } elseif ($item->reference_type == ServiceUpgrade::class) {
                    // ServiceUpgradeService locks the service and the upgrade and reads it again: the cron may apply the
                    // same invoice again (BIL-9); an upgrade is done once, and never on a service that no longer runs.
                    $serviceUpgrade = ServiceUpgrade::query()->find($item->reference_id);
                    if ($serviceUpgrade !== null) {
                        (new ServiceUpgradeService)->handle($serviceUpgrade);
                    }
                }
            } catch (Throwable $e) {
                report($e);
                $failures[] = $e;
            }
        });
        if ($failures !== []) {
            throw $failures[0];
        }
        // Applied (a line with nothing to apply counts too: a cancelled service, a one-time plan). Quietly: no invoice event.
        if ($marker !== null) {
            Invoice::query()->whereKey($invoice->getKey())->where('apply_pending_since', $marker)->update(['apply_pending_since' => null]);
        }
        $invoice->apply_pending_since = null;
        $invoice->syncOriginalAttribute('apply_pending_since');
    }
}
