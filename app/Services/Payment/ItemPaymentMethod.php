<?php

namespace App\Services\Payment;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
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
     * method, or why not, as chargedBy() decides it.
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
        $client = $item->client;
        $default = $client?->auto_charge ? $client->autoChargeMethod() : null;
        if ($client === null || !$client->auto_charge) {
            return $status('account_off');
        }
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
            return $status('gateway_off', ['gateway' => (string) $agreement->gateway?->name]);
        }

        return $status('charged', ['method' => $agreement->name]);
    }

    /** A gateway that saves a payment method to charge later (Stripe, PayPal); a bank transfer or a vPOS redirect does not. */
    private static function savesMethods(Gateway $gateway): bool
    {
        return (bool) rescue(fn () => ExtensionHelper::hasFunction($gateway, 'supportsBillingAgreements')
            && ExtensionHelper::getExtension('gateway', $gateway->extension, $gateway->settings)->supportsBillingAgreements(), false, false);
    }

    /** Its own «Do not charge automatically» (#568), audited: from now on, its open invoice included. */
    public static function setAutoCharge(Service|Domain $item, bool $on): void
    {
        if ($item->no_auto_charge === !$on) {
            return;
        }
        $item->update(['no_auto_charge' => !$on]);
    }
}
