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

        $column = $type === 'http' ? 'context->url' : 'context->message';
        $shown = fn (array $context) => $type === 'http'
            ? (isset($context['url']) ? Scrub::text((string) $context['url']) : null) // Scrub masks URLs as Redact::url and the config secrets too
            : Scrub::text((string) ($context['message'] ?? ''));
        // Scrubbed whole, then cut: a secret cut in two would no longer be known to Scrub (security review, SEC-3).
        $cut = fn (mixed $value) => mb_substr((string) Scrub::text(is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 1500);

        // Application errors are written as «exception» (bootstrap/app.php, the admin's Error Logs: review of #419).
        $query = DebugLog::query()->where('type', $type === 'http' ? 'http' : 'exception');
        if ($search !== '') {
            // % and _ taken literally; a hit counts only if what is shown still holds it (SEC-2).
            $query->whereRaw($query->getQuery()->getGrammar()->wrap($column) . " LIKE ? ESCAPE '!'", ['%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%']);
        }

        return ['type' => $type, 'entries' => $query->latest('id')->limit(self::limit($request, 20, 100))->get()
            // context is utf8mb4_bin: the database compares bytes, so what is shown must hold the very bytes (not folded as stillShows).
            ->filter(fn (DebugLog $log) => $search === '' || (string) (((array) $log->context)[$type === 'http' ? 'url' : 'message'] ?? '') === (string) $shown((array) $log->context)
                || str_contains((string) $shown((array) $log->context), $search))
            ->values()->map(function (DebugLog $log) use ($type, $shown, $cut) {
                $context = (array) $log->context;

                return ['id' => $log->id, 'at' => $log->created_at?->toDateTimeString()] + ($type === 'http'
                    ? ['method' => $context['method'] ?? null, 'url' => $shown($context), 'status' => $context['response_status'] ?? null,
                        'response' => $cut($context['response'] ?? null)]
                    : ['message' => mb_substr((string) $shown($context), 0, 1500), 'file' => $context['file'] ?? null, 'line' => $context['line'] ?? null,
                        'trace' => $cut($context['trace'] ?? null)]);
            })->all()];
    }
}
