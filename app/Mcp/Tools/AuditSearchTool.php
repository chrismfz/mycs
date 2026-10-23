<?php

namespace App\Mcp\Tools;

use App\Models\Staff;
use App\Models\User;
use App\Services\Ops\Scrub;
use App\Support\AuditValue;
use App\Support\Redact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use OwenIt\Auditing\Models\Audit;

#[Name('audit_search')]
#[Description('Search the audit log (the Activity Log): who changed what and when, newest first, with each change\'s old and new value (secrets masked, long values cut). Filters: the record (type, e.g. Invoice, Service, Client, Domain, Ticket, and id), who (staff or user id), the event (created, updated, deleted, or a custom one), and a date range. For "who suspended this service / changed this price". Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class AuditSearchTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('The record\'s kind: a model name (Invoice, Service, Client, Domain, Ticket, Staff…).'),
            'id' => $schema->integer()->description('The record\'s id (with type).'),
            'staff' => $schema->integer()->description('Changes made by this staff member.'),
            'user' => $schema->integer()->description('Changes made by this customer login.'),
            'event' => $schema->string()->description('Only this event.'),
            'from' => $schema->string()->description('From this date (YYYY-MM-DD).'),
            'to' => $schema->string()->description('Up to this date (YYYY-MM-DD), included.'),
            'limit' => $schema->integer()->description('How many (default 30, at most 200).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.audit.viewAny';
    }

    protected function run(Request $request): array
    {
        $type = trim((string) $request->get('type'));
        $class = $type === '' ? null : (class_exists($model = 'App\\Models\\' . ucfirst($type)) ? $model : $type);
        $date = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->get($key)) === 1 ? (string) $request->get($key) : null;

        $audits = Audit::query()
            ->when($class !== null, fn ($query) => $query->where('auditable_type', $class))
            ->when(filled($request->get('id')), fn ($query) => $query->where('auditable_id', (int) $request->get('id')))
            ->when(filled($request->get('staff')), fn ($query) => $query->where('user_type', Staff::class)->where('user_id', (int) $request->get('staff')))
            ->when(filled($request->get('user')), fn ($query) => $query->where('user_type', User::class)->where('user_id', (int) $request->get('user')))
            ->when(filled($request->get('event')), fn ($query) => $query->where('event', (string) $request->get('event')))
            ->when($date('from') !== null, fn ($query) => $query->where('created_at', '>=', $date('from') . ' 00:00:00'))
            ->when($date('to') !== null, fn ($query) => $query->where('created_at', '<=', $date('to') . ' 23:59:59'))
            ->latest('id')->limit(self::limit($request, 30, 200))->get();

        return ['audits' => $audits->map(fn (Audit $audit) => [
            'id' => $audit->id, 'at' => $audit->created_at?->toDateTimeString(), 'event' => $audit->event,
            'record' => class_basename((string) $audit->auditable_type) . ' #' . $audit->auditable_id,
            'by' => $audit->user_type ? class_basename($audit->user_type) . ' #' . $audit->user_id : 'system',
            'ip' => $audit->ip_address,
            // A secret field (a password, a token) by its name only; the rest masked as text, cut.
            'changes' => collect(AuditValue::modified($audit))->map(fn ($change, $field) => array_map(
                fn ($value) => Redact::isSecretKey((string) $field) ? '********' : Scrub::text(mb_substr(AuditValue::text($value), 0, 500)), (array) $change))->all(),
        ])->all()];
    }
}
