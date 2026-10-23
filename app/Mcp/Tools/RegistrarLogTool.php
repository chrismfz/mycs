<?php

namespace App\Mcp\Tools;

use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Services\Ops\Scrub;
use App\Support\Redact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('registrar_log')]
#[Description('The latest calls to the domain registrars, newest first: domain, action (register, transfer, renew, restore, sync, lock…), status (ok, pending, failed, adopted), the error (secrets masked), the invoice. Never the requests, answers or EPP codes. Filters: status, action, domain. For "the renewal failed at the registrar". Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class RegistrarLogTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['ok', 'pending', 'failed', 'adopted'])->description('Only this status.'),
            'action' => $schema->string()->description('Only this action (renew, transfer, sync…).'),
            'domain' => $schema->string()->description('Only this domain (name).'),
            'limit' => $schema->integer()->description('How many (default 30, at most 200).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.domains.viewAny';
    }

    protected function run(Request $request): array
    {
        $domain = trim((string) $request->get('domain'));

        return ['calls' => self::rows(DomainRegistrarLog::query()
            ->when(filled($request->get('status')), fn ($query) => $query->where('status', (string) $request->get('status')))
            ->when(filled($request->get('action')), fn ($query) => $query->where('action', (string) $request->get('action')))
            ->when($domain !== '', fn ($query) => $query->whereIn('domain_id', Domain::query()->where('fqdn', mb_strtolower($domain))->select('id')))
            ->latest('id')->limit(self::limit($request, 30, 200))->get())];
    }

    /**
     * What a registrar call may show: never its request, answer or transactions (credentials, EPP codes).
     *
     * @param  Collection<int, DomainRegistrarLog>  $logs
     * @return list<array<string, mixed>>
     */
    public static function rows(Collection $logs): array
    {
        $domains = Domain::query()->whereIn('id', $logs->pluck('domain_id')->filter())->get(['id', 'fqdn', 'epp_code'])->keyBy('id');
        // A registrar's error may repeat the domain's EPP code (an EPP <extValue>): masked, as its writers do (review of #419).
        // Masked first, then cut: a code across the cut would show its start (review of #419).
        $error = fn (DomainRegistrarLog $log) => mb_substr(Redact::values((string) Scrub::text((string) $log->error),
            array_filter([$domains[$log->domain_id]->epp_code ?? null])), 0, 1000) ?: null;

        return $logs->map(fn (DomainRegistrarLog $log) => ['id' => $log->id, 'at' => $log->created_at?->toDateTimeString(),
            'domain' => $domains[$log->domain_id]->fqdn ?? null, 'action' => $log->action, 'status' => $log->status,
            'error' => $error($log), 'invoice_id' => $log->invoice_id,
            'by' => $log->staff_id ? 'staff #' . $log->staff_id : ($log->user_id ? 'user #' . $log->user_id : 'system')])->values()->all();
    }
}
