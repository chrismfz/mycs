<?php

namespace App\Mcp\Tools;

use App\Models\License;
use App\Services\Ops\ZlicProbe;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('zlic_probe')]
#[Description('What zlic.php would answer a license\'s own client calling from an address, WITHOUT writing anything (no binding, no last access, no log row): its status or message, whether it would bind, what it would log, the bound IPs. For "why does this customer\'s software say invalid?". The PSK, md5hash and localkey never leave. Same as `mycs:zlic`.')]
#[IsReadOnly]
#[IsIdempotent]
class ZlicTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('The full license key.')->required(),
            'ip' => $schema->string()->description('The caller\'s address (default: its first bound IP).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.services.viewAny';
    }

    protected function run(Request $request): array
    {
        $key = trim((string) $request->get('key'));
        $ip = trim((string) $request->get('ip'));
        if ($ip === '') {
            $ip = trim(explode(',', (string) License::query()->where('license_key', $key)->value('valid_ip'))[0]) ?: '192.0.2.1';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('ip is not an IP address.');
        }

        return ['ip' => $ip] + ZlicProbe::answer($key, $ip);
    }
}
