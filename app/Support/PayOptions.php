<?php

namespace App\Support;

use App\Models\BillingAgreement;
use App\Models\Gateway;

/**
 * The ways to pay an invoice (or a Mass Pay group) by provider (docs/pay-flow-ux.md, PR 3): each gateway once, its saved
 * methods inside it and its one-time payment after them. A service picks a gateway, the gateway holds the saved methods,
 * so the page groups them the same way. What is offered does not change (the saved methods and the gateways given), nor
 * what is chosen: a saved method's ulid, or `gateway-{id}`.
 */
final class PayOptions
{
    /**
     * @param  iterable<BillingAgreement>  $saved  the account's saved methods that may be offered
     * @param  iterable<Gateway>  $gateways  the gateways that may take a one-time payment
     * @return list<array{gateway: Gateway, saved: list<BillingAgreement>, oneTime: bool}> groups with saved methods first
     *                                                                                     (the default's first), then the others in the order given
     */
    public static function byProvider(iterable $saved, iterable $gateways, ?int $defaultAgreementId = null): array
    {
        $groups = [];
        foreach ($saved as $agreement) {
            $gateway = $agreement->gateway;
            if ($gateway === null) {
                continue; // a method whose gateway is gone is never offered (savedPaymentMethods: an enabled gateway's)
            }
            $groups[$gateway->id] ??= ['gateway' => $gateway, 'saved' => [], 'oneTime' => false];
            $groups[$gateway->id]['saved'][] = $agreement;
        }
        foreach ($gateways as $gateway) {
            $groups[$gateway->id] ??= ['gateway' => $gateway, 'saved' => [], 'oneTime' => false];
            $groups[$gateway->id]['oneTime'] = true;
        }

        // Saved methods first, the default's group and the default itself at the top; the rest keep their order (sorts are
        // stable).
        $isDefault = fn (BillingAgreement $agreement): bool => $defaultAgreementId !== null && (int) $agreement->id === $defaultAgreementId;
        $rank = fn (array $group): int => match (true) {
            collect($group['saved'])->contains($isDefault) => 0,
            $group['saved'] !== [] => 1,
            default => 2,
        };
        $ordered = array_values($groups);
        usort($ordered, fn (array $a, array $b) => $rank($a) <=> $rank($b));

        return array_map(function (array $group) use ($isDefault) {
            usort($group['saved'], fn (BillingAgreement $a, BillingAgreement $b) => (int) $isDefault($b) <=> (int) $isDefault($a));

            return $group;
        }, $ordered);
    }
}
