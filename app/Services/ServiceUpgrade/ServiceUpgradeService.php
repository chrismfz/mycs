<?php

namespace App\Services\ServiceUpgrade;

use App\Jobs\Server\UpgradeJob;
use App\Models\ConfigOptionProduct;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Illuminate\Support\Facades\DB;

class ServiceUpgradeService
{
    /**
     * Handle the uploaded extension file.
     * The added file is always a zip file.
     */
    public function handle(ServiceUpgrade $serviceUpgrade): void
    {
        DB::transaction(function () use ($serviceUpgrade) {
            $serviceUpgrade->status = ServiceUpgrade::STATUS_COMPLETED;
            $serviceUpgrade->save();

            // Check if old product stock should be increased
            $service = $serviceUpgrade->service;
            if ($service->product->stock !== null) {
                $serviceUpgrade->service->product->increment('stock', $serviceUpgrade->service->quantity);
            }

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
            // recurring amount and their values from provisioning (audit sweep SVC-3). Only options the new product
            // does not have go.
            $keep = ConfigOptionProduct::query()->where('product_id', $service->product_id)->pluck('config_option_id')
                ->merge($serviceUpgrade->configs->pluck('config_option_id'))->unique()->all();
            $service->configs()
                ->whereNotIn('config_option_id', $keep)
                ->delete();

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
            // Legacy manual lines without a period retain their whole-cycle allocation on payment.
            $service->invoiceItems()->where(fn ($query) => $query->whereNull('period_start')->orWhereNull('period_end'))
                ->whereHas('invoice', fn ($query) => $query->whereIn('status', ['pending', 'draft']))
                ->each(function ($item) use ($service) {
                    $item->update(['price' => $service->price]);
                });

            if ($service->product->server) {
                UpgradeJob::dispatch($service);
            }
        });
    }
}
