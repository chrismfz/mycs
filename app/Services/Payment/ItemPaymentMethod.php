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
 * saved method charges its renewals, as before); a gateway's, its renewals are invoiced apart with it preferred, and a
 * saved method of another gateway never charges them (their lines record it: AutoCharge::agreementFor). Set by the customer on its page, by
 * staff in the admin, from WHMCS at import; an invoice's own choice stays the invoice's.
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

    /** The saved method that charges its renewals: the account's, unless its own payment method is another gateway's. */
    public static function chargedBy(Service|Domain $item): ?BillingAgreement
    {
        $agreement = $item->client?->autoChargeAgreement();
        $own = $item->payment_gateway_id === null || $item->paymentGateway === null ? null : (int) $item->payment_gateway_id; // deleted: the account's
        if ($agreement === null || ($own !== null && $own !== (int) $agreement->gateway_id)) {
            return null;
        }

        return $agreement;
    }
}
