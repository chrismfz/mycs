<?php

namespace App\Mcp\Tools;

use App\Models\DebugLog;
use App\Services\Ops\Scrub;
use App\Support\Redact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('http_error_logs')]
#[Description('The admin\'s HTTP Logs and Error Logs (kept in the database), newest first. http: each outgoing call (method, URL with secrets masked, response status, the start of the answer). error: each application error (message, file, line, the head of the trace). Filters: type, a text in the URL or message. For "the call to the registrar/panel/gateway failed". Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class AppLogTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(['http', 'error'])->description('Which log (default error).'),
            'search' => $schema->string()->description('Only entries whose URL (http) or message (error) contains this.'),
            'limit' => $schema->integer()->description('How many (default 20, at most 100).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.debug_logs.view';
    }

    protected function run(Request $request): array
    {
        $type = $request->get('type') === 'http' ? 'http' : 'error';
        $search = trim((string) $request->get('search'));

        // Application errors are written as «exception» (bootstrap/app.php, the admin's Error Logs: review of #419).
        return ['type' => $type, 'entries' => DebugLog::query()->where('type', $type === 'http' ? 'http' : 'exception')
            ->when($search !== '', fn ($query) => $query->where($type === 'http' ? 'context->url' : 'context->message', 'like', '%' . $search . '%'))
            ->latest('id')->limit(self::limit($request, 20, 100))->get()
            ->map(function (DebugLog $log) use ($type) {
                $context = (array) $log->context;

                return ['id' => $log->id, 'at' => $log->created_at?->toDateTimeString()] + ($type === 'http'
                    ? ['method' => $context['method'] ?? null, 'url' => isset($context['url']) ? Redact::url((string) $context['url']) : null,
                        'status' => $context['response_status'] ?? null,
                        'response' => Scrub::text(mb_substr(is_string($context['response'] ?? null) ? $context['response'] : (string) json_encode($context['response'] ?? null), 0, 1500))]
                    : ['message' => Scrub::text(mb_substr((string) ($context['message'] ?? ''), 0, 1500)), 'file' => $context['file'] ?? null, 'line' => $context['line'] ?? null,
                        'trace' => Scrub::text(mb_substr(is_string($context['trace'] ?? null) ? $context['trace'] : (string) json_encode($context['trace'] ?? null), 0, 1500))]);
            })->all()];
    }
}
