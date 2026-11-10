<?php

namespace App\Services\Service;

use App\Exceptions\DisplayException;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Service;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Support\AfterCommit;
use Illuminate\Database\Eloquent\Collection;

/**
 * Staff's «Revive: paid after termination» (docs/termination.md): a service the cron terminated whose account is still
 * on the server (its TerminateJob stopped: money came since its due date) runs again. Each step is the existing one:
 * the status as staff's own change (a hold of Suspend Days when still past due, ServiceObserver), the paid invoices as
 * any paid invoice (ProcessPaidInvoiceService: the period is given, the line applied), the server by its job.
 * Not done here, staff's by hand: addons that ended with it, its product's stock.
 */
final class RevivePaidTermination
{
    /** @return Collection<int, Invoice> the paid invoices it will apply: those DunningRules::moneySinceDue found */
    public static function paidInvoices(Service $service): Collection
    {
        if ($service->expires_at === null) {
            return new Collection;
        }

        return Invoice::query()->where('status', Invoice::STATUS_PAID)->where('paid_at', '>=', $service->expires_at)
            ->whereHas('items', fn ($line) => $line->where('reference_type', Service::class)->where('reference_id', $service->id)->whereNull('applied_at'))
            ->orderBy('id')->get();
    }

    public static function handle(Service $service): void
    {
        $service = $service->fresh() ?? $service;
        if ($service->status !== Service::STATUS_TERMINATED) {
            throw new DisplayException(__('Only a terminated service is revived.'));
        }
        $invoices = self::paidInvoices($service); // read before its due date moves
        $service->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);
        foreach ($invoices as $invoice) {
            (new ProcessPaidInvoiceService)->handle($invoice);
        }
        ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'terminate')->whereNull('subject')
            ->where('last_error', 'like', TerminateJob::STOPPED . '%')
            ->update(['status' => ModuleAction::STATUS_RESOLVED, 'resolved_at' => now(), 'resolution' => 'revived by staff', 'updated_at' => now()]);
        AfterCommit::job(new UnsuspendJob($service->fresh()));
    }
}
