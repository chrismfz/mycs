<?php

namespace App\Services\Order;

use App\Enums\InvoiceTransactionStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Order;
use App\Models\Service;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\ServiceUpgrade\CancelPendingUpgrades;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Staff's «Cancel Order», «Cancel & Refund» and «Set as Fraud» (Σ1-b2, WHMCS). Its services end (cancelled, stock back,
 * pending upgrades withdrawn), as a cancellation request ends one; accounts already made stay unless staff ask to
 * terminate them. Their lines on open invoices are cancelled (CancelOpenInvoices: credit paid on them goes back). Paid
 * invoices are not touched, unless staff refund: then every payment of an invoice that bills only this order's
 * services goes back (the gateway, by hand, or to credit; credit paid goes back to credit). An invoice that also bills
 * something else is left to staff, as anything the invoices' own rules leave open.
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
        $reason = __('invoices.cancellation_reasons.order_cancelled');

        $services = DB::transaction(function () use ($order, $terminate, $fraud) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['status' => $fraud ? Order::STATUS_FRAUD : Order::STATUS_CANCELLED])->save();
            $order->setRawAttributes($locked->getAttributes(), true);

            $ended = [];
            foreach (Service::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get() as $service) {
                if ($service->isEnded()) {
                    continue;
                }
                $running = in_array($service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true);
                $service->update(['status' => Service::STATUS_CANCELLED]);
                if ($running && $terminate && $service->provisioningServer() !== null) {
                    TerminateJob::dispatch($service, false)->afterCommit();
                }
                if ($service->product?->stock !== null) {
                    $service->product->increment('stock', $service->quantity);
                }
                $ended[] = $service;
            }

            return $ended;
        });

        $left = [];
        foreach ($services as $service) {
            $left += CancelOpenInvoices::handle($service->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->get(), $reason, $service);
            $left += CancelPendingUpgrades::forService($service, $reason);
        }

        return $refund === null ? $left : $left + self::refund($order, $refund);
    }

    /** @return array<string, Throwable> */
    private static function refund(Order $order, string $method): array
    {
        $left = [];
        $ours = Service::query()->where('order_id', $order->id)->pluck('id')->all();
        $invoices = Invoice::query()->where('status', Invoice::STATUS_PAID)
            ->whereHas('items', fn ($items) => $items->where('reference_type', Service::class)->whereIn('reference_id', $ours))->get();
        foreach ($invoices as $invoice) {
            $others = $invoice->items()->where(fn ($items) => $items->where('reference_type', '!=', Service::class)
                ->orWhereNull('reference_type')->orWhereNotIn('reference_id', $ours))->exists();
            if ($others) {
                $left["refund #{$invoice->id}"] = new DisplayException("Invoice #{$invoice->id} also bills something else: refund its part by hand.");

                continue;
            }
            $payments = $invoice->transactions()->where('status', InvoiceTransactionStatus::Succeeded)->get();
            foreach ($payments as $payment) {
                $amount = (float) $payment->refundable_amount;
                if ($amount <= 0) {
                    continue;
                }
                try {
                    self::refundOne($payment, $amount, $method);
                } catch (Throwable $e) {
                    report($e);
                    $left["refund #{$invoice->id}/{$payment->id}"] = new DisplayException("Payment {$payment->id} of invoice #{$invoice->id} was not refunded: {$e->getMessage()}");
                }
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
