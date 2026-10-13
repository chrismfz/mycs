<?php

namespace App\Services\Money;

use App\Helpers\NotificationHelper;
use App\Models\InvoiceTransaction;
use App\Models\PaymentGatewayEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gateway log (docs/money-trail.md §4, MT-2a), one per request: a row opens when a route named
 * extensions.gateways.{gateway}.{endpoint} matches, RecordPaymentService tells what came of the money, and the row
 * closes with the response. No code in the gateways (Stripe and PayPal also say whether the signature held).
 *
 * Only names are kept: the request's field names and an event type such as «payment_intent.succeeded», never a value,
 * token or signature; a refused request (4xx) keeps not even the names. When a gateway's request fails (5xx) staff are
 * mailed, at most once an hour per endpoint, saying whether the money was recorded: the gateway retries either way.
 */
class GatewayLog
{
    private const MAX_FIELDS = 50;

    private const ALERT_COOLDOWN_SECONDS = 3600;

    /** Outcomes a payment can report, the stronger one kept when a request records several. */
    private const RANK = [
        PaymentGatewayEvent::OUTCOME_IGNORED => 1,
        PaymentGatewayEvent::OUTCOME_RECORDED => 2,
        PaymentGatewayEvent::OUTCOME_REJECTED => 3,
    ];

    private ?PaymentGatewayEvent $event = null;

    /** @var array<string, mixed>|null */
    private ?array $result = null;

    public static function current(): self
    {
        return app(self::class);
    }

    public function active(): bool
    {
        return $this->event !== null;
    }

    /** A gateway route matched: open its row (outside any transaction, so it stays whatever happens next). */
    public function start(Request $request, string $routeName): void
    {
        if ($this->event !== null) {
            $this->finish(null); // a second gateway route in one request: the first is closed as an error
        }
        $parts = explode('.', $routeName, 4); // extensions.gateways.{gateway}.{endpoint}
        try {
            $this->event = PaymentGatewayEvent::query()->create([
                'gateway' => substr($parts[2] ?? 'unknown', 0, 50),
                'endpoint' => substr($parts[3] ?? '', 0, 100),
                'method' => $request->getMethod(),
                'event_type' => self::eventType($request),
                'fields' => self::fieldNames($request),
            ]);
        } catch (\Throwable $e) {
            report($e); // the log never stops a payment
            $this->event = null;
        }
        $this->result = null;
    }

    /** What the gateway's signature check said (optional: the gateways that check one tell it). */
    public function signature(bool $verified): void
    {
        $this->event?->forceFill(['signature_verified' => $verified]);
    }

    /**
     * What came of the money (RecordPaymentService). Kept at once, not after commit: a callback that fails after the
     * commit (a renewal) must not hide money that was recorded. finish() checks that a recorded transaction exists, so a
     * rolled-back payment is never logged as recorded.
     *
     * @param  array{amount?: mixed, currency_code?: ?string, external_id?: ?string, invoice_id?: mixed, invoice_transaction_id?: mixed}  $details
     */
    public function result(string $outcome, ?string $reason = null, array $details = []): void
    {
        if ($this->event === null) {
            return;
        }
        if ($this->result !== null && (self::RANK[$this->result['outcome']] ?? 0) > (self::RANK[$outcome] ?? 0)) {
            return;
        }
        $this->result = ['outcome' => $outcome, 'reason' => $reason] + array_intersect_key($details, array_flip([
            'amount', 'currency_code', 'external_id', 'invoice_id', 'invoice_transaction_id',
        ]));
    }

