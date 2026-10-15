<?php

namespace App\Mcp\Tools;

use App\Services\Ops\LogTail;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('logs')]
#[Description('The latest entries of the app log, newest first, at a level and above, each with the head of its stack; secrets masked. channel: laravel (default) or payments. Same as `mycs:logs`.')]
#[IsReadOnly]
#[IsIdempotent]
class LogsTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'level' => $schema->string()->enum(['debug', 'info', 'notice', 'warning', 'error', 'critical'])->description('Lowest level (default error).'),
            'channel' => $schema->string()->enum(['laravel', 'payments'])->description('Which log (default laravel).'),
            'limit' => $schema->integer()->description('How many (default 20, at most 200).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.failed_jobs.viewAny';
    }

    protected function run(Request $request): array
    {
        $channel = in_array($request->get('channel'), ['laravel', 'payments'], true) ? $request->get('channel') : 'laravel';

        return ['entries' => LogTail::entries(self::limit($request, 20, LogTail::MAX_ENTRIES), (string) ($request->get('level') ?? 'error'), $channel)];
    }
}
