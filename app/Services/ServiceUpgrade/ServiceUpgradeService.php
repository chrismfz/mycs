<?php

namespace App\Services\ServiceUpgrade;

use App\Helpers\NotificationHelper;
use App\Jobs\Server\UpgradeJob;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Observers\ServiceObserver;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Invoice\LineTax;
use App\Services\Money\PaymentsLog;
use App\Services\Service\OptionTasks;
use App\Support\AfterCommit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

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
                // Done before: its server job, if the queue never took it, is asked for again (ADV-582-5).
                if ($locked?->status === ServiceUpgrade::STATUS_COMPLETED && $service !== null) {
                    ModuleAction::askAgainNotQueued($service);
                }

                return false;
            }
            $why = null;
            if ($service === null || !in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
                $why = 'the service no longer runs';
            } else {
                $locked->setRelation('service', $service);
                // The catalog lost the price it was charged at meanwhile (ADV-551-R2-2): never applied at a price it can't
                // read, for staff.
                if ($locked->needsStaff()) {
                    $why = 'the catalog has no price for it in the service\'s currency now';
                }
            }
            if ($why !== null) {
                $locked->update(['status' => ServiceUpgrade::STATUS_CANCELLED]);
                $original->setRawAttributes($locked->getAttributes(), true);
                $context = ['service_upgrade_id' => $locked->id, 'service_id' => $serviceId, 'invoice_id' => $locked->invoice_id, 'why' => $why];
                PaymentsLog::warning('Paid upgrade not applied (refund or credit it).', $context);
                DB::afterCommit(fn () => NotificationHelper::sendSystemEmailNotification('Paid upgrade not applied: needs staff attention', e(
                    "Upgrade #{$locked->id} of service #{$serviceId} (invoice #{$locked->invoice_id}) was paid, but {$why}: "
                    . 'it was not applied. Refund the payment or move it to credit.'
                )));

                return false;
            }
            $serviceUpgrade = $locked;
            // What it keeps and what it costs, worked out before the service changes (as ServiceUpgrade::calculatePrice).
            $options = $serviceUpgrade->resultingOptions();
            $optionsOnly = $serviceUpgrade->changesOptionsOnly();
            $newPrice = $serviceUpgrade->newRecurringPrice();
            $fromCatalog = $serviceUpgrade->repricesFromCatalog();
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
            $from = $productChanged ? $service->product?->name : null;
            // The service's history (owner 2026-11-04): what it was before, read before it changes.
            $before = ['from_product_id' => $service->product_id, 'from_plan_id' => $service->plan_id,
                'from_price' => number_format((float) $service->price, 2, '.', '')];
            $service->plan_id = $serviceUpgrade->plan_id;
            $service->product_id = $serviceUpgrade->product_id;
            if ($productChanged) {
                $service->next_plan_id = null; // a change of cycle set for the old product's plans (SVC-14b)
                // A coupon not kept on a product change ends here, as newRecurringPrice priced it (ADV-650-1).
                if ($service->coupon_id !== null && $service->coupon_expired_at === null && $service->coupon?->kept_on_product_change === false) {
                    $service->coupon_expired_at = now()->toImmutable();
                }
            }
            // Its order's open pro rata line is told below with the others it keeps (tellOfKeptPeriods), not by the
            // observer as a plan change by staff is (REV3-L-ROUNDING-TAX-2).
            ServiceObserver::$upgrading = true;
            try {
                $service->save();
            } finally {
                ServiceObserver::$upgrading = false;
            }

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
            if (!$optionsOnly || $fromCatalog) {
                // The catalog's now, not staff's (code review 3 of #551): also on another product or plan, priced by
                // the catalog, or the group's later prices would skip it (sweep UPGRADES-5).
                $service->price_locked = false;
            }
            $service->save();
            // The end of the days its money paid for (sweep 2, F-PLAN-CHANGE-2): its expiry now, a renewal paid ahead
            // included (calculatePrice charged to it, and a shortfall below bills the rest), read by DowngradeCredit.
            $serviceUpgrade->forceFill($before + ['to_price' => number_format((float) $service->price, 2, '.', ''), 'applied_at' => Carbon::now(),
                'paid_until' => $service->expires_at?->toDateString()])->save();
            $original->setRawAttributes($serviceUpgrade->getAttributes(), true);

            // Reprice all open renewal lines (drafts too) after the new recurring amount is saved; on a new product
            // their tax follows it too (Δ1: a product sold without tax, F8 of the Σ7b review). A line ChangeOpenLine keeps
            // at its price (R9) is reported by it, under its locks, for staff (review C1/C2 of #695).
            $kept = [];
            $onKept = function (InvoiceItem $billed, InvoiceItem $wouldBe) use (&$kept) {
                $kept[] = ['line' => $billed, 'price' => (string) $wouldBe->price, 'tax_rate' => $wouldBe->tax_rate, 'tax_reason' => $wouldBe->tax_reason];
            };
            $service->refreshOpenPeriods(retax: $productChanged, kept: $onKept);
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
                }, $onKept));

            // With this transaction: on the database queue, never lost between its commit and the queue (predeploy S4).
            if ($service->hasServerActions()) {
                AfterCommit::job(new UpgradeJob($service));
            }

            if ($shortfall !== null) {
                $this->billShortfall($serviceUpgrade, $service, $shortfall);
            }
            $this->tellOfKeptPeriods($serviceUpgrade, $service, $kept);

            // Its customer told, once it is committed (an upgrade, a downgrade or options; WHMCS confirms one).
            $id = (int) $service->id;
            // A mail that fails is reported and goes no further: the after-commit callbacks behind it still run (EVT-2).
            DB::afterCommit(function () use ($id, $from) {
                try {
                    $changed = Service::query()->with('product', 'plan', 'client')->find($id);
                    if ($changed?->client !== null) {
                        NotificationHelper::serviceUpgradedNotification($changed->client, $changed, $from);
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            });

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
        if ($upgrade->charged !== null) {
            // What the days invoiced were priced at, not the line's price: staff may have lowered it on the unpaid invoice
            // (a gesture), and that is not a renewal paid meanwhile (sweep 2, F-PLAN-CHANGE-3).
            $owed = BigDecimal::of(number_format((float) $upgrade->calculatePrice()->price, 2, '.', ''))->minus((string) $upgrade->charged);

            return $owed->isGreaterThan(0) ? (string) $owed->toScale(2) : null;
        }
        // The charge, not staff's discount on it (owner 2026-11-06): the discount was on the days invoiced, not on more.
        foreach (InvoiceItem::query()->where('invoice_id', $upgrade->invoice_id)->where('reference_type', ServiceUpgrade::class)
            ->where('reference_id', $upgrade->id)->where(fn ($line) => $line->whereNull('kind')->orWhere('kind', '!=', InvoiceItem::KIND_DISCOUNT))
            ->get(['price', 'quantity']) as $line) {
            $invoiced = $invoiced->plus(BigDecimal::of(number_format((float) $line->price, 2, '.', ''))->multipliedBy(max(1, (int) $line->quantity)));
        }
        $owed = BigDecimal::of(number_format((float) $upgrade->calculatePrice()->price, 2, '.', ''))->minus($invoiced);

        return $owed->isGreaterThan(0) ? (string) $owed->toScale(2) : null;
    }

    /**
     * A line that kept its price (ADV-A2-2) is staff's to settle, never billed apart (owner: never bill wrongly; an
     * invoice of its own outlived a cancellation or a downgrade, review 2): they are told, once it commits, the service,
     * the invoice, the period and the exact difference: the price the upgrade would have given the line less the price
     * it is billed at (× quantity; review C3 of #695: its actual price, a staff price change included), negative for a
     * downgrade, net (before VAT) as lines store it. A tax the new product would change (Δ1 retax) is told too, even with
     * no net difference (review 3 of #695). The lines are those ChangeOpenLine kept (C1), with or without a period (C2).
     *
     * @param  list<array{line: InvoiceItem, price: string, tax_rate: mixed, tax_reason: mixed}>  $periods
     */
    private function tellOfKeptPeriods(ServiceUpgrade $upgrade, Service $service, array $periods): void
    {
        $lines = [];
        $currency = $service->currency_code;
        foreach ($periods as $period) {
            $line = $period['line'];
            $kept = InvoiceItem::query()->with('invoice')->find($line->id);
            if ($kept === null || $kept->invoice === null || !in_array($kept->invoice->status, Invoice::OPEN_STATUSES, true)
                || (string) $kept->price !== (string) $line->price) {
                continue; // paid or given up later in this transaction, or repriced after all
            }
            $difference = BigDecimal::of((string) $period['price'])->minus(BigDecimal::of((string) $kept->price))
                ->multipliedBy(max(1, (int) $kept->quantity))->toScale(2, RoundingMode::HalfUp);
            $taxNow = self::taxOf($kept->tax_rate, $kept->tax_reason);
            $taxNew = self::taxOf($period['tax_rate'], $period['tax_reason']);
            if ($difference->isZero() && $taxNow === $taxNew) {
                continue;
            }
            $dates = $kept->period_start !== null && $kept->period_end !== null
                ? $kept->period_start->format('d/m/Y') . ' - ' . $kept->period_end->format('d/m/Y')
                : 'no period (an imported line)';
            PaymentsLog::warning('Upgrade of a service whose open renewal keeps its price (a payment or an Ekdosi document on it, or the first line of its order): staff settle the difference.',
                ['service_upgrade_id' => $upgrade->id, 'service_id' => $service->id, 'invoice_id' => $kept->invoice_id,
                    'invoice_item_id' => $kept->id, 'period' => $dates, 'difference' => (string) $difference, 'tax' => $taxNow, 'new_tax' => $taxNew]);
            $text = "invoice #{$kept->invoice_id}, period {$dates}: {$difference} {$currency} net (before VAT), its billed price "
                . number_format((float) $kept->price, 2, '.', '') . " {$currency} to its new price {$period['price']} {$currency}";
            if ($taxNow !== $taxNew) {
                $text .= ", and its tax would be {$taxNew} instead of {$taxNow}";
            }
            $lines[] = $text;
        }
        if ($lines === []) {
            return;
        }
        $body = "Upgrade #{$upgrade->id} of service #{$service->id} changed its price, but its open renewal invoice keeps each line at "
            . 'its billed price (a payment or an Ekdosi document is on it, or it is the first line of its order, which keeps its stub). Settle the difference to the new price by hand (bill it, '
            . 'or credit it if negative): ' . implode('; ', $lines) . '.';
        // As the customer's notice below (EVT-2, review C4 of #695): a mail that fails is reported, stops nothing.
        DB::afterCommit(function () use ($body) {
            try {
                NotificationHelper::sendSystemEmailNotification('Upgraded service\'s renewal kept its billed price: needs staff attention', e($body));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** A line's tax as staff read it: its rate (0 when none), and the reason of a zero rate. */
    private static function taxOf(mixed $rate, mixed $reason): string
    {
        $percent = (string) BigDecimal::of(is_numeric($rate) ? (string) $rate : '0')->strippedOfTrailingZeros() . '%';

        return $reason === null || $reason === '' ? $percent : "{$percent} ({$reason})";
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
