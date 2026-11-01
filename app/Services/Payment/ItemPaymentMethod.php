<?php

namespace App\Services\Payment;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Services\Invoice\SplitInvoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A service's or domain's own payment method (owner 2026-10-29, as WHMCS's paymentmethod): none is its account's (the
 * saved method charges its renewals, as before); a gateway's, its renewals are invoiced apart with it preferred, charged
 * by the account's saved method of that gateway if it has one (owner 2026-11-01), never by one of another gateway (their
 * lines record it: AutoCharge::agreementFor). Set by the customer on its page, by
 * staff in the admin, from WHMCS at import; an invoice's own choice stays the invoice's. Its own «Do not charge
 * automatically» keeps any method from its invoices (#568).
 */
final class ItemPaymentMethod
{
    /** @return Collection<int, Gateway> the gateways it may pay with: those its invoice would offer */
    public static function options(Service|Domain $item): Collection
    {
        $amount = $item instanceof Service ? (float) $item->price : (float) $item->recurring_amount;

        return collect(ExtensionHelper::getCheckoutGateways($amount, (string) $item->currency_code, 'invoice'));
    }

    /** Its own payment method set, or the account's (null); a gateway not on offer is refused. */
    public static function set(Service|Domain $item, ?int $gatewayId): void
    {
        if ($gatewayId === ($item->payment_gateway_id === null ? null : (int) $item->payment_gateway_id)) {
            return; // as it is (one no longer offered stays until another is chosen)
        }
        if ($gatewayId !== null && !self::options($item)->contains(fn (Gateway $gateway) => (int) $gateway->id === $gatewayId)) {
            throw new DisplayException(__('services.payment_method_not_available'));
        }
        $item->update(['payment_gateway_id' => $gatewayId]);
    }

    /** Its own payment method's gateway, still there (a deleted one: none, the account's). Its lines record it when billed. */
    public static function live(Service|Domain $item): ?int
    {
        $own = $item->payment_gateway_id === null ? null : (int) $item->payment_gateway_id;

        return $own !== null && Gateway::query()->whereKey($own)->exists() ? $own : null;
    }

    /**
     * The payment method its renewals go apart by (WHMCS bills each payment method on its own invoice): its own (live),
     * unless it is its account's saved method's gateway (given, or read), which charges the rest: none then, one invoice
     * with them. Not the gateway it last paid with (round 2 of #541: a card saved later would find a PayPal line among
     * the ones it should charge).
     */
    public static function apart(Service|Domain $item, int|false|null $account = false): ?int
    {
        $own = self::live($item);
        if ($own === null) {
            return null;
        }
        $account = $account === false ? Invoice::savedMethodGatewayFor((int) $item->client_id) : $account;

        return $own === $account ? null : $own;
    }

    /**
     * The saved method that charges its renewals: the account's, or the account's method of its own payment method's
     * gateway when that is another (Client::autoChargeMethodFor, owner 2026-11-01); none of that gateway, none.
     */
    public static function chargedBy(Service|Domain $item): ?BillingAgreement
    {
        if ($item->no_auto_charge) {
            return null; // its own «Do not charge automatically» (#568)
        }
        $own = $item->payment_gateway_id === null || $item->paymentGateway === null ? null : (int) $item->payment_gateway_id; // deleted: the account's

        return $item->client?->autoChargeAgreementFor($own);
    }

    /**
     * What its page tells the customer and staff (owner 2026-11-01: no misunderstanding): charged automatically with which
     * method, or why not, by the cron's rules (AutoCharge::due and agreementFor; review 2 of #568: a suspended service, a
     * domain not renewing, a method stopped at its failures, an open invoice held by another item on it).
     */
    public static function autoChargeStatus(Service|Domain $item): string
    {
        $status = fn (string $key, array $with = []) => __("services.auto_charge_status.{$key}", $with);
        if ($item instanceof Service && (string) $item->subscription_id !== '') {
            return $status('subscription');
        }
        if ($item->no_auto_charge) {
            return $status('off');
        }
        if ($item instanceof Service && $item->status !== Service::STATUS_ACTIVE) {
            return $status('not_active');
        }
        if ($item instanceof Domain && (!$item->auto_renew || in_array($item->status->value, DomainStatus::terminalValues(), true))) {
            return $status('not_renewing');
        }
        // Its open invoice is charged as it was billed (#541: a change counts from the next renewal), so the page says what
        // the cron will do with it (ADV-568-R2-6), and why not.
        $open = self::openInvoice($item);
        if ($open !== null) {
            $agreement = AutoCharge::agreementFor($open);
            if ($agreement !== null) {
                return $status('charged', ['method' => $agreement->name]);
            }
            if ($open->no_auto_charge) {
                return $status('invoice_off', ['invoice' => $open->id]);
            }
            if ($open->items()->where(fn ($lines) => self::kept($lines))->exists()) {
                return $status('held', ['invoice' => $open->id]);
            }
        }
        $client = $item->client;
        if ($client === null || !$client->auto_charge) {
            return $status('account_off');
        }
        $default = $client->autoChargeMethod();
        if ($default === null) {
            return $status('no_default');
        }
        $own = self::live($item);
        if ($own !== null && $own !== (int) $default->gateway_id) {
            $gateway = Gateway::query()->find($own);
            if ($gateway !== null && !self::savesMethods($gateway)) {
                return $status('manual', ['gateway' => $gateway->name]);
            }
            $agreement = $client->autoChargeMethodFor($own);
            if ($agreement === null) {
                return $status('no_method', ['gateway' => (string) $gateway?->name]);
            }
        } else {
            $agreement = $default;
        }
        if (!ExtensionHelper::gatewayEnabled($agreement->gateway_id)) {
            return $status('gateway_off', ['gateway' => (string) Gateway::query()->withTrashed()->whereKey($agreement->gateway_id)->value('name')]);
        }
        $max = max(0, (int) config('settings.autocharge_max_failures', 0));
        if ($max > 0 && $agreement->failed_count >= $max) {
            return $status('stopped', ['method' => $agreement->name]);
        }
        if ($open !== null) {
            // Its next renewal would be; the open one is not (a confirmation the bank asked for, lines of two ways).
            return $status('open_manual', ['invoice' => $open->id]);
        }

        return $status('charged', ['method' => $agreement->name]);
    }

    /** Its open invoice the cron would charge next: the earliest due, unpaid, with a line of it. */
    private static function openInvoice(Service|Domain $item): ?Invoice
    {
        return Invoice::query()->where('status', Invoice::STATUS_UNPAID)
            ->whereIn('id', InvoiceItem::query()->select('invoice_id')->where('reference_type', $item::class)->where('reference_id', $item->id))
            ->orderBy('due_at')->orderBy('id')->first();
    }

    /**
     * The lines of a service or domain set «Do not charge automatically» (#568), as they are now.
     *
     * @param  Builder<InvoiceItem>  $lines
     * @return Builder<InvoiceItem>
     */
    public static function kept(Builder $lines): Builder
    {
        return $lines->where(fn ($line) => $line->where('reference_type', Service::class)->whereIn('reference_id', Service::query()->select('id')->where('no_auto_charge', true)))
            ->orWhere(fn ($line) => $line->where('reference_type', Domain::class)->whereIn('reference_id', Domain::query()->select('id')->where('no_auto_charge', true)));
    }

    /** A gateway that saves a payment method to charge later (Stripe, PayPal); a bank transfer or a vPOS redirect does not. */
    private static function savesMethods(Gateway $gateway): bool
    {
        return (bool) rescue(fn () => ExtensionHelper::hasFunction($gateway, 'supportsBillingAgreements')
            && ExtensionHelper::getExtension('gateway', $gateway->extension, $gateway->settings)->supportsBillingAgreements(), false, false);
    }

    /** Its own «Do not charge automatically» (#568), audited: its open invoices too (keepApart, from the observers). */
    public static function setAutoCharge(Service|Domain $item, bool $on): void
    {
        if ($item->no_auto_charge === !$on) {
            return;
        }
        $item->update(['no_auto_charge' => !$on]);
    }

    /**
     * Turned off (review 2 of #568): its lines leave an open invoice they share with items still charged, for an invoice of
     * their own (SplitInvoice), so the cron still charges the others. One that cannot be split (a payment on it, a discount
     * that would be left alone) stays whole and is not charged automatically: autoChargeStatus says so on the others.
     */
    public static function keepApart(Service|Domain $item): void
    {
        $open = InvoiceItem::query()->where('reference_type', $item::class)->where('reference_id', $item->id)
            ->whereIn('invoice_id', Invoice::query()->select('id')->where('status', Invoice::STATUS_UNPAID))->distinct()->pluck('invoice_id');
        foreach ($open as $invoiceId) {
            $mine = InvoiceItem::query()->where('invoice_id', $invoiceId)->where('reference_type', $item::class)->where('reference_id', $item->id)->pluck('id');
            $charged = InvoiceItem::query()->where('invoice_id', $invoiceId)->whereNotIn('id', $mine)->whereIn('reference_type', [Service::class, Domain::class])
                ->whereNot(fn ($lines) => self::kept($lines))->exists();
            if (!$charged) {
                continue; // alone, or with others kept too: held as it is
            }
            try {
                (new SplitInvoice)->handle((int) $invoiceId, $mine->map(fn ($id) => (int) $id)->all());
            } catch (DisplayException) {
                // Stays whole, held: its others are paid from it (their page says so).
            }
        }
    }
}