    /**
     * The response went out: close the row. Nothing recorded on a success is «ignored», a 4xx «rejected», a 5xx an error.
     * Never throws: the log must not change a gateway's response.
     */
    public function finish(?Response $response): void
    {
        if ($this->event === null) {
            return;
        }
        $event = $this->event;
        $this->event = null;
        $result = $this->result;
        $this->result = null;
        try {
            $this->close($event, $result, $response?->getStatusCode() ?? 500);
            if (self::alarming($event)) {
                $this->alert($event);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param  array<string, mixed>|null  $result */
    private function close(PaymentGatewayEvent $event, ?array $result, int $status): void
    {
        // A payment reported as recorded that rolled back afterwards left no transaction: it was not recorded.
        if (($result['outcome'] ?? null) === PaymentGatewayEvent::OUTCOME_RECORDED
            && !InvoiceTransaction::query()->whereKey(self::id($result['invoice_transaction_id'] ?? null))->exists()) {
            $result = null;
        }
        $values = $result ?? match (true) {
            $status >= 500 => ['outcome' => PaymentGatewayEvent::OUTCOME_ERROR, 'reason' => 'http_' . $status],
            $status >= 400 => ['outcome' => PaymentGatewayEvent::OUTCOME_REJECTED, 'reason' => 'http_' . $status],
            default => ['outcome' => PaymentGatewayEvent::OUTCOME_IGNORED, 'reason' => 'nothing_recorded'],
        };
        if ($status >= 500) {
            // Whatever was recorded, the gateway saw a failure and will try again: staff are told.
            $values['outcome'] = PaymentGatewayEvent::OUTCOME_ERROR;
            $values['reason'] ??= 'http_' . $status;
        }
        if ($result === null && $status >= 400 && $status < 500) {
            $values['fields'] = null; // a refused request (forged, unknown) keeps nothing its sender wrote
            $values['event_type'] = null;
        }
        $values['currency_code'] = isset($values['currency_code']) ? strtoupper(substr((string) $values['currency_code'], 0, 3)) : null;
        $values['external_id'] = isset($values['external_id']) ? substr((string) $values['external_id'], 0, 255) : null;
        $values['invoice_id'] = self::id($values['invoice_id'] ?? null);
        $values['invoice_transaction_id'] = self::id($values['invoice_transaction_id'] ?? null);
        $values['amount'] = isset($values['amount']) && is_numeric($values['amount']) ? $values['amount'] : null;
        $event->forceFill($values + ['http_status' => $status])->save();
    }

    /**
     * Staff hear of a failure (5xx) unless the request failed its signature, and of an authentic request (signature
     * verified) refused for no money reason RecordPaymentService already mails: the gateway charged, nothing recorded.
     */
    private static function alarming(PaymentGatewayEvent $event): bool
    {
        if ($event->outcome === PaymentGatewayEvent::OUTCOME_ERROR) {
            return $event->signature_verified !== false;
        }

        return $event->outcome === PaymentGatewayEvent::OUTCOME_REJECTED && $event->signature_verified === true
            && ($event->reason === 'invoice_not_found' || str_starts_with((string) $event->reason, 'http_'));
    }

    private static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function alert(PaymentGatewayEvent $event): void
    {
        $key = 'gateway-log-alert:' . $event->gateway . ':' . $event->endpoint;
        try {
            $first = Cache::add($key, true, self::ALERT_COOLDOWN_SECONDS);
        } catch (\Throwable $e) {
            report($e);
            $first = true; // no cache: better a mail too many than none
        }
        if (!$first) {
            return;
        }
        $message = $event->outcome === PaymentGatewayEvent::OUTCOME_REJECTED
            ? sprintf('A verified request from the %s gateway (%s) was refused (HTTP %d, %s): the gateway charged and the money is NOT recorded. Gateway log #%d.',
                $event->gateway, $event->endpoint, (int) $event->http_status, $event->reason, $event->id)
            : ($event->invoice_transaction_id !== null
            ? sprintf('A request from the %s gateway (%s) failed with HTTP %d after its payment WAS recorded (transaction #%d): what follows the payment (renewal, provisioning) failed, and the gateway will retry. Gateway log #%d.',
                $event->gateway, $event->endpoint, (int) $event->http_status, $event->invoice_transaction_id, $event->id)
            : sprintf('A request from the %s gateway (%s) failed with HTTP %d: if it was a payment, it is NOT recorded and the gateway will retry. Gateway log #%d.',
                $event->gateway, $event->endpoint, (int) $event->http_status, $event->id));
        Log::warning($message, ['gateway_event_id' => $event->id]);
        NotificationHelper::sendSystemEmailNotification('Gateway request failed', e($message));
    }

    /**
     * A name, not a value: starts with a letter, letters, digits and $extra only, no run of 5 digits (a card number,
     * an account), and no dot before a short ending (a domain such as «shop.gr»).
     */
    private static function looksLikeAName(string $value, int $max, string $extra): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9' . preg_quote($extra, '/') . ']{0,' . ($max - 1) . '}$/', $value) === 1
            && preg_match('/\d{5,}/', $value) !== 1
            && preg_match('/\.[A-Za-z]{2,3}$/', $value) !== 1;
    }

    /** @return list<string> The request's field names (query and body), never their values. */
    private static function fieldNames(Request $request): array
    {
        $names = array_filter(array_map('strval', array_keys($request->all())),
            fn (string $name) => self::looksLikeAName($name, 64, '_\-'));

        return array_slice(array_values($names), 0, self::MAX_FIELDS);
    }

    /** The event's type when the gateway names one («type», «event_type», «txn_type»): a short code, not data. */
    private static function eventType(Request $request): ?string
    {
        foreach (['type', 'event_type', 'txn_type'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && self::looksLikeAName($value, 100, '_.')) {
                return $value;
            }
        }

        return null;
    }
}
