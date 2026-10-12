<?php

namespace App\Services\Invoice;

use App\Models\Credit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Services\Service\RenewServiceService;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
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
        $invoice->items->each(function ($item) use ($invoice, &$failures) {
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
                } elseif ($item->reference_type == Credit::class) {
                    DB::transaction(function () use ($invoice, $item) {
                        $user = $invoice->user()->lockForUpdate()->first();
                        // Given once per line (applied_at): a refund or a deletion of the payment takes back only
                        // credit that was given (RecordPaymentService::lockBoughtCredit).
                        $item = InvoiceItem::query()->whereKey($item->id)->lockForUpdate()->first();
                        if ($item === null || $item->applied_at !== null) {
                            return;
                        }
                        $item->forceFill(['applied_at' => now()])->save();
                        $credit = $user->credits()->where('currency_code', $invoice->currency_code)->lockForUpdate()->first();

                        if ($credit) {
                            $credit->setAttribute('amount', (string) BigDecimal::of((string) $credit->getRawOriginal('amount'))->plus($item->price));
                            $credit->save();
                        } else {
                            $user->credits()->create([
                                'currency_code' => $invoice->currency_code,
                                'amount' => $item->price,
                            ]);
                        }
                    });
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
