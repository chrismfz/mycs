<?php

namespace App\Services\Invoice;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Service\RenewServiceService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Throwable;

class ProcessPaidInvoiceService
{
    /**
     * Handle the processing of a paid invoice.
     */
    public function handle(Invoice $invoice): void
    {
        // Update services if invoice is paid (suspended -> active etc.)
        $failures = [];
        $invoice->items->each(function ($item) use (&$failures) {
            try {
                if ($item->reference_type == Service::class) {
                    $service = $item->reference;
                    if (!$service || !($service instanceof Service)) {
                        return;
                    }
                    (new RenewServiceService)->handle($service, $item);
                } elseif ($item->reference_type == ServiceUpgrade::class) {
                    $serviceUpgrade = $item->reference;
                    if (!($serviceUpgrade instanceof ServiceUpgrade) || $serviceUpgrade->status !== ServiceUpgrade::STATUS_PENDING) {
                        return;
                    }

                    // Handle the upgrade
                    (new ServiceUpgradeService)->handle($serviceUpgrade);
                }
            } catch (Throwable $e) {
                report($e);
                $failures[] = $e;
            }
        });
        if ($failures !== []) {
            throw $failures[0];
        }
    }
}
