<?php

namespace App\Services\Money;

use App\Helpers\NotificationHelper;
use App\Models\Invoice;
use App\Models\PaymentGatewayEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * This instance's mark on every payment it starts at a gateway (Stripe and Mollie metadata `mycs`, PayPal's invoice_id
 * `<mark>-<id>`), so an event it receives is known as its own (security audit S0-6): a gateway account shared with
 * another system (WHMCS until the cut-over, its late refunds and disputes after) sends events of payments this instance
 * never started, with invoice numbers of their own. Those never move money here: staff are told (foreign()).
 */
final class GatewayReference
{
    /** The mark: the `gateway_reference` setting, else a slug of APP_URL's host (e.g. «my-nixpal-com»). */
    public static function mark(): string
    {
        $set = trim((string) config('settings.gateway_reference', ''));
        if ($set !== '') {
            return Str::limit(Str::slug($set), 40, '');
        }
        $host = str_replace('.', '-', (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'mycs'));

        return Str::limit(Str::slug($host), 40, '') ?: 'mycs';
    }

    /** A mark a gateway sent back is this instance's. */
    public static function isOurs(mixed $mark): bool
    {
        return is_string($mark) && $mark !== '' && hash_equals(self::mark(), $mark);
    }

    /** PayPal's invoice_id for an invoice of this instance: unique on a shared account too. */
    public static function paypalInvoiceId(Invoice $invoice): string
    {
        return self::mark() . '-' . $invoice->id;
    }

    /** The invoice id in a PayPal invoice_id, when it is this instance's (`<mark>-<id>`); null for any other. */
    public static function invoiceIdFromPaypal(mixed $value): ?int
    {
        $prefix = self::mark() . '-';
        if (!is_string($value) || !str_starts_with($value, $prefix)) {
            return null;
        }
        $id = substr($value, strlen($prefix));

        return ctype_digit($id) ? (int) $id : null;
    }

    /**
     * An event about a payment this instance did not start, or one it has no record of (a refund, a dispute of a
     * WHMCS-era payment after the cut-over): money never moves for it; it is kept in the gateway log as «foreign»
     * and staff are told, to handle by hand.
     */
    public static function foreign(string $gateway, string $what, ?string $reference = null, mixed $amount = null, ?string $currency = null): void
    {
        GatewayLog::current()->result(PaymentGatewayEvent::OUTCOME_FOREIGN, Str::limit($what, 60, ''), [
            'amount' => $amount, 'currency_code' => $currency, 'external_id' => $reference,
        ]);
        $message = sprintf('The %s gateway sent an event (%s) for a payment this site did not make or has no record of%s%s. Nothing was recorded here. If its payment reaches this site later, a refund or chargeback held for it is applied then by itself: do not also act on it by hand. Otherwise check it at the gateway (and in the old system, for a payment made before the move).',
            $gateway, $what, $reference !== null ? ' (gateway id ' . $reference . ')' : '',
            is_numeric($amount) ? ', ' . number_format((float) $amount, 2, '.', '') . ' ' . strtoupper((string) $currency) : '');
        PaymentsLog::warning($message, ['gateway' => $gateway, 'reference' => $reference]);
        // Once a day for the same event: an unsigned webhook (Mollie's) can be posted again and again (#328).
        if (Cache::add('gateway-foreign:' . hash('sha256', $gateway . '|' . $what . '|' . ($reference ?? '')), true, now()->addDay())) {
            NotificationHelper::sendSystemEmailNotification('Gateway event for a payment not made here', e($message));
        }
    }
}
