<?php

namespace App\Mcp\Tools;

use App\Http\Middleware\McpAuth;
use App\Services\Ops\Health;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('health')]
#[Description('Is MyCS well? ok / warn / fail for each check: build (deployed commit), pending migrations, scheduler heartbeat, the daily cron, the queue (waiting, failed in 24h), mail (sent / failed / stuck, disabled, SMTP set), module actions needing staff, license checks today. The first call when something seems wrong. Same as `mycs:health`.')]
#[IsReadOnly]
#[IsIdempotent]
class HealthTool extends McpTool
{
    protected function permission(): string
    {
        return McpAuth::PERMISSION;
    }

    protected function run(Request $request): array
    {
        return Health::report();
    }
}
