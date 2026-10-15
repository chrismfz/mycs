<?php

namespace App\Services\Ops;

use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\ModuleAction;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Models\Audit;

/**
 * Everything about one service, account or invoice on one page (docs/ops.md), for troubleshooting: read-only, no
 * secret (no password, no PSK, a license key by its prefix and last 4, error texts through Scrub; of the audit only
 * which fields changed). The same answer for `mycs:service`,
 * `mycs:client`, `mycs:invoice` and the MCP.
 */
final class Dossier
{
    public const RECENT = 10;

    /** @return array<string, mixed> */
    public static function service(Service $service): array
    {
        $service->loadMissing(['product', 'plan', 'server', 'order', 'license', 'cancellation', 'client']);

        return [
            'id' => $service->id,
            'status' => $service->status,
            'account' => self::account($service->client),
            'product' => $service->product ? "#{$service->product->id} {$service->product->name}" : null,
            'plan' => $service->plan ? "#{$service->plan->id} {$service->plan->name} ({$service->plan->type})" : null,
            'price' => $service->price . ' ' . $service->currency_code . ' × ' . $service->quantity,
            'domain' => $service->license && $service->domain === $service->license->license_key ? Scrub::licenseKey($service->domain) : $service->domain,
            'username' => $service->username,
            'server' => $service->server ? "#{$service->server->id} {$service->server->name} ({$service->server->extension})" : null,
            'dates' => array_map(fn ($date) => $date?->toDateTimeString(), [
                'created' => $service->created_at, 'activated' => $service->activated_at, 'expires' => $service->expires_at,
                'suspend_hold_until' => $service->suspend_hold_until, 'dunning_suspended' => $service->dunning_suspended_at,
            ]),
            'suspend_reason' => $service->suspend_reason,
            'order' => $service->order ? ['id' => $service->order->id, 'status' => $service->order->status] : null,
            'cancellation' => $service->cancellation ? ['type' => $service->cancellation->type, 'reason' => $service->cancellation->reason,
                'requested' => $service->cancellation->created_at?->toDateTimeString()] : null,
            'license' => $service->license ? [
                'key' => Scrub::licenseKey($service->license->license_key), 'status' => $service->license->status, 'valid_ip' => $service->license->valid_ip,
                'valid_domain' => $service->license->valid_domain, 'reissues' => $service->license->reissues,
                'last_ok' => $service->license->last_ok_at?->toDateTimeString(), 'last_seen' => trim($service->license->last_seen_ip . ' ' . $service->license->last_seen_client) ?: null,
                'last_failure' => $service->license->last_failure_at ? $service->license->last_failure_at->toDateTimeString() . ': ' . Scrub::text($service->license->last_failure) : null,
            ] : null,
            'invoices' => $service->invoices()->latest('invoices.id')->limit(self::RECENT)->get()->map(fn (Invoice $invoice) => self::invoiceLine($invoice))->all(),
            'module_actions' => ModuleAction::query()->where('service_id', $service->id)->latest('id')->limit(self::RECENT)->get()
                ->map(fn (ModuleAction $action) => ['id' => $action->id, 'action' => $action->action, 'status' => $action->status, 'attempts' => $action->attempts,
                    'given_up' => $action->given_up_at ? (string) $action->given_up_at : null, 'error' => Scrub::text($action->last_error), 'at' => $action->updated_at?->toDateTimeString()])->all(),
            'emails' => self::emails($service->emailLogs()),
            'audit' => self::audit($service),
        ];
    }

    /** @return array<string, mixed> */
    public static function client(Client $client): array
    {
        $client->loadMissing(['primaryContact', 'ownerGrant.user', 'credits']);

        return [
            ...self::account($client),
            'company' => $client->primaryContact?->company_name,
            'login' => $client->ownerGrant?->user?->email,
            'credit' => $client->credits->mapWithKeys(fn ($credit) => [$credit->currency_code => (string) $credit->amount])->all(),
            'services' => $client->services()->with('product')->latest('id')->limit(50)->get()->map(fn (Service $service) => [
                'id' => $service->id, 'status' => $service->status, 'product' => $service->product?->name, 'domain' => $service->license && $service->domain === $service->license->license_key ? Scrub::licenseKey($service->domain) : $service->domain,
                'expires' => $service->expires_at?->toDateString()])->all(),
            'invoices' => $client->invoices()->latest('id')->limit(self::RECENT)->get()->map(fn (Invoice $invoice) => self::invoiceLine($invoice))->all(),
            'open_invoices' => $client->invoices()->whereIn('status', Invoice::OPEN_STATUSES)->count(),
            'emails' => self::emails($client->emailLogs()),
            'audit' => self::audit($client),
        ];
    }

    /** @return array<string, mixed> */
    public static function invoice(Invoice $invoice): array
    {
        $invoice->loadMissing(['client', 'items', 'transactions.gateway']);

        return [
            ...self::invoiceLine($invoice),
            'account' => self::account($invoice->client),
            'items' => $invoice->items->map(fn ($item) => ['description' => $item->description, 'quantity' => $item->quantity, 'price' => (string) $item->price,
                'reference' => $item->reference_type ? class_basename($item->reference_type) . ' #' . $item->reference_id : null,
                'period' => $item->period_start ? $item->period_start->toDateString() . ' → ' . $item->period_end?->toDateString() : null])->all(),
            'transactions' => $invoice->transactions->map(fn (InvoiceTransaction $transaction) => ['id' => $transaction->id,
                'gateway' => $transaction->gateway?->name, 'amount' => (string) $transaction->amount, 'status' => $transaction->status->value,
                'reference' => $transaction->transaction_id, 'at' => $transaction->created_at?->toDateTimeString()])->all(),
            'emails' => self::emails($invoice->emailLogs()),
            'audit' => self::audit($invoice),
        ];
    }

    /** @return array<string, mixed> */
    private static function invoiceLine(Invoice $invoice): array
    {
        return ['id' => $invoice->id, 'number' => $invoice->number, 'status' => $invoice->status, 'currency' => $invoice->currency_code,
            'total' => (string) $invoice->formattedTotal, 'remaining' => (string) $invoice->formattedRemaining,
            'issued' => $invoice->created_at?->toDateString(), 'due' => $invoice->due_at?->toDateString()];
    }

    /** @return array<string, mixed> */
    private static function account(?Client $client): array
    {
        if ($client === null) {
            return ['account' => null];
        }

        return ['account_id' => $client->id, 'name' => $client->name, 'email' => $client->primaryContact?->email];
    }

    /** @return list<array<string, mixed>> */
    private static function emails($relation): array
    {
        return $relation->latest('id')->limit(self::RECENT)->get()->map(fn (EmailLog $log) => ['id' => $log->id, 'template' => $log->template_key,
            'to' => $log->to, 'status' => $log->status, 'queued' => $log->created_at?->toDateTimeString(), 'error' => Scrub::text($log->error)])->all();
    }

    /** Who changed what and when; the fields only, never their values (a secret may be one). @return list<array<string, mixed>> */
    private static function audit(Model $model): array
    {
        if (!method_exists($model, 'audits')) {
            return [];
        }

        return $model->audits()->latest('id')->limit(self::RECENT)->get()->map(fn (Audit $audit) => ['event' => $audit->event,
            'fields' => array_keys((array) $audit->new_values + (array) $audit->old_values),
            'by' => $audit->user_type ? class_basename($audit->user_type) . ' #' . $audit->user_id : 'system', 'at' => $audit->created_at?->toDateTimeString()])->all();
    }
}
