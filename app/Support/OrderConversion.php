<?php

namespace App\Support;

use App\Models\Domain;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Service;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * A placed order's conversion (WG-13: GA4/Google Ads `purchase`, as myip's WHMCS hook sent on «order complete»). The cart
 * marks the order (queue); the next page of the client area prints the «Order complete code» setting once, its
 * placeholders filled from the order's invoice: the net subtotal as `value`, its tax apart, the currency, the coupon and
 * the lines as GA4 items. Never for a free order (nothing to pay: not a sale, as the hook), never for staff in «Login as
 * client», never on a private link's page. Each placeholder is a JSON value, safe inside a <script> (quotes, <, > and &
 * escaped): `{purchase}` the whole GA4 event, `{order_id}`, `{value}`, `{tax}`, `{currency}`, `{coupon}` (null when
 * none), `{items}`. Consent is the snippet's own (the gtag of the custom <head>): nothing is sent from the server.
 */
final class OrderConversion
{
    public const SESSION_KEY = 'order_conversion';

    private const PLACEHOLDERS = ['purchase', 'order_id', 'value', 'tax', 'currency', 'coupon', 'items'];

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION;

    /** The cart's: this order's conversion goes out on the next page (not for staff acting as the customer). */
    public static function queue(Order $order): void
    {
        if (!Actor::impersonating()) {
            session()->put(self::SESSION_KEY, $order->id);
        }
    }

    /** The layout's: the setting's code for the order the cart marked, once; null when there is nothing to send. */
    public static function render(): ?HtmlString
    {
        $id = session()->pull(self::SESSION_KEY);
        $code = (string) theme('order_complete_code', '');
        if ($id === null || trim($code) === '' || Actor::impersonating()) {
            return null;
        }
        try {
            // Only an order of the account this login works on: never another's, whatever the session holds.
            $order = Order::query()->whereKey((int) $id)->where('client_id', CurrentClient::id())->first();
            $payload = $order === null ? null : self::payload($order);
        } catch (Throwable $e) {
            report($e);
            $payload = null; // analytics never break the page
        }
        if ($payload === null) {
            return null;
        }
        $values = ['purchase' => $payload, 'order_id' => $payload['transaction_id'], 'value' => $payload['value'], 'tax' => $payload['tax'],
            'currency' => $payload['currency'], 'coupon' => $payload['coupon'] ?? null, 'items' => $payload['items']];
        $replace = [];
        foreach (self::PLACEHOLDERS as $name) {
            $replace['{' . $name . '}'] = (string) json_encode($values[$name], self::JSON);
        }

        return new HtmlString(strtr($code, $replace));
    }

    /**
     * GA4's `purchase` for an order, from its invoice (the amounts it bills), or null when it has none or nothing to pay.
     *
     * @return array{transaction_id: string, value: float, tax: float, currency: string, coupon?: string, items: list<array<string, mixed>>}|null
     */
    public static function payload(Order $order): ?array
    {
        $invoice = $order->invoice()->with('items.reference')->first();
        if ($invoice === null || (float) $invoice->subtotal <= 0) {
            return null;
        }
        $items = [];
        $coupon = null;
        foreach ($invoice->items as $item) {
            /** @var InvoiceItem $item */
            $reference = $item->reference;
            $line = ['item_name' => (string) $item->description, 'price' => round($item->net() / max(1, (int) $item->quantity), 2), 'quantity' => max(1, (int) $item->quantity)];
            if ($reference instanceof Service) {
                $line = ['item_id' => 'P' . $reference->product_id, 'item_name' => (string) ($reference->product->name ?? $item->description),
                    'item_category' => (string) ($reference->product->category->name ?? 'Hosting'), 'item_variant' => (string) ($reference->plan->name ?? '')] + $line;
                $coupon ??= $reference->coupon?->code;
            } elseif ($reference instanceof Domain) {
                $line = ['item_id' => 'D.' . $reference->tld, 'item_name' => 'Domain .' . $reference->tld, 'item_category' => 'Domains'] + $line;
            }
            $items[] = $line;
        }

        return array_filter([
            'transaction_id' => (string) $order->id,
            'value' => round((float) $invoice->subtotal, 2),
            'tax' => round((float) $invoice->taxTotal, 2),
            'currency' => (string) $invoice->currency_code,
            'coupon' => $coupon,
            'items' => $items,
        ], fn ($value) => $value !== null);
    }
}
