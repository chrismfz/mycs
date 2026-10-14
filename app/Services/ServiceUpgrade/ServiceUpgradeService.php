<?php

namespace App\Services\ServiceUpgrade;

use App\Helpers\NotificationHelper;
use App\Jobs\Server\UpgradeJob;
use App\Models\ConfigOptionProduct;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Money\PaymentsLog;
use Illuminate\Support\Facades\DB;

class ServiceUpgradeService
{
    /**
     * Apply a paid upgrade: the new product/plan, the options it changed, the new recurring amount, the server.
     *
     * Locked service, then upgrade (docs/money-trail.md §2: service, customer, invoice, lines), and read again under
     * them: applied once, whoever calls it twice (the payment, the cron's paid_applied). A service that no longer runs
     * (cancelled, or never started) is not upgraded (audit sweep SVC-6): the upgrade is cancelled and its money is the
     * staff's to refund or credit.
     */
    public function handle(ServiceUpgrade $serviceUpgrade): bool
    {
        $original = $serviceUpgrade;

        return DB::transaction(function () use ($serviceUpgrade, $original) {
            $serviceId = ServiceUpgrade::query()->whereKey($serviceUpgrade->getKey())->value('service_id');
            $service = $serviceId === null ? null : Service::query()->whereKey($serviceId)->lockForUpdate()->first();
            $locked = ServiceUpgrade::query()->whereKey($serviceUpgrade->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->status !== ServiceUpgrade::STATUS_PENDING) {
                return false;
            }
            if ($service === null || !in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
                $locked->update(['status' => ServiceUpgrade::STATUS_CANCELLED]);
                $original->setRawAttributes($locked->getAttributes(), true);
                $context = ['service_upgrade_id' => $locked->id, 'service_id' => $serviceId, 'invoice_id' => $locked->invoice_id];
                PaymentsLog::warning('Paid upgrade of a service that no longer runs: not applied (refund or credit it).', $context);
                DB::afterCommit(fn () => NotificationHelper::sendSystemEmailNotification('Paid upgrade not applied: needs staff attention', e(
                    "Upgrade #{$locked->id} of service #{$serviceId} (invoice #{$locked->invoice_id}) was paid, but the service no longer runs: "
                    . 'it was not applied. Refund the payment or move it to credit.'
                )));

                return false;
            }
            $serviceUpgrade = $locked;
            $serviceUpgrade->status = ServiceUpgrade::STATUS_COMPLETED;
            $serviceUpgrade->save();
            $original->setRawAttributes($serviceUpgrade->getAttributes(), true);

            // Check if old product stock should be increased
            if ($service->product->stock !== null) {
                $service->product->increment('stock', $service->quantity);
            }

            $productChanged = (int) $service->product_id !== (int) $serviceUpgrade->product_id;
            $service->plan_id = $serviceUpgrade->plan_id;
            $service->product_id = $serviceUpgrade->product_id;
            $service->save();

            $service->refresh();

            // Decrease stock of new product if applicable
            if ($service->product->stock !== null) {
                $service->product->decrement('stock', $service->quantity);
            }

            // The service keeps every option the (new) product still has: the upgrade only offers the upgradable ones,
            // and deleting the rest (OS, location, extra IPs, hidden ones staff set) dropped their price from the
            // recurring amount and their values from provisioning (audit sweep SVC-3). On a product change, options the
            // new product does not have go; on the same product nothing goes, also an option staff unlinked since (an
            // imported WHMCS option): an upgrade never lowers the recurring amount by the back door. The upgrade's own
            // options are kept too (an upgrade made by staff or the API may carry one the product no longer links).
            if ($productChanged) {
                $keep = ConfigOptionProduct::query()->where('product_id', $service->product_id)->pluck('config_option_id')
                    ->merge($serviceUpgrade->configs->pluck('config_option_id'))->unique()->all();
                $service->configs()
                    ->whereNotIn('config_option_id', $keep)
                    ->delete();
            }

            // Update or create new configs
            foreach ($serviceUpgrade->configs as $config) {
                $service->configs()->updateOrCreate(
                    ['config_option_id' => $config->config_option_id],
                    ['config_value_id' => $config->config_value_id]
                );
            }

            $service->refresh();

            $service->price = $service->calculatePrice();
            $service->save();

            // Reprice all open renewal lines (drafts too) after the new recurring amount is saved.
            $service->refreshOpenPeriods();
            // Legacy manual lines without a period retain their whole-cycle allocation on payment. Under the payment
            // locks; an invoice with a payment on it keeps its line (R9).
            $service->invoiceItems()->where(fn ($query) => $query->whereNull('period_start')->orWhereNull('period_end'))
                ->whereHas('invoice', fn ($query) => $query->whereIn('status', Invoice::OPEN_STATUSES))
                ->get()
                ->each(fn ($item) => ChangeOpenLine::handle($item, fn ($line) => $line->price = $service->price));

            if ($service->provisioningServer()) {
                UpgradeJob::dispatch($service);
            }

            return true;
        });
    }
}
