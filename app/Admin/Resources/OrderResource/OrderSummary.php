<?php

namespace App\Admin\Resources\OrderResource;

use App\Admin\Resources\DomainResource;
use App\Admin\Resources\ServiceResource;
use App\Enums\DomainStatus;
use App\Enums\InvoiceTransactionStatus;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Order;
use App\Models\Service;

/**
 * What the order page shows above its form, as WHMCS's order view (owner 2026-10-19): the order's payment and its items,
 * each with its own status and whether its line on the order's invoice is paid. Read only: staff act with the page's
 * buttons and on the service or domain itself.
 */
final class OrderSummary
{
    /** The order's own invoice (Σ1-b2), with its lines and payments. */
    public static function invoice(Order $order): ?Invoice
    {
        // Read each time (code review: a memo outlived a payment): a few small queries per render.
        return $order->invoice_id === null ? null
            : Invoice::query()->with(['items.invoice.currency', 'transactions.gateway'])->find($order->invoice_id);
    }

    /** WHMCS «Payment Status»: of the order's invoice. */
    public static function paymentStatus(?Invoice $invoice): string
    {
        return match ($invoice?->status) {
            null => 'Nothing to pay',
            default => ucfirst((string) $invoice->status),
        };
    }

    /** WHMCS «Payment Method»: the gateways that paid its invoice (none yet: unpaid). */
    public static function paymentMethod(?Invoice $invoice): string
    {
        $names = $invoice?->transactions->where('status', InvoiceTransactionStatus::Succeeded)
            ->map(fn (InvoiceTransaction $payment) => $payment->gateway->name ?? ($payment->is_credit_transaction ? 'Credit' : 'Manual'))
            ->unique()->values()->all() ?? [];

        return $names === [] ? '—' : implode(', ', $names);
    }

    /** WHMCS «Promotion Code»: the coupons its services were ordered with. */
    public static function promotionCode(Order $order): string
    {
        $codes = $order->services->map(fn (Service $service) => $service->coupon?->code)->filter()->unique()->values()->all();

        return $codes === [] ? '—' : implode(', ', $codes);
    }

    /**
     * WHMCS «Order Items»: each service and domain with its billing cycle, its amount on the order's invoice, its status
     * and whether that line is paid.
     *
     * @return list<array{item: string, description: string, url: ?string, cycle: string, amount: string, status: string, payment: string}>
     */
    public static function items(Order $order, ?Invoice $invoice): array
    {
        $line = fn (string $type, int $id): ?InvoiceItem => $invoice?->items->first(fn (InvoiceItem $item) => $item->reference_type === $type && (int) $item->reference_id === $id);
        $amount = fn (?InvoiceItem $item): string => $item === null ? '—' : (string) $item->formattedTotal;
        $paid = fn (?InvoiceItem $item): string => $item === null ? '—' : self::paymentStatus($invoice);

        $services = $order->services->map(function (Service $service) use ($line, $amount, $paid) {
            $item = $line(Service::class, $service->id);

            return [
                'item' => 'Product/Service',
                'description' => trim(($service->product->name ?? $service->label) . ($service->quantity > 1 ? ' × ' . $service->quantity : '')
                    . "\n" . ($service->domain ?? '')),
                // A link only for staff who may open it (code review: no link to a 403).
                'url' => ServiceResource::canEdit($service) ? ServiceResource::getUrl('edit', ['record' => $service->id], panel: 'admin') : null,
                'cycle' => $service->plan->name ?? '—',
                'amount' => $amount($item),
                'status' => ucfirst((string) $service->status),
                'payment' => $paid($item),
            ];
        });
        $domains = $order->domains->map(function (Domain $domain) use ($line, $amount, $paid) {
            $item = $line(Domain::class, $domain->id);
            $transfer = $domain->status === DomainStatus::PendingTransfer || $domain->epp_code !== null
                || $item?->kind === InvoiceItem::KIND_TRANSFER;

            return [
                'item' => $transfer ? 'Domain transfer' : 'Domain registration',
                'description' => $domain->fqdn,
                'url' => DomainResource::canView($domain) ? DomainResource::getUrl('view', ['record' => $domain->id], panel: 'admin') : null,
                'cycle' => $domain->years . ' ' . ($domain->years === 1 ? 'year' : 'years'),
                'amount' => $amount($item),
                'status' => $domain->status->getLabel(),
                'payment' => $paid($item),
            ];
        });

        return $services->concat($domains)->values()->all();
    }
}
