<?php

namespace App\Services\Ops;

use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\ItemPaymentMethod;
use App\Services\Payment\SavedMethodCharge;
use App\Support\Redact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The automatic charges of saved methods as the ops tools show them (docs/ops.md, docs/payments.md «Αυτόματη χρέωση»):
 * an invoice's charge attempts and who charges it, recent attempts across accounts, an item's «Automatic charge» line and
 * an account's saved methods, for «why was this customer charged, not charged, charged twice». Read-only, by the rules
 * the cron itself reads (AutoCharge, ItemPaymentMethod, Client), never their copy. A method by its name and gateway only:
 * never its external reference (the gateway's token of it), and its reference masked in any error text.
 */
final class Charges
{
    /** Shown in an attempt's «alert» (attentionOf()): money maybe taken and not recorded. */
    public const TAKEN_NOT_RECORDED = 'taken_not_recorded';

    public const UNKNOWN_OUTCOME = 'unknown_outcome';

    /**
     * Whether today's cron picks it up (AutoCharge::dueToday), the method it charges it to when it does (agreementFor,
     * read whatever the invoice's status), and every attempt on it, newest first.
     *
     * @return array<string, mixed>
     */
    public static function invoice(Invoice $invoice): array
    {
        $agreement = AutoCharge::agreementFor($invoice);

        return [
            'no_auto_charge' => (bool) $invoice->no_auto_charge,
            'due_for_charge_today' => AutoCharge::dueToday($invoice),
            'charged_by' => $agreement === null ? null : self::method($agreement),
            'attempts' => self::rows(ChargeAttempt::query()->where('invoice_id', $invoice->id)->latest('id')->limit(50)->get()),
        ];
    }

    /** @return array<string, mixed> its «Automatic charge» line, as its page says it, and what it is set to */
    public static function item(Service|Domain $item): array
    {
        $item->loadMissing('paymentGateway');
        $locale = app()->getLocale();
        app()->setLocale('en'); // as the rest of the report, whatever the site's language
        try {
            $status = ItemPaymentMethod::autoChargeStatus($item);
        } finally {
            app()->setLocale($locale);
        }

        return [
            'automatic_charge' => $status,
            'no_auto_charge' => (bool) $item->no_auto_charge,
            'payment_method' => $item->payment_gateway_id === null ? "the account's"
                : ($item->paymentGateway->name ?? "the account's (its gateway #{$item->payment_gateway_id} was deleted)"),
        ];
    }

    /** @return array<string, mixed> «Auto-charge», the default method, and what each other saved method pays */
    public static function client(Client $client): array
    {
        $client->loadMissing('defaultBillingAgreement');
        $default = $client->autoChargeMethod();
        $own = ItemPaymentMethod::ownWayMethods($client);
        $stored = $client->defaultBillingAgreement;

        $none = 'nothing automatically: ' . match (true) {
            !$client->auto_charge => '«Auto-charge» is off for the account',
            $default === null => 'the account has no default method, and without one no method is charged',
            default => 'not the default, and no service or domain of its gateway is charged to it',
        };
        $stored = $stored !== null && (int) $stored->client_id === (int) $client->id ? $stored : null;

        return [
            'auto_charge' => (bool) $client->auto_charge,
            'default_method' => $stored === null ? null : self::method($stored, true)
                + ['pays' => $default === null ? $none : "the renewals of the account's services and domains, except those paid with another gateway"],
            'other_methods' => $client->billingAgreements()->latest('id')->get()
                ->reject(fn (BillingAgreement $method) => (int) $method->id === (int) $stored?->id)
                ->map(fn (BillingAgreement $method) => self::method($method, true)
                    + ['pays' => isset($own[$method->id]) ? "the renewals of the services and domains paid with {$own[$method->id]}" : $none])
                ->values()->all(),
        ];
    }

    /**
     * Recent attempts across accounts, newest first, with a count of each status and the ones that need staff: money
     * taken and no payment recorded since, an unknown outcome, an invoice charged more than once.
     *
     * @return array<string, mixed>
     */
    public static function recent(?string $status, ?Client $client, ?Invoice $invoice, int $days, int $limit): array
    {
        $statuses = [ChargeAttempt::STATUS_STARTED, ChargeAttempt::STATUS_SUCCEEDED, ChargeAttempt::STATUS_FAILED, ChargeAttempt::STATUS_UNKNOWN,
            ChargeAttempt::STATUS_ACTION_REQUIRED, self::TAKEN_NOT_RECORDED];
        if ($status !== null && $status !== '' && !in_array($status, $statuses, true)) {
            throw new NotFound("No status {$status}: one of " . implode(', ', $statuses) . '.');
        }
        $query = fn () => ChargeAttempt::query()->where('sent_at', '>=', now()->subDays($days))
            ->when($client !== null, fn (Builder $query) => $query->where('client_id', $client?->id))
            ->when($invoice !== null, fn (Builder $query) => $query->where('invoice_id', $invoice?->id));
        $attempts = match ($status) {
            null, '' => $query()->latest('id')->limit($limit)->get(),
            self::TAKEN_NOT_RECORDED => $query()->where('status', ChargeAttempt::STATUS_SUCCEEDED)->latest('id')->get()
                ->filter(fn (ChargeAttempt $attempt) => self::attentionOf($attempt) === self::TAKEN_NOT_RECORDED)->take($limit)->values(),
            default => $query()->where('status', $status)->latest('id')->limit($limit)->get(),
        };
        $taken = $query()->where('status', ChargeAttempt::STATUS_SUCCEEDED)->get();

        return [
            'window' => "the last {$days} days",
            'counts' => $query()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'taken_not_recorded' => $taken->filter(fn (ChargeAttempt $attempt) => self::attentionOf($attempt) === self::TAKEN_NOT_RECORDED)->pluck('invoice_id')->unique()->values()->all(),
            'unknown_outcome' => $query()->where(fn (Builder $query) => $query->where('status', ChargeAttempt::STATUS_UNKNOWN)
                ->orWhere(fn (Builder $stale) => $stale->where('status', ChargeAttempt::STATUS_STARTED)->where('sent_at', '<=', now()->subMinutes(ChargeAttempt::HOLD_MINUTES))))
                ->pluck('invoice_id')->unique()->values()->all(),
            'succeeded_more_than_once' => $taken->countBy('invoice_id')->filter(fn (int $n) => $n > 1)->keys()->map(fn ($id) => (int) $id)->values()->all(),
            'attempts' => self::rows($attempts),
        ];
    }

    /**
     * @param  Collection<int, ChargeAttempt>  $attempts
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $attempts): array
    {
        $attempts->loadMissing('billingAgreement');
        $gateways = Gateway::query()->withTrashed()->whereKey($attempts->pluck('billingAgreement.gateway_id')->filter()->unique()->all())->pluck('name', 'id');

        return $attempts->map(function (ChargeAttempt $attempt) use ($gateways) {
            $method = $attempt->billingAgreement;
            $attention = self::attentionOf($attempt);

            return [
                'id' => $attempt->id,
                'at' => $attempt->started_at->toDateTimeString(),
                'sent_again' => $attempt->sent_at->equalTo($attempt->started_at) ? null : $attempt->sent_at->toDateTimeString(),
                'finished' => $attempt->finished_at?->toDateTimeString(),
                'invoice_id' => $attempt->invoice_id,
                'account_id' => $attempt->client_id,
                'source' => $attempt->source,
                'method' => $method === null ? null : trim($method->name . ($method->trashed() ? ' (removed)' : '')),
                'gateway' => $method === null ? null : ($gateways[$method->gateway_id] ?? "#{$method->gateway_id}"),
                'amount' => $attempt->amount . ' ' . $attempt->currency_code,
                'status' => $attempt->status,
                'alert' => match ($attention) {
                    self::TAKEN_NOT_RECORDED => 'TAKEN, NOT RECORDED: the gateway took it but no payment of it was recorded since (check its webhooks)'
                        . ($attempt->sent_at->lessThan(now()->subDays(SavedMethodCharge::TAKEN_HOLD_DAYS)) ? '; the invoice is no longer held and may be charged again' : '; the invoice is held, not charged again'),
                    self::UNKNOWN_OUTCOME => 'UNKNOWN OUTCOME: maybe taken; the next charge of this amount to this method is sent again with the same key',
                    'awaiting_payment' => 'taken; its payment is not recorded yet (the gateway\'s webhook is expected within the hour)',
                    'awaiting_answer' => 'sent; no answer from the gateway yet',
                    ChargeAttempt::STATUS_ACTION_REQUIRED => 'the bank asked the customer to confirm: not charged to this method again until they pay',
                    default => null,
                },
                'error' => $attempt->error === null ? null : Redact::values((string) Scrub::text($attempt->error), array_filter([$method?->external_reference])),
                'payment_group_id' => $attempt->payment_group_id,
                'key' => $attempt->key,
            ];
        })->values()->all();
    }

    /**
     * What an attempt needs staff to know, by the cron's own reading: taken and no payment of it recorded an hour later
     * (AutoCharge::tellOfTakenNotRecorded, SavedMethodCharge::recordedSince), an unknown outcome or a started one with no
     * answer past ChargeAttempt::HOLD_MINUTES (SavedMethodCharge::unknownAttempt), or the bank's confirmation asked.
     */
    public static function attentionOf(ChargeAttempt $attempt): ?string
    {
        return match ($attempt->status) {
            ChargeAttempt::STATUS_SUCCEEDED => SavedMethodCharge::recordedSince($attempt) ? null
                : ($attempt->sent_at->lessThanOrEqualTo(now()->subHour()) ? self::TAKEN_NOT_RECORDED : 'awaiting_payment'),
            ChargeAttempt::STATUS_UNKNOWN => self::UNKNOWN_OUTCOME,
            ChargeAttempt::STATUS_STARTED => $attempt->sent_at->lessThanOrEqualTo(now()->subMinutes(ChargeAttempt::HOLD_MINUTES)) ? self::UNKNOWN_OUTCOME : 'awaiting_answer',
            ChargeAttempt::STATUS_ACTION_REQUIRED => ChargeAttempt::STATUS_ACTION_REQUIRED,
            default => null,
        };
    }

    /** @return array<string, mixed> a saved method by its name and gateway (never its external reference) */
    private static function method(BillingAgreement $method, bool $whole = false): array
    {
        $gateway = Gateway::query()->withTrashed()->whereKey($method->gateway_id)->first();

        return ['id' => $method->id, 'name' => $method->name, 'gateway' => $gateway->name ?? "#{$method->gateway_id}"]
            + (!$whole ? [] : [
                'gateway_enabled' => (bool) ($gateway?->enabled) && !$gateway->trashed(),
                'type' => $method->type, 'expiry' => $method->expiry === null ? null : (string) $method->expiry,
                'failed_count' => (int) $method->failed_count, 'last_failed' => $method->last_failed_at?->toDateTimeString(),
                'added' => $method->created_at?->toDateTimeString(),
            ]);
    }
}
