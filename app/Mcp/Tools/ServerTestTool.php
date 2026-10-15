<?php

namespace App\Mcp\Tools;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Services\Ops\NotFound;
use App\Services\Ops\Scrub;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('server_test')]
#[Description('Test a provisioning server\'s connection with its stored settings, as the admin\'s «Test Connection»: changes nothing on the server; at most one real test a minute per server (else the last result). For "can MyCS reach cPanel/DirectAdmin/… at all?". By id or name; without one, lists the servers.')]
#[IsReadOnly]
#[IsIdempotent]
class ServerTestTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return ['server' => $schema->string()->description('Server id or name (none: the list).')];
    }

    protected function permission(): string
    {
        return 'admin.servers.update';
    }

    protected function run(Request $request): array
    {
        $id = trim((string) $request->get('server'));
        if ($id === '') {
            return ['servers' => Server::query()->orderBy('id')->get()->map(fn (Server $server) => ['id' => $server->id, 'name' => $server->name,
                'module' => $server->extension, 'enabled' => (bool) $server->enabled, 'testable' => ExtensionHelper::hasFunction($server, 'testConfig')])->all()];
        }
        $server = (ctype_digit($id) ? Server::query()->find((int) $id) : Server::query()->where('name', $id)->first())
            ?? throw new NotFound("No server {$id}.");
        if (!ExtensionHelper::hasFunction($server, 'testConfig')) {
            return ['server' => $server->name, 'module' => $server->extension, 'ok' => null, 'detail' => 'This module has no connection test.'];
        }
        // One real login a minute per server: a looping agent must not get MyCS locked out (cPHulk, fail2ban: review R9).
        $cached = Cache::get($key = 'mcp:server-test:' . $server->id);
        if (is_array($cached)) {
            return $cached + ['cached' => true, 'note' => 'The result of the last test, less than a minute ago.'];
        }
        $started = hrtime(true);
        // Modules answer true, or the reason as text (as the admin's Test Connection reads it).
        $result = ExtensionHelper::testConfig($server, ExtensionHelper::settingsToArray($server->settings));
        $ok = $result === true;
        $answer = ['server' => $server->name, 'module' => $server->extension, 'ok' => $ok,
            'detail' => $ok ? 'Connected.' : Scrub::text((string) $result), 'ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'tested_at' => now()->toIso8601String()];
        Cache::put($key, $answer, now()->addMinute());

        return $answer;
    }
}
