<?php

namespace App\Mcp\Tools;

use App\Services\Ops\Scrub;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('failed_jobs')]
#[Description('Queue jobs that failed for good, newest first: the job, when, and the head of the exception (secrets masked). For "a mail / a provisioning job / a payment step did not happen". The payloads (encrypted) never leave.')]
#[IsReadOnly]
#[IsIdempotent]
class FailedJobsTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->description('How many (default 20, at most 100).')];
    }

    protected function permission(): string
    {
        return 'admin.failed_jobs.viewAny';
    }

    protected function run(Request $request): array
    {
        $rows = DB::table('failed_jobs')->orderByDesc('id')->limit(self::limit($request, 20, 100))->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at']);

        return [
            'total' => DB::table('failed_jobs')->count(),
            'failures' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'uuid' => $row->uuid,
                'job' => json_decode((string) $row->payload, true)['displayName'] ?? null,
                'queue' => $row->queue,
                'failed_at' => $row->failed_at,
                'exception' => Scrub::text(mb_substr((string) $row->exception, 0, 1500)),
            ])->all(),
        ];
    }
}
