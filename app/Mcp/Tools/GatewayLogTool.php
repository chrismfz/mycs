<?php

namespace App\Mcp\Tools;

use App\Models\PaymentGatewayEvent;
use App\Services\Ops\Scrub;
use App\Support\Redact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('gateway_log')]
#[Description('The payment gateways\' log (Gateway Log), newest first: gateway, endpoint, event, whether the signature was verified, outcome and reason, amount, invoice and transaction, HTTP status, and the event\'s fields with secrets masked. Filters: gateway, outcome, invoice. For "the customer paid but the invoice is unpaid". Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class GatewayLogTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'gateway' => $schema->string()->description('Only this gateway (PayPal, Stripe, Mollie, Cardlink…).'),
            'outcome' => $schema->string()->description('Only this outcome (recorded, ignored, rejected…).'),
            'invoice' => $schema->integer()->description('Only events about this invoice id.'),
            'limit' => $schema->integer()->description('How many (default 30, at most 200).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.gateway_events.viewAny';
    }

    protected function run(Request $request): array
    {
        return ['events' => PaymentGatewayEvent::query()
            ->when(filled($request->get('gateway')), fn ($query) => $query->where('gateway', (string) $request->get('gateway')))
            ->when(filled($request->get('outcome')), fn ($query) => $query->where('outcome', (string) $request->get('outcome')))
            ->when(filled($request->get('invoice')), fn ($query) => $query->where('invoice_id', (int) $request->get('invoice')))
            ->latest('id')->limit(self::limit($request, 30, 200))->get()
            ->map(fn (PaymentGatewayEvent $event) => ['id' => $event->id, 'at' => $event->created_at?->toDateTimeString(), 'gateway' => $event->gateway,
                'endpoint' => $event->endpoint, 'method' => $event->method, 'event' => $event->event_type, 'signature_verified' => (bool) $event->signature_verified,
                'outcome' => $event->outcome, 'reason' => Scrub::text($event->reason), 'amount' => $event->amount !== null ? $event->amount . ' ' . $event->currency_code : null,
                'external_id' => $event->external_id, 'invoice_id' => $event->invoice_id, 'transaction_id' => $event->invoice_transaction_id, 'http_status' => $event->http_status,
                'fields' => Redact::array((array) $event->fields)])->all()];
    }
}
