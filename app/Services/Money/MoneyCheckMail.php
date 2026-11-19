<?php

namespace App\Services\Money;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\ServiceResource;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;

/**
 * The money check's mail, readable (owner 2026-11-19: «δεν το κατάλαβα… ποιος χρήστης, ποιο service, why?»). The findings
 * stay as the check words them (the log, the command and the once-a-week fingerprint read the same text); the mail adds,
 * by each rule, what it means, and under each finding who it is about: the customer, the service by its product and name,
 * the invoice by the number staff see (not only its id), each linked to its page in the admin.
 *
 * The references are read from the finding's own text («invoice #12», «service #7», «customer #3», «transaction #9»): every
 * rule names its records so, and a record no longer there is left as the text says it. Read-only, as the check.
 */
class MoneyCheckMail
{
    private const REFERENCE = '/\b(invoice|service|customer|transaction) #(\d+)/';

    /** @var array<string, array<int, Client|Invoice|InvoiceTransaction|Service|null>> */
    private array $records = [];

    /** @param  list<array{rule: string, subject: string, detail: string}>  $findings */
    public function html(array $findings): string
    {
        $html = '<p>' . e(__('The daily money check found what does not add up. Nothing was changed: each finding says what to do.')) . '</p>';
        foreach (collect($findings)->groupBy('rule') as $rule => $group) {
            $html .= '<h3 style="margin:18px 0 4px">' . e((string) $rule) . ' (' . count($group) . ')</h3>';
            $html .= '<p style="margin:0 0 8px;color:#555">' . e(self::meaning((string) $rule)) . '</p><ul style="padding-left:18px">';
            foreach ($group as $finding) {
                $html .= '<li style="margin-bottom:10px"><strong>' . $this->linked($finding['subject']) . '</strong>: ' . $this->linked($finding['detail']);
                if (($about = $this->about($finding['subject'] . ' ' . $finding['detail'])) !== '') {
                    $html .= '<br><span style="color:#555">' . $about . '</span>';
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        return $html . '<p style="color:#555">' . e(__('More in docs/money-trail.md §5.')) . '</p>';
    }

    /** What a rule means, in a line: what staff read before the finding itself. */
    public static function meaning(string $rule): string
    {
        return match ($rule) {
            'paid_balance' => __('A paid invoice whose payments do not match its total: it still owes, or holds more than its total.'),
            'open_settled' => __('An open invoice that its payments already cover.'),
            'cancelled_applied' => __('A cancelled invoice with a line that renewed its service.'),
            'cancelled_holds_money' => __('A cancelled invoice that still holds money (payments less refunds and credit).'),
            'paid_not_applied' => __('A paid invoice whose services were not renewed after a day.'),
            'paid_for_cancelled' => __('A paid invoice for a service that had ended: the payment bought nothing and wants a refund or credit.'),
            'open_for_cancelled' => __('An open invoice whose every service has ended.'),
            'credit_journal' => __('A customer credit balance that differs from its journal.'),
            'credit_negative' => __('A customer credit balance below zero.'),
            'payment_not_journaled' => __('A successful payment without its entry in the money journal.'),
            'journal_without_payment' => __('A payment in the money journal without its successful transaction.'),
            'refund_journal' => __('A payment whose refunded amount differs from the refunds in the journal.'),
            'over_refunded' => __('A payment refunded (or turned into credit) for more than it brought.'),
            'duplicate_gateway_id' => __('The same payment of a gateway recorded as successful more than once.'),
            'gateway_received' => __('A gateway request that never finished (a fatal error or a timeout).'),
            'line_totals' => __('An invoice line whose total is not price × quantity + tax.'),
            'service_unbilled' => __('An active service that runs without being paid or billed.'),
            'gateway_unreconciled' => __('A gateway list and this site disagree on a payment.'),
            default => '',
        };
    }

    /** The text, escaped, with each invoice and service it names as a link (an invoice by its number). */
    private function linked(string $text): string
    {
        return (string) preg_replace_callback(self::REFERENCE, function (array $match): string {
            $record = $this->record($match[1], (int) $match[2]);

            return match (true) {
                $record instanceof Invoice => self::link(InvoiceResource::getUrl('edit', ['record' => $record->id], panel: 'admin'),
                    $record->number && (string) $record->number !== (string) $record->id
                        ? "invoice {$record->number} (#{$record->id})" : "invoice #{$record->id}"),
                $record instanceof Service => self::link(ServiceResource::getUrl('edit', ['record' => $record->id], panel: 'admin'), $match[0]),
                $record instanceof Client => self::link(ClientResource::getUrl('view', ['record' => $record->id], panel: 'admin'), $match[0]),
                default => $match[0],
            };
        }, e($text));
    }

    /** Who and what a finding is about: its customer, its services by product and name, its invoices with their status. */
    private function about(string $text): string
    {
        preg_match_all(self::REFERENCE, $text, $matches, PREG_SET_ORDER);
        $client = null;
        $parts = [];
        foreach ($matches as [, $type, $id]) {
            $record = $this->record($type, (int) $id);
            if ($record instanceof InvoiceTransaction) {
                $record = $this->record('invoice', (int) $record->invoice_id);
            }
            $client ??= match (true) {
                $record instanceof Client => $record,
                $record instanceof Invoice, $record instanceof Service => $record->client,
                default => null,
            };
            $part = match (true) {
                $record instanceof Service => __('Service') . ': ' . self::link(ServiceResource::getUrl('edit', ['record' => $record->id], panel: 'admin'),
                    $record->mail_name) . ' (' . e((string) $record->status) . ')',
                $record instanceof Invoice => __('Invoice') . ': ' . self::link(InvoiceResource::getUrl('edit', ['record' => $record->id], panel: 'admin'),
                    $record->number ?: "#{$record->id}") . ' (' . e((string) $record->status) . ', ' . e($record->formattedTotal) . ')',
                default => null,
            };
            if ($part !== null && !in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }
        if ($client !== null) {
            array_unshift($parts, __('Customer') . ': ' . self::link(ClientResource::getUrl('view', ['record' => $client->id], panel: 'admin'),
                "#{$client->id} {$client->name}"));
        }

        return implode(' · ', $parts);
    }

    private function record(string $type, int $id): Client|Invoice|InvoiceTransaction|Service|null
    {
        if (!array_key_exists($id, $this->records[$type] ?? [])) {
            $this->records[$type][$id] = match ($type) {
                'invoice' => Invoice::query()->find($id),
                'service' => Service::query()->with('product')->find($id),
                'customer' => Client::query()->find($id),
                'transaction' => InvoiceTransaction::query()->find($id),
                default => null,
            };
        }

        return $this->records[$type][$id];
    }

    private static function link(string $url, string $text): string
    {
        return '<a href="' . e($url) . '">' . e($text) . '</a>';
    }
}
