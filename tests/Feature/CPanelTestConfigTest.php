<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * cPanel's «Test Connection» (also the MCP's server_test): true only when WHM says yes (metadata.result 1, which it
 * answers with HTTP 200 also when it refuses), otherwise why not as text, never an exception.
 */
class CPanelTestConfigTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://whm.test:2087/json-api';

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
    }

    private function test(): bool|string
    {
        return ExtensionHelper::testConfig($this->server, ExtensionHelper::settingsToArray($this->server->fresh()->settings));
    }

    public function test_true_when_whm_says_yes(): void
    {
        Http::fake([self::WHM . '/version*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['version' => '11.138.0.5']])]);

        $this->assertTrue($this->test());
    }

    public function test_a_refusal_with_http_200_is_not_a_success(): void
    {
        Http::fake([self::WHM . '/version*' => Http::response(['metadata' => ['result' => 0, 'reason' => 'Access denied']])]);

        $this->assertSame('cPanel version failed: Access denied', $this->test());
    }

    public function test_an_http_error_says_its_status_with_or_without_a_message(): void
    {
        Http::fake([self::WHM . '/version*' => Http::sequence()
            ->push(['statusmsg' => 'Token denied'], 401)
            ->push('', 403)]);

        $this->assertSame('WHM answered HTTP 401: Token denied', $this->test());
        $this->assertSame('WHM answered HTTP 403', $this->test(), 'no status message: still text, never a TypeError');
    }

    public function test_an_unreachable_server_is_text_too(): void
    {
        Http::fake([self::WHM . '/version*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect')]);

        $this->assertSame('Could not reach WHM: cURL error 7: Failed to connect', $this->test());
    }
}
