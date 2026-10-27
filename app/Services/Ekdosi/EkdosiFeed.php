<?php

namespace App\Services\Ekdosi;

use App\Enums\AdjustmentNoteStatus;
use App\Models\Contact;
use App\Models\Credit;
use App\Models\Domain;
use App\Models\DomainOwnerChange;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * What Ekdosi reads of the invoices (docs/ekdosi-bridge.md, E1): the shape its WHMCS bridge plugin gives
 * (`InvoiceFeed`, «compatible with WhmcsClient::getInvoiceWithClient»), so its ingestor takes it as it is, plus what
 * MyCS knows outright and WHMCS kept in custom fields and the plugin's own tables:
 *
 * - `mycs`: the account's flags (ignore / immediate invoicing) and its recipient, the primary contact, with ΑΦΜ, ΔΟΥ,
 *   activity and the kind of document it wants: no custom field map.
 * - `third_party`, always: each line's recipient, the related contact of its service or domain when it is not the
 *   account's own, as the plugin's `op=resolve` (`is_receipt` from the contact's document type).
 * - per line, besides WHMCS's net `amount`: quantity, unit price, VAT rate and amount, total. `taxtype` is always
 *   Exclusive: the amounts are net and the VAT is given, nothing to guess.
 *
 * Read only. An account with «ignore invoicing» is never in the feed, but for an invoice staff sent (`requested`, E5);
 * one invoice asked for by id comes, with the flag.
 */
final class EkdosiFeed
{
    public const MAX_LIMIT = 200;

    /** WHMCS's status names, which Ekdosi's ingestor compares with. */
    private const STATUS_NAMES = [
        Invoice::STATUS_DRAFT => 'Draft',
        Invoice::STATUS_UNPAID => 'Unpaid',
        Invoice::STATUS_PAID => 'Paid',
        Invoice::STATUS_CANCELLED => 'Cancelled',
        Invoice::STATUS_REFUNDED => 'Refunded',
    ];

    /**
     * One page, newest first. `paid_unfiled` (the Inbox): paid, no document from Ekdosi yet, since the payment date
     * `$since` (an invoice paid after the cut-over shows up whenever it was issued). The others by status and issue date.
     *
     * @param  list<int>|null  $userIds  only these accounts (E3: the unpaid invoices of «τιμολόγιο πριν την πληρωμή»)
     * @return array{invoices: list<array<string, mixed>>, offset: int, count: int}
     */
    public function page(string $status, ?string $since, int $offset, int $limit, ?array $userIds = null): array
    {
        $offset = max(0, $offset);
        $limit = $limit < 1 ? 100 : min($limit, self::MAX_LIMIT);

        $query = Invoice::query()
            ->whereNotNull('client_id')
            ->orderByDesc('id');
        // «Send to Ekdosi» (E5): what staff asked for comes whatever the account's «ignore invoicing».
        if ($status !== 'requested') {
            $query->whereHas('client', fn (Builder $client) => $client->where('ignore_invoicing', false));
        }
        if ($userIds !== null) {
            $query->whereIn('client_id', $userIds);
        }

        $since = $this->date($since);
        if ($status === 'requested') {
            // Staff asked Ekdosi for a document (E5), paid or not (an unpaid one is issued on credit): until one is
            // active. `since` by when they asked.
            $query->whereNotNull('ekdosi_requested_at')
                ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PAID])
                ->whereDoesntHave('ekdosiDocuments', fn (Builder $documents) => $documents->where('state', EkdosiDocument::ACTIVE))
                // An unpaid credit purchase owes nothing, with a discount line too (review of #478, R2-2).
                ->whereNot(fn (Builder $credit) => $credit->where('status', Invoice::STATUS_UNPAID)
                    ->whereHas('items', fn (Builder $items) => $items->where('reference_type', Credit::class)));
            if ($since !== null) {
                $query->where('ekdosi_requested_at', '>=', $since);
            }
        } elseif ($status === 'paid_unfiled' || $status === '') {
            // Only an active document files it: one cancelled at AADE (to reissue it) brings it back (ADV-3).
            $query->where('status', Invoice::STATUS_PAID)
                ->whereDoesntHave('ekdosiDocuments', fn (Builder $documents) => $documents->where('state', EkdosiDocument::ACTIVE));
            if ($since !== null) {
                // An invoice marked paid with no payment date: by its issue date, as the plugin.
                $query->where(fn (Builder $q) => $q->where('paid_at', '>=', $since)
                    ->orWhere(fn (Builder $q) => $q->whereNull('paid_at')->where('created_at', '>=', $since)));
            }
        } else {
            $own = array_search($status, self::STATUS_NAMES, true);
            if ($own !== false) {
                $query->where('status', $own);
            } elseif ($status !== 'All') {
                throw new BadBridgeArgument('status');
            }
            if ($since !== null) {
                $query->where('created_at', '>=', $since);
            }
        }

        $payloads = $query->offset($offset)->limit($limit)->with(self::relations())->get()
            ->map(fn (Invoice $invoice) => $this->payload($invoice))->values()->all();

        return ['invoices' => $payloads, 'offset' => $offset, 'count' => count($payloads)];
    }

    /** One invoice by id, whatever its status (Ekdosi's «send to Ekdosi» of one invoice); null when there is none. */
    public function one(int $invoiceId): ?array
    {
        $invoice = $invoiceId > 0 ? Invoice::query()->whereNotNull('client_id')->with(self::relations())->find($invoiceId) : null;

        return $invoice === null ? null : $this->payload($invoice);
    }

    /** Only the lines' recipients (the plugin's `op=resolve`); null when there is no such invoice. */
    public function routing(int $invoiceId): ?array
    {
        $invoice = $invoiceId > 0 ? Invoice::query()->whereNotNull('client_id')->with(self::relations())->find($invoiceId) : null;

        return $invoice === null ? null : ['status' => 'ok'] + $this->thirdParty($invoice);
    }

    /** @return array<int|string, mixed> what a page reads, at once (no query per line: review of #459) */
    private static function relations(): array
    {
        return ['client.primaryContact', 'transactions.gateway', 'adjustmentNotes', 'ekdosiDocuments',
            'items.reference' => fn (MorphTo $reference) => $reference->morphWith([
                Service::class => ['product', 'contact'],
                Domain::class => ['contact'],
                ServiceUpgrade::class => ['service.contact'],
                DomainOwnerChange::class => ['domain.contact'],
            ]),
        ];
    }

    /**
     * What Ekdosi read of the invoice, short: its account, currency, lines (id, kind of item, total) and active notes. Ekdosi sends it back with
     * the write-back, and a document is taken only for the invoice as it was read (R2-3 of #459).
     */
    public static function fingerprint(Invoice $invoice): string
    {
        $lines = $invoice->items->sortBy('id')->map(fn (InvoiceItem $item) => implode(':', [$item->id, $item->reference_type, $item->reference_id,
            $item->totalCents()]))->implode('|');

        // Its active credit and debit notes too: a note changes what is owed (final review of #459).
        $notes = $invoice->adjustmentNotes->where('status', AdjustmentNoteStatus::Active->value)->sortBy('id')
            ->map(fn ($note) => $note->id . ':' . (int) round((float) $note->amount * 100))->implode('|');

        return substr(hash('sha256', $invoice->id . '|' . $invoice->client_id . '|' . $invoice->currency_code . '|' . $lines . '|' . $notes), 0, 32);
    }

    /** @return array<string, mixed> */
    private function payload(Invoice $invoice): array
    {
        $client = $invoice->client;
        $primary = $client?->primaryContact;
        $statement = $invoice->statement();
        $payments = collect($statement['payments']);
        $rates = $invoice->items->filter(fn (InvoiceItem $item) => (float) $item->tax_amount != 0.0)
            ->map(fn (InvoiceItem $item) => (string) $item->tax_rate)->unique()->values();

        return [
            'invoiceid' => $invoice->id,
            'id' => $invoice->id,
            'invoicenum' => (string) ($invoice->number ?? ''),
            'userid' => (int) $invoice->client_id,
            'date' => $invoice->created_at?->format('Y-m-d') ?? '',
            'duedate' => $invoice->due_at?->format('Y-m-d') ?? '',
            'datepaid' => $invoice->paid_at?->format('Y-m-d H:i:s') ?? '',
            'subtotal' => $this->money($invoice->subtotal),
            'tax' => $this->money($invoice->taxTotal),
            // One rate on all its taxed lines: that one; several (or none): each line says its own.
            'taxrate' => $rates->count() === 1 ? (string) $rates->first() : '0',
            // As WHMCS: what was due after the account credit applied.
            'total' => $this->money($statement['total']),
            'credit' => $this->money($statement['credit']),
            'tax2' => '0.00',
            'taxtype' => 'Exclusive',
            'status' => self::STATUS_NAMES[$invoice->status] ?? ucfirst((string) $invoice->status),
            'invoiced' => 0,
            'paymentmethod' => $this->gatewayName($payments->last()),
            'currencycode' => (string) $invoice->currency_code,
            'companyname' => (string) ($primary->company_name ?? ''),
            'firstname' => (string) ($primary->first_name ?? ''),
            'lastname' => (string) ($primary->last_name ?? ''),
            'email' => (string) ($primary->email ?? ''),
            'address1' => (string) ($primary->address1 ?? ''),
            'address2' => (string) ($primary->address2 ?? ''),
            'city' => (string) ($primary->city ?? ''),
            'state' => (string) ($primary->state ?? ''),
            'postcode' => (string) ($primary->postcode ?? ''),
            'country' => (string) ($primary->country ?? ''),
            'phonenumber' => (string) ($primary->phone ?? ''),
            'customfields' => [],
            'items' => ['item' => $invoice->items->sortBy('id')->map(fn (InvoiceItem $item) => $this->line($item))->values()->all()],
            'transactions' => $payments->map(fn (InvoiceTransaction $payment) => [
                'transid' => (string) ($payment->transaction_id ?? ''),
                'gateway' => $this->gatewayName($payment),
                'date' => $payment->created_at?->format('Y-m-d H:i:s') ?? '',
                'amount' => $this->money($payment->amount),
                'refunded' => $this->money($payment->refunded_amount),
            ])->values()->all(),
            'third_party' => $this->thirdParty($invoice),
            'mycs' => [
                'source' => 'mycs',
                'fingerprint' => self::fingerprint($invoice),
                'ignore_invoicing' => (bool) ($client->ignore_invoicing ?? false),
                'immediate_invoicing' => (bool) ($client->immediate_invoicing ?? false),
                // Staff's «Send to Ekdosi» (E5): when they asked, or null. Never issued unattended.
                'requested_at' => $invoice->ekdosi_requested_at?->format('Y-m-d H:i:s'),
                // Credit and debit notes on it (WHMCS has none): its total already counts them.
                'adjustments' => $this->money($statement['adjustments']),
                'recipient' => $primary === null ? null : $this->contact($primary),
                'documents' => $invoice->ekdosiDocuments->map(fn ($document) => [
                    'mark' => $document->mark, 'code' => $document->code, 'state' => $document->state,
                ])->values()->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function line(InvoiceItem $item): array
    {
        $reference = $item->reference;
        $product = $reference instanceof Service ? $reference->product : null;

        $taxed = (bool) $item->taxed && (float) $item->tax_rate > 0;

        return [
            'id' => $item->id,
            'type' => $this->type($item),
            'relid' => (int) ($item->reference_id ?? 0),
            'description' => (string) ($item->description ?? ''),
            'amount' => $this->money($item->net()),
            // As WHMCS's: tax applies at the invoice's rate. A line ticked but billed at 0% (a product that is not taxable,
            // an exempt account, LineTax) is not: Ekdosi would put 24% on it (live test of 2026-10-27).
            'taxed' => (int) $taxed,
            'whmcs_product_id' => (int) ($product->id ?? 0),
            'whmcs_group_id' => (int) ($product->category_id ?? 0),
            'quantity' => (int) $item->quantity,
            'unit_price' => $this->money($item->price),
            'tax_rate' => (string) ($item->tax_rate ?? '0'),
            'tax_amount' => $this->money($item->tax_amount),
            'total' => $this->money($item->total()),
            'period_start' => $item->period_start?->format('Y-m-d'),
            'period_end' => $item->period_end?->format('Y-m-d'),
        ] + ($taxed ? [] : [
            // Why it went without tax (EuVat: exempt, not_taxable, reverse_charge, outside_eu): Ekdosi's exemption category
            // for it, not the tenant's one for all (adversarial ADV-1 of #487). Only on such a line: a key on every line
            // made every staged MyCS row read «changed» to Ekdosi's re-read once (R2-1 of #487).
            'tax_reason' => $item->tax_reason ?: null,
        ]);
    }

    /** WHMCS's line type, which Ekdosi's routing and classification read («hosting», «domain» in it). */
    private function type(InvoiceItem $item): string
    {
        return match ($item->reference_type) {
            Service::class => 'Hosting',
            ServiceUpgrade::class => 'Upgrade',
            Domain::class => match ($item->kind) {
                InvoiceItem::KIND_REGISTER => 'DomainRegister',
                InvoiceItem::KIND_TRANSFER => 'DomainTransfer',
                default => 'Domain',
            },
            DomainOwnerChange::class => 'DomainOwnerChange',
            Credit::class => 'AddFunds',
            default => '',
        };
    }

    /**
     * Each line's recipient, as the plugin's `op=resolve`: the related contact of its service or domain (an upgrade's,
     * its service's), when it is not the account's own; else the account (`routed` false).
     *
     * @return array<string, mixed>
     */
    private function thirdParty(Invoice $invoice): array
    {
        $primaryId = $invoice->client?->primaryContact?->id;
        $lines = [];
        $parties = [];
        foreach ($invoice->items->sortBy('id') as $item) {
            // The service or domain the line is for: an upgrade's service, an owner change's domain.
            $reference = $item->reference;
            $owner = match (true) {
                $reference instanceof ServiceUpgrade => $reference->service,
                $reference instanceof DomainOwnerChange => $reference->domain,
                default => $reference,
            };
            $contact = $owner instanceof Service || $owner instanceof Domain ? $owner->contact : null;
            // Only a contact of the invoice's account, not archived: one left from before a move, or retired, is not a
            // recipient (the account is).
            if ($contact !== null && ((int) $contact->client_id !== (int) $invoice->client_id || $contact->id === $primaryId || $contact->archived_at !== null)) {
                $contact = null;
            }
            $parties[$contact === null ? 'account' : 'contact:' . $contact->id] = true;
            $type = $this->type($item);
            $lines[] = [
                'item_id' => $item->id,
                // As the plugin's routing: the service's or the domain's id (an upgrade's line names its service).
                'relid' => (int) ($owner instanceof Service || $owner instanceof Domain ? $owner->id : ($item->reference_id ?? 0)),
                'type' => $type,
                'service_type' => $owner instanceof Domain ? 'domain' : ($owner instanceof Service ? 'hosting' : null),
                'description' => (string) ($item->description ?? ''),
                'routed' => $contact !== null,
                'is_receipt' => $contact !== null && $contact->document_type === 'receipt',
                'contact' => $contact === null ? null : $this->contact($contact),
            ];
        }
        $routed = count(array_filter($lines, fn (array $line) => $line['routed']));

        return [
            'whmcs_invoice_id' => $invoice->id,
            'userid' => (int) $invoice->client_id,
            'timologia_present' => true,
            'lines' => $lines,
            'summary' => [
                'line_count' => count($lines),
                'routed_lines' => $routed,
                'unrouted_lines' => count($lines) - $routed,
                'distinct_parties' => count($parties),
                'multi_party' => count($parties) > 1,
            ],
        ];
    }

    /**
     * A contact in the plugin's shape (`mod_ekdosi_contacts`), and what it has besides.
     *
     * @return array<string, mixed>
     */
    private function contact(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'type' => $contact->type,
            'company_name' => (string) ($contact->company_name ?? ''),
            'first_name' => (string) ($contact->first_name ?? ''),
            'last_name' => (string) ($contact->last_name ?? ''),
            'gr_vatno' => (string) ($contact->tax_id ?? ''),
            'vies_vatno' => (string) ($contact->vat_number ?? ''),
            'tax_office' => (string) ($contact->tax_office ?? ''),
            'description' => (string) ($contact->activity ?? ''),
            'address1' => (string) ($contact->address1 ?? ''),
            'address2' => (string) ($contact->address2 ?? ''),
            'city' => (string) ($contact->city ?? ''),
            'state' => (string) ($contact->state ?? ''),
            'postal_code' => (string) ($contact->postcode ?? ''),
            'country' => (string) ($contact->country ?? ''),
            'email' => (string) ($contact->email ?? ''),
            'telephone' => (string) ($contact->phone ?? ''),
            // invoice (τιμολόγιο), receipt (απόδειξη), none, or null: not chosen.
            'document_type' => $contact->document_type,
        ];
    }

    /** The gateway's system name, as WHMCS's (`banktransfer`, `stripe`, `paypal`): Ekdosi maps it to a payment method. */
    private function gatewayName(?InvoiceTransaction $payment): string
    {
        return strtolower((string) ($payment?->gateway->extension ?? ''));
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function date(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', trim($value))) {
            throw new BadBridgeArgument('since');
        }
        // «2026-13-45» or «25:99» is no date (Carbon would roll it over, or throw its own message).
        $value = trim($value);
        $format = match (strlen($value)) {
            10 => '!Y-m-d',
            16 => '!Y-m-d H:i',
            default => '!Y-m-d H:i:s',
        };
        $date = rescue(fn () => Carbon::createFromFormat($format, $value), false, false);
        if (!$date instanceof Carbon || $date->format(substr($format, 1)) !== $value) {
            throw new BadBridgeArgument('since');
        }

        return $date;
    }
}
