<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Support\PanelConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use MyCS\Extensions\Servers\CPanel\CPanel;
use MyCS\Extensions\Servers\DirectAdmin\DirectAdmin;
use RuntimeException;
use Tests\TestCase;

/**
 * A panel holding a server's root key is reached over HTTPS only, at its server's IP when set; a self-signed certificate
 * only with that IP, never a certificate pin (security audit PROV-3; owner's decision 2026-10-23).
 */
class PanelConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function refused(string $base, ?string $ip, bool $selfSigned, string $why): void
    {
        try {
            PanelConnection::http($base, $ip, $selfSigned);
            $this->fail('allowed: ' . $base);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($why, $e->getMessage());
        }
    }

    public function test_only_https(): void
    {
        $this->refused('http://whm.example.test:2087', null, false, 'https://');
        $this->refused('whm.example.test:2087', null, false, 'https://');
        $this->refused('ftp://whm.example.test', null, false, 'https://');
    }

    public function test_a_valid_certificate_without_an_ip_is_as_before(): void
    {
        $options = PanelConnection::http('https://whm.example.test:2087', null, false)->getOptions();

        $this->assertArrayNotHasKey('curl', $options);
        $this->assertNotSame(false, $options['verify'] ?? true);
    }

    public function test_the_server_ip_is_where_the_connection_goes(): void
    {
        $options = PanelConnection::http('https://whm.example.test:2087', '203.0.113.10', false)->getOptions();

        $this->assertSame(['whm.example.test:2087:203.0.113.10'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertNotSame(false, $options['verify'] ?? true, 'the certificate is still checked');

        $v6 = PanelConnection::http('https://da.example.test', '2001:db8::5', false)->getOptions();
        $this->assertSame(['da.example.test:443:[2001:db8::5]'], $v6['curl'][CURLOPT_RESOLVE]);
    }

    public function test_a_self_signed_certificate_needs_the_ip(): void
    {
        $this->refused('https://whm.example.test:2087', null, true, 'server IP');
        $this->refused('https://whm.example.test:2087', 'not-an-ip', true, 'not an IP');

        $options = PanelConnection::http('https://whm.example.test:2087', '203.0.113.10', true)->getOptions();
        $this->assertFalse($options['verify']);
        $this->assertSame(['whm.example.test:2087:203.0.113.10'], $options['curl'][CURLOPT_RESOLVE]);

        // An address given as an IP is that IP.
        $literal = PanelConnection::http('https://203.0.113.10:2087', null, true)->getOptions();
        $this->assertFalse($literal['verify']);
        $this->assertArrayNotHasKey('curl', $literal);
    }

    public function test_the_server_forms_accept_only_https(): void
    {
        foreach ([new CPanel([]), new DirectAdmin([])] as $module) {
            $host = collect($module->getConfig())->firstWhere('name', 'host');
            $this->assertTrue(Validator::make(['host' => 'http://panel.example.test'], ['host' => $host['validation']])->fails(), $module::class);
            $this->assertFalse(Validator::make(['host' => 'https://panel.example.test:2087'], ['host' => $host['validation']])->fails(), $module::class);
            $names = collect($module->getConfig())->pluck('name');
            $this->assertContains('ip', $names);
            $this->assertContains('self_signed', $names);
        }
    }

    public function test_a_cpanel_server_still_on_http_sends_nothing(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'http://whm.example.test:2087', 'username' => 'root', 'apikey' => 'secret-key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $cpanel = new CPanel(['host' => 'http://whm.example.test:2087', 'username' => 'root', 'apikey' => 'secret-key']);

        try {
            $cpanel->testConfig();
        } catch (\Throwable) {
            // refused, one way or the other
        }

        Http::assertNothingSent();
    }
}
