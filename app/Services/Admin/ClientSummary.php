<?php

namespace App\Services\Admin;

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\UserAuthenticationLog;
use App\Services\Money\MoneyHistory;

/**
 * The client's «Summary» in the admin, as WHMCS's client profile: its details, its invoices by status, its services,
 * its credit, its last login and its last mails, on one screen. Read only. Amounts are summed in cents, per currency.
 */
class ClientSummary
{
    public const INVOICE_STATUSES = [Invoice::STATUS_PAID, Invoice::STATUS_UNPAID, Invoice::STATUS_DRAFT, Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED];

    /** @return array<string, mixed> */
    public static function for(Client $client): array
    {
        $client->loadMissing(['primaryContact', 'ownerGrant.user']);

        return [
            'details' => self::details($client),
            'invoices' => self::invoices($client),
            'credit' => Credit::query()->where('client_id', $client->id)->where('amount', '!=', 0)->orderBy('currency_code')->get()
                ->map(fn (Credit $credit) => MoneyHistory::money($credit->amount, $credit->currency_code))->all(),
            'services' => self::services($client),
            'other' => self::other($client),
            'emails' => EmailLog::query()->where('client_id', $client->id)->latest('id')->limit(5)
                ->get(['id', 'subject', 'status', 'created_at'])->all(),
            'open_tickets' => Ticket::query()->where('client_id', $client->id)->where('status', '!=', 'closed')->count(),
        ];
    }

    /** @return array<string, string|null> */
    private static function details(Client $client): array
    {
        $contact = $client->primaryContact;

        return [
            'name' => $contact === null ? null : (trim($contact->first_name . ' ' . $contact->last_name) ?: null),
            'company' => $contact?->company_name,
            'email' => $contact?->email,
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
     * @return array{statuses: array<string, array{count: int, total: list<string>}>, due: list<string>, paid_in: list<string>}
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
            $statuses[$status]['cents'][$currency] = ($statuses[$status]['cents'][$currency] ?? 0) + (int) round((float) $invoice->total * 100);
            if ($status === Invoice::STATUS_UNPAID) {
                $due[$currency] = ($due[$currency] ?? 0) + (int) round((float) $invoice->remaining * 100);
            }
        }

        return [
            'statuses' => array_map(fn (array $row) => ['count' => $row['count'], 'total' => self::money($row['cents'])], $statuses),
            'due' => self::money($due),
            'paid_in' => self::money($statuses[Invoice::STATUS_PAID]['cents']),
        ];
    }

    /** @return array{counts: array<string, int>, rows: list<array<string, mixed>>} */
    private static function services(Client $client): array
    {
        $services = Service::query()->where('client_id', $client->id)->with(['product', 'plan'])->orderByRaw("status = 'active' desc")->orderByDesc('id')->get();
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
                'amount' => MoneyHistory::money($service->price * max(1, (int) $service->quantity), (string) $service->currency_code),
                'cycle' => self::cycle($service->plan),
                'created' => $service->created_at,
                'expires' => $service->expires_at,
                'status' => $service->status,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function other(Client $client): array
    {
        $owner = $client->owner();
        $grant = ClientUser::query()->where('client_id', $client->id)->whereNull('revoked_at')->whereNotNull('last_login_at')->latest('last_login_at')->first();
        $log = $grant === null ? null : UserAuthenticationLog::query()->where('user_id', $grant->user_id)->latest('id')->first();

        return [
            'since' => $client->created_at,
            'last_login' => $grant?->last_login_at,
            'last_login_ip' => $log?->ip_address,
            'owner_verified' => $owner === null ? null : $owner->email_verified_at !== null,
            'users' => ClientUser::query()->where('client_id', $client->id)->whereNull('revoked_at')->count(),
        ];
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
     * @param  array<string, int>  $cents
     * @return list<string>
     */
    private static function money(array $cents): array
    {
        ksort($cents);

        return array_map(fn (int $amount, string $currency) => MoneyHistory::money(number_format($amount / 100, 2, '.', ''), $currency), $cents, array_keys($cents));
    }
}
