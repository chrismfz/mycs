<?php

namespace App\Services\Admin;

use App\Classes\Price;
use App\Enums\AdjustmentNoteStatus;
use App\Models\AdjustmentNote;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Credit;
use App\Models\Currency;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\UserAuthenticationLog;
use App\Services\Invoice\RecordPaymentService;

/**
 * The client's «Summary» in the admin, as WHMCS's client profile: its details, its invoices by status, its services,
 * its credit, its last login and its last mails, on one screen. Read only. Amounts are summed in cents, per currency.
 */
class ClientSummary
{
    public const INVOICE_STATUSES = [Invoice::STATUS_PAID, Invoice::STATUS_UNPAID, Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED];

    /** Each panel and the permission of the page it summarises (as the client's own subpages require). */
    public const PANELS = [
        'invoices' => 'admin.invoices.viewAny',
        'credit' => 'admin.credits.viewAny',
        'services' => 'admin.services.viewAny',
        'tickets' => 'admin.tickets.viewAny',
        'emails' => 'admin.email_logs.viewAny',
        'logins' => 'admin.users.viewAny',
    ];

    /**
     * The panels staff may see; the others are null and never queried.
     *
     * @return array<string, mixed>
     */
    public static function for(Client $client, ?Staff $staff): array
    {
        $client->loadMissing(['primaryContact', 'ownerGrant.user']);
        self::$currencies = [];
        $may = fn (string $panel) => (bool) $staff?->hasPermission(self::PANELS[$panel]);

        return [
            'details' => self::details($client),
            'invoices' => $may('invoices') ? self::invoices($client) : null,
            'credit' => $may('credit') ? Credit::query()->where('client_id', $client->id)->where('amount', '!=', 0)->orderBy('currency_code')->get()
                ->map(fn (Credit $credit) => self::money((int) round((float) $credit->amount * 100), $credit->currency_code))->all() : null,
            'services' => $may('services') ? self::services($client) : null,
            'open_tickets' => $may('tickets') ? Ticket::query()->where('client_id', $client->id)->where('status', '!=', 'closed')->count() : null,
            'emails' => $may('emails') ? EmailLog::query()->where('client_id', $client->id)->latest('id')->limit(5)
                ->get(['id', 'subject', 'status', 'created_at'])->all() : null,
            'other' => self::other($client, $may('logins')),
        ];
    }

    /** @return array<string, string|null> */
    private static function details(Client $client): array
    {
        $contact = $client->primaryContact;

        return [
            // The account's own name and email: its primary contact's, else its owner's (a legacy account, review F3).
            'name' => $client->name ?: null,
            'company' => $contact?->company_name,
            'email' => $client->email ?: null,
            'address' => $contact?->address1,
            'address2' => $contact?->address2,
            'city' => $contact?->city,
            'state' => $contact?->state,
            'postcode' => $contact?->postcode,
            'country' => $contact?->country ? ((string) (config('app.countries')[$contact->country] ?? $contact->country)) : null,
            'phone' => $contact?->phone,
            'tax_id' => $contact?->tax_id,
            'vat_number' => $contact?->vat_number,
        ];
    }

    /**
     * Each status: how many, and their totals per currency; unpaid ones also what is still due.
     *
     * @return array{statuses: array<string, array{count: int, total: list<string>}>, due: list<string>}
     */
    private static function invoices(Client $client): array
    {
        $statuses = array_fill_keys(self::INVOICE_STATUSES, ['count' => 0, 'cents' => []]);
        $due = [];
        $invoices = Invoice::query()->where('client_id', $client->id)->with(['items', 'adjustmentNotes', 'transactions'])->get();
        foreach ($invoices as $invoice) {
            $status = array_key_exists($invoice->status, $statuses) ? $invoice->status : null;
            if ($status === null) {
                continue;
            }
            $currency = (string) $invoice->currency_code;
            $statuses[$status]['count']++;
            $statuses[$status]['cents'][$currency] = ($statuses[$status]['cents'][$currency] ?? 0) + self::issuedCents($invoice);
            if ($status === Invoice::STATUS_UNPAID) {
                $due[$currency] = ($due[$currency] ?? 0) + (int) round((float) $invoice->remaining * 100);
            }
        }

        return [
            'statuses' => array_map(fn (array $row) => ['count' => $row['count'], 'total' => self::amounts($row['cents'])], $statuses),
            'due' => self::amounts($due),
        ];
    }

