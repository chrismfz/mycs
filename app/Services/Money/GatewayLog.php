<?php

namespace App\Services\Money;

use App\Helpers\NotificationHelper;
use App\Models\PaymentGatewayEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gateway log (docs/money-trail.md §4, MT-2a), one per request: a row opens when a route named
 * extensions.gateways.{gateway}.{endpoint} matches, RecordPaymentService tells what came of the money, and the row
 * closes with the response. No code in the gateways (Stripe and PayPal also say whether the signature held).
 *
 * Only names are kept: the request's field names and an event type such as «payment_intent.succeeded», never a value,
 * token or signature. When a gateway's request fails (5xx) staff are mailed, at most once an hour per endpoint: the
 * gateway says it charged and retries, while the money is not recorded.
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
     * What came of the money (RecordPaymentService), once it commits: a rolled-back payment reports nothing.
     *
     * @param  array{amount?: mixed, currency_code?: ?string, external_id?: ?string, invoice_id?: mixed, invoice_transaction_id?: mixed}  $details
     */
    public function result(string $outcome, ?string $reason = null, array $details = []): void
    {
        if ($this->event === null) {
            return;
        }
        DB::afterCommit(function () use ($outcome, $reason, $details) {
            if ($this->result !== null && (self::RANK[$this->result['outcome']] ?? 0) > (self::RANK[$outcome] ?? 0)) {
                return;
            }
            $this->result = ['outcome' => $outcome, 'reason' => $reason] + array_intersect_key($details, array_flip([
                'amount', 'currency_code', 'external_id', 'invoice_id', 'invoice_transaction_id',
            ]));
        });
    }

    /** The response went out: close the row. Nothing recorded on a success is «ignored», a 4xx «rejected», a 5xx an error. */
    public function finish(?Response $response): void
    {
        if ($this->event === null) {
            return;
        }
        $event = $this->event;
        $this->event = null;
        $status = $response?->getStatusCode() ?? 500;
        $values = $this->result ?? match (true) {
            $status >= 500 => ['outcome' => PaymentGatewayEvent::OUTCOME_ERROR, 'reason' => 'http_' . $status],
            $status >= 400 => ['outcome' => PaymentGatewayEvent::OUTCOME_REJECTED, 'reason' => 'http_' . $status],
            default => ['outcome' => PaymentGatewayEvent::OUTCOME_IGNORED, 'reason' => 'nothing_recorded'],
        };
        if ($status >= 500) {
            // Whatever was recorded, the gateway saw a failure and will try again: staff are told.
            $values['outcome'] = PaymentGatewayEvent::OUTCOME_ERROR;
            $values['reason'] ??= 'http_' . $status;
        }
        $values['currency_code'] = isset($values['currency_code']) ? strtoupper(substr((string) $values['currency_code'], 0, 3)) : null;
        $values['external_id'] = isset($values['external_id']) ? substr((string) $values['external_id'], 0, 255) : null;
        try {
            $event->forceFill($values + ['http_status' => $status])->save();
        } catch (\Throwable $e) {
            report($e);
        }
        $this->result = null;

        if ($event->outcome === PaymentGatewayEvent::OUTCOME_ERROR) {
            $this->alert($event);
        }
    }

    private function alert(PaymentGatewayEvent $event): void
    {
        $key = 'gateway-log-alert:' . $event->gateway . ':' . $event->endpoint;
        if (!Cache::add($key, true, self::ALERT_COOLDOWN_SECONDS)) {
            return;
        }
        $message = sprintf('A request from the %s gateway (%s) failed with HTTP %d: if it was a payment, it is NOT recorded and the gateway will retry. Gateway log #%d.',
            $event->gateway, $event->endpoint, (int) $event->http_status, $event->id);
        Log::warning($message, ['gateway_event_id' => $event->id]);
        try {
            NotificationHelper::sendSystemEmailNotification('Gateway request failed', e($message));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return list<string> The request's field names (query and body), never their values. */
    private static function fieldNames(Request $request): array
    {
        $names = array_filter(array_map('strval', array_keys($request->all())),
            fn (string $name) => preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $name) === 1);

        return array_slice(array_values($names), 0, self::MAX_FIELDS);
    }

    /** The event's type when the gateway names one («type», «event_type», «txn_type»): a short code, not data. */
    private static function eventType(Request $request): ?string
    {
        foreach (['type', 'event_type', 'txn_type'] as $field) {
            $value = $request->input($field);
            if (is_string($value) && preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $value) === 1) {
                return $value;
            }
        }

        return null;
    }
}
