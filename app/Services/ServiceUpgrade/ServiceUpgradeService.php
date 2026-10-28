<?php

namespace App\Services\ServiceUpgrade;

use App\Helpers\NotificationHelper;
use App\Jobs\Server\UpgradeJob;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Invoice\LineTax;
use App\Services\Money\PaymentsLog;
use App\Services\Service\OptionTasks;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
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
            $serviceUpgrade->setRelation('service', $service);
            // What it keeps and what it costs, worked out before the service changes (as ServiceUpgrade::calculatePrice).
            $options = $serviceUpgrade->resultingOptions();
            $optionsOnly = $serviceUpgrade->changesOptionsOnly();
            $newPrice = $serviceUpgrade->newRecurringPrice();
            $shortfall = $this->shortfall($serviceUpgrade);
            $serviceUpgrade->status = ServiceUpgrade::STATUS_COMPLETED;
            $serviceUpgrade->save();
            $original->setRawAttributes($serviceUpgrade->getAttributes(), true);

            // Check if old product stock should be increased
            if ($service->product->stock !== null) {
                $service->product->increment('stock', $service->quantity);
            }

            // Its options staff do by hand, before anything changes (a product change drops some: reviews of #296), and
            // after: a task for what it takes or gives back (OptionTasks).
            $staffOptions = OptionTasks::on($service);
            $productChanged = (int) $service->product_id !== (int) $serviceUpgrade->product_id;
            $service->plan_id = $serviceUpgrade->plan_id;
            $service->product_id = $serviceUpgrade->product_id;
            if ($productChanged) {
                $service->next_plan_id = null; // a change of cycle set for the old product's plans (SVC-14b)
            }
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
                $service->configs()
                    ->whereNotIn('config_option_id', array_keys($options))
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
            OptionTasks::changed($service, $staffOptions, OptionTasks::on($service));

            // Options only: the price it had and the options' difference (a staff price stays). Another product or
            // plan: its catalog price for the next renewal.
            $service->price = $optionsOnly ? $newPrice : $service->calculatePrice();
            $service->save();

            // Reprice all open renewal lines (drafts too) after the new recurring amount is saved; on a new product
            // their tax follows it too (Δ1: a product sold without tax, F8 of the Σ7b review).
            $service->refreshOpenPeriods(retax: $productChanged);
            // Legacy manual lines without a period retain their whole-cycle allocation on payment. Under the payment
            // locks; an invoice with a payment on it keeps its line (R9).
            // Not a charge of the service (an imported setup fee, promotion, addon or upgrade): it keeps its own amount.
            $service->invoiceItems()->where(fn ($query) => $query->whereNull('period_start')->orWhereNull('period_end'))
                ->where(fn ($query) => $query->whereNull('kind')->orWhere('kind', '!=', InvoiceItem::KIND_CHARGE))
                ->whereHas('invoice', fn ($query) => $query->whereIn('status', Invoice::OPEN_STATUSES))
                ->get()
                ->each(fn ($item) => ChangeOpenLine::handle($item, function ($line) use ($service, $productChanged) {
                    $line->price = $service->price;
                    if ($productChanged) {
                        $line->forceFill(LineTax::decide($line));
                    }
                }));

            if ($service->provisioningServer()) {
                UpgradeJob::dispatch($service);
            }

            if ($shortfall !== null) {
                $this->billShortfall($serviceUpgrade, $service, $shortfall);
            }

            return true;
        });
    }

    /**
     * What a paid upgrade still owes (F4 of the SVC-14 review): its invoice was made for the days left then; a renewal
     * paid before it moved the expiry on, so more days are now at the old price. Null when nothing more is owed (also
     * when days passed: the cron lowers the price of an open upgrade line, never the other way after payment).
     */
    private function shortfall(ServiceUpgrade $upgrade): ?string
    {
        if ($upgrade->invoice_id === null) {
            return null;
        }
        $invoiced = BigDecimal::zero();
        foreach (InvoiceItem::query()->where('invoice_id', $upgrade->invoice_id)->where('reference_type', ServiceUpgrade::class)
            ->where('reference_id', $upgrade->id)->get(['price', 'quantity']) as $line) {
            $invoiced = $invoiced->plus(BigDecimal::of(number_format((float) $line->price, 2, '.', ''))->multipliedBy(max(1, (int) $line->quantity)));
        }
        $owed = BigDecimal::of(number_format((float) $upgrade->calculatePrice()->price, 2, '.', ''))->minus($invoiced);

        return $owed->isGreaterThan(0) ? (string) $owed->toScale(2) : null;
    }

    /** The rest of an upgrade on an invoice of its own, as the upgrade's (`Livewire\Services\Upgrade`). */
    private function billShortfall(ServiceUpgrade $upgrade, Service $service, string $amount): void
    {
        $invoice = Invoice::query()->create([
            'currency_code' => $service->currency_code,
            'status' => Invoice::STATUS_UNPAID,
            'due_at' => Carbon::now()->addDays(7),
            'client_id' => $service->client_id,
        ]);
        $invoice->items()->create([
            'description' => 'Upgrade ' . $service->product->name . ': the days of a renewal paid meanwhile',
            'price' => $amount,
            'quantity' => 1,
            'reference_id' => $upgrade->id,
            'reference_type' => ServiceUpgrade::class,
        ]);
        PaymentsLog::warning('Paid upgrade owed more after a renewal paid before it: billed the rest.',
            ['service_upgrade_id' => $upgrade->id, 'service_id' => $service->id, 'invoice_id' => $invoice->id, 'amount' => $amount]);
    }
}