    /**
     * What the invoice was issued for, as WHMCS lists it: its lines and staff's notes, not the notes of its cancellation
     * or refunds (they bring `total` to 0 for a cancelled or refunded invoice, review F1). As CronJob::keepsItsMoney.
     */
    private static function issuedCents(Invoice $invoice): int
    {
        return $invoice->items->sum(fn (InvoiceItem $item) => $item->totalCents())
            + $invoice->adjustmentNotes->where('status', AdjustmentNoteStatus::Active->value)->where('is_refund', false)->where('is_cancellation', false)
                ->sum(fn (AdjustmentNote $note) => RecordPaymentService::toCents($note->amount));
    }

    /** @return array{counts: array<string, int>, rows: list<array<string, mixed>>} */
    private static function services(Client $client): array
    {
        $services = Service::query()->where('client_id', $client->id)->with(['product', 'plan', 'currency'])->orderByRaw("status = 'active' desc")->orderByDesc('id')->get();
        $counts = array_fill_keys(array_keys(Service::statusOptions()), 0);
        foreach ($services as $service) {
            $counts[$service->status] = ($counts[$service->status] ?? 0) + 1;
        }

        return [
            'counts' => $counts,
            'rows' => $services->map(fn (Service $service) => [
                'id' => $service->id,
                'product' => $service->product?->name,
                'domain' => $service->domain,
                'amount' => (string) $service->formattedPrice, // as Products/Services shows it: with tax, what a renewal charges
                'cycle' => self::cycle($service->plan),
                'created' => $service->created_at,
                'expires' => $service->expires_at,
                'status' => $service->status,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function other(Client $client, bool $logins): array
    {
        $owner = $client->owner();
        $other = [
            'since' => $client->created_at,
            'owner_verified' => $owner === null ? null : $owner->email_verified_at !== null,
            'last_login' => null,
            'last_login_ip' => null,
            'users' => null,
        ];
        if (!$logins) {
            return $other;
        }
        $grant = ClientUser::query()->where('client_id', $client->id)->whereNull('revoked_at')->whereNotNull('last_login_at')->latest('last_login_at')->first();
        // One row per address a user logged in from, its last use bumped on each login (LoginDetectedListener): the
        // address that user used last. The log is the user's, not the account's: a user of several accounts shows the
        // address of its last login to any of them (review F4).
        $log = $grant === null ? null : UserAuthenticationLog::query()->where('user_id', $grant->user_id)
            ->orderByRaw('last_used_at is null')->orderByDesc('last_used_at')->orderByDesc('created_at')->orderByDesc('id')->first();

        return array_merge($other, [
            'last_login' => $grant?->last_login_at,
            'last_login_ip' => $log?->ip_address,
            'users' => ClientUser::query()->where('client_id', $client->id)->whereNull('revoked_at')->count(),
        ]);
    }

    /** As WHMCS names the cycles: Monthly, Quarterly, …; else «every N units». */
    public static function cycle(?Plan $plan): string
    {
        if ($plan === null) {
            return '';
        }
        if ($plan->type !== 'recurring') {
            return $plan->type === 'free' ? __('Free') : __('One time');
        }
        $months = match ($plan->billing_unit) {
            'month' => $plan->billing_period,
            'year' => $plan->billing_period * 12,
            default => null,
        };

        return match ($months) {
            1 => __('Monthly'),
            3 => __('Quarterly'),
            6 => __('Semi-Annually'),
            12 => __('Annually'),
            24 => __('Biennially'),
            36 => __('Triennially'),
            default => trim($plan->billing_period . ' ' . $plan->billing_unit),
        };
    }

    /**
     * @param  array<string, int>  $cents  per currency
     * @return list<string>
     */
    private static function amounts(array $cents): array
    {
        ksort($cents);

        return array_map(fn (int $amount, string $currency) => self::money($amount, $currency), $cents, array_keys($cents));
    }

    /** @var array<string, Currency|null> one lookup per currency, not per amount */
    private static array $currencies = [];

    private static function money(int $cents, string $currency): string
    {
        $model = self::$currencies[$currency] ??= Currency::query()->find($currency);
        $amount = $cents / 100;

        return $model === null ? number_format($amount, 2, '.', '') . ' ' . $currency
            : (new Price(['price' => 0, 'currency' => $model], dontShowUnavailablePrice: true))->format($amount);
    }
}
