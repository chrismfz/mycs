<?php

namespace App\Services\Order;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\OrderResource;
use App\Enums\DomainStatus;
use App\Helpers\NotificationHelper;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Service;

/**
 * WHMCS's «New Order Notification»: a customer placed an order at checkout, staff are told (the System Email Address),
 * with its lines, its invoice, the customer's notes and links to the order, the account and the invoice. Not for an
 * order staff create themselves. `mail_staff_new_order` turns it off.
 */
class StaffOrderNotice
{
    public static function send(Order $order, ?string $ip = null): void
    {
        if (!config('settings.mail_staff_new_order', true)) {
            return;
        }

        $order->loadMissing(['client', 'services.product', 'services.plan', 'domains']);
        $admin = fn (string $resource, string $page, int $id) => e($resource::getUrl($page, ['record' => $id], panel: 'admin'));

        $lines = $order->services->map(fn (Service $service) => '<li>' . e($service->label
            . ($service->domain ? ' · ' . $service->domain : '') . ($service->plan ? ' (' . $service->plan->name . ')' : '')) . '</li>')
            ->concat($order->domains->map(fn (Domain $domain) => '<li>' . e(match ($domain->status) {
                DomainStatus::PendingTransfer => 'Domain transfer',
                default => 'Domain registration',
            } . ': ' . $domain->fqdn . ' (' . $domain->years . ' ' . ($domain->years === 1 ? 'year' : 'years') . ')') . '</li>'))
            ->implode("\n");

        $invoice = $order->invoice_id === null ? null : Invoice::query()->find($order->invoice_id);
        $rows = [
            'Order' => "<a href=\"{$admin(OrderResource::class, 'edit', $order->id)}\">#{$order->id}</a>",
            'Client' => "<a href=\"{$admin(ClientResource::class, 'view', $order->client_id)}\">" . e(($order->client->name ?? '') . ' (#' . $order->client_id . ')') . '</a>',
            'Date' => e($order->created_at?->toDateTimeString() ?? ''),
            'Invoice' => $invoice === null ? 'none (nothing to pay)'
                : "<a href=\"{$admin(InvoiceResource::class, 'view', $invoice->id)}\">#" . e((string) ($invoice->number ?? $invoice->id)) . '</a> ' . e((string) $invoice->formattedTotal),
        ];
        if ($ip !== null) {
            $rows['IP address'] = e($ip);
        }
        $table = collect($rows)->map(fn (string $value, string $label) => "<tr><th align=\"left\">{$label}</th><td>{$value}</td></tr>")->implode("\n");

        $notes = trim((string) $order->notes);
        $body = "<p>A new order was placed.</p>\n<table cellpadding=\"4\" cellspacing=\"0\">\n{$table}\n</table>\n"
            . "<p><strong>Items</strong></p>\n<ul>\n{$lines}\n</ul>\n"
            . '<p><strong>Notes</strong></p>' . ($notes === '' ? '<p>—</p>' : '<p>' . nl2br(e($notes)) . '</p>');

        NotificationHelper::sendSystemEmailNotification("New Order #{$order->id}" . ($order->client ? ' · ' . $order->client->name : ''), $body);
    }
}
