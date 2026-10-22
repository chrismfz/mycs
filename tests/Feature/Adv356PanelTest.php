<?php

namespace Tests\Feature;

use App\Support\PanelConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Servers\CPanel\CPanel;
use MyCS\Extensions\Servers\DirectAdmin\DirectAdmin;
use Tests\TestCase;

/**
 * Adversarial review of #356: a panel's answer must not move the request (and the passwords in its body) to plain
 * HTTP or to another host. Guzzle follows redirects by default; on a 307/308 it keeps the method and the body, and
 * the CURLOPT_RESOLVE pin and the TLS check only cover the configured host.
 */
class Adv356PanelTest extends TestCase
{
    use RefreshDatabase;

    private function fakeRedirect(string $from, string $to, int $status = 307): void
    {
        Http::preventStrayRequests();
        Http::fake([
            $from => Http::response('', $status, ['Location' => $to]),
            '*' => Http::response('{"metadata":{"result":1}}'),
        ]);
    }

    private function assertNothingLeftThePanel(string $panel): void
    {
        $elsewhere = Http::recorded(fn (Request $r) => !str_starts_with($r->url(), $panel));
        $this->assertCount(0, $elsewhere, 'sent elsewhere: ' . $elsewhere->map(fn ($p) => $p[0]->method() . ' ' . $p[0]->url() . ' body=' . $p[0]->body())->implode(', '));
    }

    private function panelPost(object $module, string $endpoint, array $data): void
    {
        try {
            (fn () => $this->request($endpoint, 'post', $data))->call($module);
        } catch (\Throwable) {
            // refusing the redirect is the fix
        }
    }

    public function test_a_307_from_whm_does_not_resend_the_password_over_plain_http(): void
    {
        $this->fakeRedirect('https://whm.example.test:2087/*', 'http://evil.example.test/steal');
        $cpanel = new CPanel(['host' => 'https://whm.example.test:2087', 'ip' => '203.0.113.10', 'username' => 'root', 'apikey' => 'secret-key']);

        $this->panelPost($cpanel, '/createacct', ['api.version' => 1, 'username' => 'newuser', 'password' => 'Acc0untPassw0rd']);

        $this->assertNothingLeftThePanel('https://whm.example.test:2087/');
    }

    public function test_a_308_from_directadmin_does_not_send_the_body_to_another_host(): void
    {
        // Another host: the server IP pin does not apply to it, and with a self-signed panel neither does the TLS check.
        $this->fakeRedirect('https://da.example.test:2222/*', 'https://evil.example.test/steal', 308);
        $da = new DirectAdmin(['host' => 'https://da.example.test:2222', 'ip' => '203.0.113.20', 'self_signed' => true, 'username' => 'admin', 'password' => 'admin-pass']);

        $this->panelPost($da, '/CMD_API_ACCOUNT_USER', ['action' => 'create', 'username' => 'u1', 'passwd' => 'Acc0untPassw0rd', 'passwd2' => 'Acc0untPassw0rd']);

        $this->assertNothingLeftThePanel('https://da.example.test:2222/');
    }

    public function test_the_panel_connection_does_not_follow_redirects(): void
    {
        $options = PanelConnection::http('https://whm.example.test:2087', '203.0.113.10', true)->getOptions();

        $this->assertFalse($options['allow_redirects'] ?? true);
    }
}
