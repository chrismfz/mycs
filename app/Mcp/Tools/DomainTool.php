<?php

namespace App\Mcp\Tools;

use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Services\Ops\NotFound;
use App\Services\Ops\Scrub;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('domain')]
#[Description('One domain with everything staff see: status, account, registrar connection and its id there, dates (registered, transferred, expires, grace, redemption), years, renewal amount and auto-renew, lock, ID protection, DNS management, email forwarding, DNSSEC, nameservers, contact handles, IDN script, notes, last sync and its error, its invoice lines, and its latest registrar calls (action, status, error, invoice). Never the EPP code or the registrar\'s requests and answers. By id or name. Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class DomainTool extends McpTool
{
    public const CALLS = 15;

    public function schema(JsonSchema $schema): array
    {
        return ['domain' => $schema->string()->description('Domain id or name (example.gr).')->required()];
    }

    protected function permission(): string
    {
        return 'admin.domains.viewAny';
    }

    protected function run(Request $request): array
    {
        $key = mb_strtolower(trim((string) $request->get('domain')));
        $domain = Domain::query()->with(['client', 'registrarConnection', 'nameservers'])
            ->where(fn ($query) => $query->where('fqdn', $key)->when(ctype_digit($key), fn ($q) => $q->orWhere('id', (int) $key)))
            ->latest('id')->first() ?? throw new NotFound("No domain {$key}.");
        $date = fn ($value) => $value?->toDateString();

        return [
            'id' => $domain->id, 'domain' => $domain->fqdn, 'status' => $domain->status->value,
            'account' => '#' . $domain->client_id . ' ' . $domain->client->name,
            'registrar' => $domain->registrarConnection ? '#' . $domain->registrar_connection_id . ' ' . $domain->registrarConnection->registrar . ' (' . $domain->registrarConnection->label . ')' : null,
            'registered' => $date($domain->registered_at), 'expires' => $date($domain->expires_at),
            'grace_ends' => $date($domain->grace_ends_on), 'redemption_ends' => $date($domain->redemption_ends_on),
            'years' => $domain->years, 'recurring_amount' => $domain->recurring_amount . ' ' . $domain->currency_code, 'auto_renew' => (bool) $domain->auto_renew,
            'transferred' => $date($domain->transferred_at), 'transfer_lock' => (bool) $domain->transfer_lock, 'id_protection' => (bool) $domain->id_protection,
            'dns_management' => (bool) $domain->dns_management, 'email_forwarding' => (bool) $domain->email_forwarding, 'dnssec' => (bool) $domain->dnssec_enabled,
            'nameservers' => $domain->nameservers->sortBy('sort')->pluck('host')->values()->all(),
            'registrar_domain_id' => $domain->registrar_domain_id, 'idn_script' => $domain->idn_script,
            'contacts' => array_filter(['registrant' => $domain->registrant_handle, 'admin' => $domain->admin_handle, 'tech' => $domain->tech_handle, 'billing' => $domain->billing_handle]),
            'notes' => Scrub::text($domain->notes),
            'created_at' => $domain->created_at?->toDateTimeString(),
            'last_synced_at' => $domain->last_synced_at === null ? null : (string) $domain->last_synced_at, 'sync_error' => Scrub::text($domain->sync_error),
            'invoice_lines' => $domain->invoiceItems()->latest('id')->limit(10)->get()->map(fn ($line) => ['invoice_id' => $line->invoice_id,
                'description' => $line->description, 'total' => (string) $line->line_total, 'period' => trim(($line->period_start?->toDateString() ?? '') . ' – ' . ($line->period_end?->toDateString() ?? ''), ' –')])->all(),
            'registrar_calls' => RegistrarLogTool::rows(DomainRegistrarLog::query()->where('domain_id', $domain->id)->latest('id')->limit(self::CALLS)->get()),
        ];
    }
}
