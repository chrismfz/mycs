<?php

namespace App\Services\Service;

use App\Admin\Resources\ServiceResource;
use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * What the daily cron suspended and terminated for non-payment, one row per service, mailed to staff after the run
 * (as WHMCS's «Cron Job Activity»): they see what went down, and unsuspend or set «Do Not Suspend Until» on a
 * service they want kept running. Only on a day something happened; `cronjob_suspension_digest` turns it off.
 */
class SuspensionDigest
{
    /**
     * @param  list<int>  $suspended  services the run suspended (committed)
     * @param  list<int>  $terminated  services the run terminated (committed)
     */
    public static function send(array $suspended, array $terminated): void
    {
        // Suspended and terminated in the same run («minimum days suspended» 0): listed once, as terminated.
        $suspended = array_values(array_diff($suspended, $terminated));
        if (($suspended === [] && $terminated === []) || !config('settings.cronjob_suspension_digest', true)) {
            return;
        }

        $parts = [];
        if ($suspended !== []) {
            $parts[] = count($suspended) . ' suspended';
        }
        if ($terminated !== []) {
            $parts[] = count($terminated) . ' terminated';
        }

        $body = self::section('Suspended for non-payment', $suspended, withInvoices: true)
            . self::section('Terminated for non-payment', $terminated, withInvoices: false)
            . '<p>To keep a service running: open it, Unsuspend, and set «Do Not Suspend Until» so the cron leaves it until then.</p>';

        NotificationHelper::sendSystemEmailNotification('Cron Job: services ' . implode(', ', $parts), $body);
    }

    /** @param  list<int>  $ids */
    private static function section(string $title, array $ids, bool $withInvoices): string
    {
        if ($ids === []) {
            return '';
        }

        $services = Service::query()->with(['client', 'product'])->whereKey($ids)->orderBy('id')->get();
        $rows = $services->map(function (Service $service) use ($withInvoices) {
            $url = e(ServiceResource::getUrl('edit', ['record' => $service->id], panel: 'admin'));
            $cells = [
                "<a href=\"{$url}\">#{$service->id}</a>",
                e(($service->client->name ?? '') . ' (#' . $service->client_id . ')'),
                e(trim($service->label . ($service->domain ? ' · ' . $service->domain : ''))),
                e($service->expires_at?->toDateString() ?? '—'),
            ];
            if ($withInvoices) {
                $cells[] = e(self::unpaidInvoices($service)) ?: '—';
            }

            return '<tr><td>' . implode('</td><td>', $cells) . '</td></tr>';
        })->implode("\n");

        $headers = ['Service', 'Client', 'Product', 'Due date'];
        if ($withInvoices) {
            $headers[] = 'Unpaid invoices';
        }
        $head = '<tr><th align="left">' . implode('</th><th align="left">', $headers) . '</th></tr>';

        return '<p><strong>' . e($title) . ' (' . count($ids) . ')</strong></p>'
            . "<table cellpadding=\"4\" cellspacing=\"0\" border=\"1\">\n{$head}\n{$rows}\n</table>";
    }

    /** «#123 10,00 € due 2026-10-01; …»: the service's unpaid invoices, what is left to pay on each. */
    private static function unpaidInvoices(Service $service): string
    {
        /** @var Collection<int, Invoice> $invoices */
        $invoices = $service->invoices()->where('invoices.status', Invoice::STATUS_UNPAID)->with('transactions')->orderBy('invoices.due_at')->get()
            ->unique('id'); // HasManyThrough its lines: an invoice with two lines of the service comes once

        return $invoices->map(fn (Invoice $invoice) => '#' . ($invoice->number ?? $invoice->id) . ' ' . $invoice->formattedRemaining
            . ($invoice->due_at ? ' due ' . $invoice->due_at->toDateString() : ''))->implode('; ');
    }
}
