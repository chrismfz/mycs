<?php

namespace App\Services\Money;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\ServiceResource;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Support\LogText;
use Illuminate\Database\Eloquent\Model;

/**
 * The money check's mail, readable (owner 2026-11-19, the devbox's «service #12 … invoice #9000004, which is refunded»:
 * «δεν το κατάλαβα… ποιος χρήστης, ποιο service, why?»). The findings stay as the check words them (the log, the command
 * and the once-a-week fingerprint read the same text); the mail adds, by each rule, what it means, and under each finding
 * who it is about: the customer, the service by its product and name, the invoice by the number staff see (not only its
 * id), each linked to its page in the admin.
 *
 * The references are read from the finding's own text («invoice #12», «service #7», «customer #3», «transaction #9»): every
 * rule names its records so, but `duplicate_gateway_id`, whose detail lists its transactions bare («(#12, #15)», ADV-914-1).
 * A record no longer there is left as the text says it. All records are read at once, by type (ADV-914-4). Read-only.
 */
class MoneyCheckMail
{
    private const REFERENCE = '/\b(invoice|service|customer|transaction) #(\d+)/';

    /** @var array<string, array<int, Model>> type => id => record, for every id the findings name */
    private array $records = [];

    /** @param  list<array{rule: string, subject: string, detail: string}>  $findings */
    public function html(array $findings): string
    {
        $this->load($findings);
        $html = '<p>' . e(__('The daily money check found what does not add up. Nothing was changed: each finding says what to do.')) . '</p>';
        foreach (collect($findings)->groupBy('rule') as $rule => $group) {
            $html .= '<h3 style="margin:18px 0 4px">' . e((string) $rule) . ' (' . count($group) . ')</h3>';
            $html .= '<p style="margin:0 0 8px;color:#555">' . e(self::meaning((string) $rule)) . '</p><ul style="padding-left:18px">';
            foreach ($group as $finding) {
                $html .= '<li style="margin-bottom:10px"><strong>' . $this->linked($finding['subject']) . '</strong>: ' . $this->linked($finding['detail']);
                if (($about = $this->about($finding)) !== '') {
                    $html .= '<br><span style="color:#555">' . $about . '</span>';
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        return $html . '<p style="color:#555">' . e(__('More in docs/money-trail.md §5.')) . '</p>';
    }

    /** The mail as it was: the findings' lines, escaped (a renderer that fails never costs staff the mail). */
    public static function plain(array $findings): string
    {
        $lines = e(implode("\n", array_map(fn (array $finding) => "{$finding['rule']}: {$finding['subject']}: {$finding['detail']}", $findings)));

        return 'The daily money check found what does not add up (nothing was changed; docs/money-trail.md §5):<br><pre>' . $lines . '</pre>';
    }

    /** What a rule means, in a line: what staff read before the finding itself. */
    public static function meaning(string $rule): string
    {
        return match ($rule) {
            'paid_balance' => __('A paid invoice whose payments do not match its total: it still owes, or holds more than its total.'),
            'open_settled' => __('An open invoice with nothing left to pay: its payments cover it, or its total is zero.'),
            'cancelled_applied' => __('A cancelled invoice with a line that renewed its service.'),
            'cancelled_holds_money' => __('A cancelled invoice that still holds money (payments less refunds and credit).'),
            'paid_not_applied' => __('A paid invoice whose services were not renewed after a day.'),
            'paid_for_cancelled' => __('A paid invoice for a service that had ended or an upgrade cancelled: the payment bought nothing and wants a refund or credit.'),
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
            'gateway_unreconciled' => __('A gateway list and this site disagree on a payment, or the list was not read: nothing is compared.'),
            default => '',
        };
    }

    /**
     * @param  array{rule: string, subject: string, detail: string}  $finding
     * @return list<array{0: string, 1: int}>
     */
    private static function references(array $finding): array
    {
        $found = [];
        preg_match_all(self::REFERENCE, $finding['subject'] . ' ' . $finding['detail'], $matches, PREG_SET_ORDER);
        foreach ($matches as [, $type, $id]) {
            $found[] = [$type, (int) $id];
        }
        if ($finding['rule'] === 'duplicate_gateway_id') {
            // «succeeded N times (#12, #15)»: the ids in its parentheses are transactions.
            preg_match('/\(([#\d, ]+)\)\s*$/', $finding['detail'], $list);
            preg_match_all('/#(\d+)/', $list[1] ?? '', $matches);
            foreach ($matches[1] as $id) {
                $found[] = ['transaction', (int) $id];
            }
        }

        return $found;
    }

    /** Every record the findings name, by type in one query each, with what their names and totals read. */
    private function load(array $findings): void
    {
        $ids = [];
        foreach ($findings as $finding) {
            foreach (self::references($finding) as [$type, $id]) {
                $ids[$type][$id] = $id;
            }
        }
        $client = ['primaryContact', 'ownerGrant.user'];
        $this->records['transaction'] = InvoiceTransaction::query()->whereIn('id', $ids['transaction'] ?? [])->get()->keyBy('id')->all();
        foreach ($this->records['transaction'] as $transaction) {
            $ids['invoice'][(int) $transaction->invoice_id] = (int) $transaction->invoice_id;
        }
        $this->records['invoice'] = Invoice::query()->whereIn('id', $ids['invoice'] ?? [])
            ->with(['items', 'adjustmentNotes', 'currency', ...array_map(fn ($relation) => "client.{$relation}", $client)])->get()->keyBy('id')->all();
        $this->records['service'] = Service::query()->whereIn('id', $ids['service'] ?? [])
            ->with(['product.server', 'license', 'properties', 'parent.product.server', 'parent.license', 'parent.properties', ...array_map(fn ($relation) => "client.{$relation}", $client)])->get()->keyBy('id')->all();
        $this->records['customer'] = Client::query()->whereIn('id', $ids['customer'] ?? [])->with($client)->get()->keyBy('id')->all();
    }

    private function record(string $type, int $id): ?Model
    {
        return $this->records[$type][$id] ?? null;
    }

    /** The text, escaped, with each invoice, service and customer it names as a link (an invoice by its number). */
    private function linked(string $text): string
    {
        // The check's own text is scrubbed as a log line, as it was (third review of #914: a gateway's error may carry a
        // URL with a key); the records' names added here are not (ADV-914-3).
        $text = (string) LogText::of($text);

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

    /**
     * Who and what a finding is about: its customers, its services by product and name, its invoices with their status.
     * A finding may span two customers (the very thing some rules catch, ADV-914-2): then each record says whose it is.
     *
     * @param  array{rule: string, subject: string, detail: string}  $finding
     */
    private function about(array $finding): string
    {
        $records = [];
        foreach (self::references($finding) as [$type, $id]) {
            $record = $this->record($type, $id);
            if ($record instanceof InvoiceTransaction) {
                $record = $this->record('invoice', (int) $record->invoice_id);
            }
            if ($record !== null) {
                $records[$record::class . '#' . $record->getKey()] = $record;
            }
        }
        $clients = [];
        foreach ($records as $record) {
            $client = $record instanceof Client ? $record : $record->getRelationValue('client');
            if ($client instanceof Client) {
                $clients[$client->id] = $client;
            }
        }
        $many = count($clients) > 1;
        $parts = [];
        foreach ($records as $record) {
            $whose = $many && ($client = $record->getRelationValue('client')) instanceof Client ? ' — #' . $client->id : '';
            $part = match (true) {
                $record instanceof Service => __('Service') . ': ' . self::link(ServiceResource::getUrl('edit', ['record' => $record->id], panel: 'admin'),
                    $record->mail_name) . ' (' . e((string) $record->status) . e($whose) . ')',
                $record instanceof Invoice => __('Invoice') . ': ' . self::link(InvoiceResource::getUrl('edit', ['record' => $record->id], panel: 'admin'),
                    $record->number ?: "#{$record->id}") . ' (' . e((string) $record->status) . ', ' . e((string) $record->formattedTotal) . e($whose) . ')',
                default => null,
            };
            if ($part !== null) {
                $parts[] = $part;
            }
        }
        foreach (array_reverse($clients) as $client) {
            array_unshift($parts, __('Customer') . ': ' . self::link(ClientResource::getUrl('view', ['record' => $client->id], panel: 'admin'),
                "#{$client->id} {$client->name}"));
        }

        return implode(' · ', $parts);
    }

    private static function link(string $url, string $text): string
    {
        return '<a href="' . e($url) . '">' . e($text) . '</a>';
    }
}
