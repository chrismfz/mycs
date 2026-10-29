<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\License;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nixpal\Licensing\LocalKey;
use Tests\TestCase;

/**
 * Pre-launch audit, flow 7 (docs/prelaunch-audit.md): the zlic.php contract.
 *
 * AUD7-1: WHMCS's zlic.php reads verify.php's answer with preg_match_all('/<(.*?)>([^<]+)<\/\\1>/i'): a tag with an
 * empty value («<companyname></companyname>», a customer without a company) never matches, so it is neither in the
 * JSON nor in the localkey. Zlic sends every tag, empty ones too: the JSON and the localkey are not byte-for-byte
 * WHMCS's (CLAUDE.md, «Contracts που δεν σπάνε»). The fixtures and the parity tests have no empty tag, and the parity
 * tests run both repositories through the same Zlic, so they cannot see it.
 */
class AuditFlow7Test extends TestCase
{
    use RefreshDatabase;

    private const PSK = 'TestSecret';

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['key_prefix' => ['ZCAgent-', false], 'key_length' => ['16', false], 'secret_key' => [self::PSK, true]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => $product->product->getMorphClass()])->save();
        }
        $user = User::factory()->create();
        $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'EUR', 'price' => 0, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
        // A private customer: no company (most of them).
        $user->client()->primaryContact()->update(['company_name' => null]);
    }

    /** What WHMCS's zlic.php keeps of verify.php's tags (reference/whmcs/zlic.php, the remote branch). */
    private static function asWhmcsParses(array $tags): array
    {
        $xml = '';
        foreach ($tags as $name => $value) {
            $xml .= '<' . $name . '>' . $value . '</' . $name . '>';
        }
        preg_match_all('/<(.*?)>([^<]+)<\/\\1>/i', $xml, $matches);
        $results = [];
        foreach ($matches[1] as $k => $v) {
            $results[$v] = $matches[2][$k];
        }

        return $results;
    }

    private function check(string $key, string $method = 'post'): array
    {
        $request = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7']);
        $response = $method === 'post'
            ? $request->post('/zlic.php', ['license' => $key, 'license_PSK' => self::PSK])
            : $request->get('/zlic.php?' . http_build_query(['license' => $key, 'license_PSK' => self::PSK]));

        return json_decode($response->getContent(), true);
    }

    public function test_aud7_1_an_empty_tag_is_left_out_of_the_answer_as_whmcs_zlic_does(): void
    {
        ExtensionHelper::createServer($this->service);
        $key = License::query()->sole()->license_key;

        foreach (['post', 'get'] as $method) {
            $answer = $this->check($key, $method);
            $this->assertSame('Active', $answer['status']);
            // verify.php's tags as Zlic built them (everything before md5hash), parsed as WHMCS's zlic.php parses them.
            $tags = array_slice($answer, 0, array_search('md5hash', array_keys($answer), true), true);
            $whmcs = self::asWhmcsParses($tags);

            $this->assertArrayNotHasKey('companyname', $whmcs, 'the yardstick: WHMCS drops the empty tag');
            $this->assertArrayNotHasKey('companyname', $answer, "{$method}: WHMCS's zlic.php never sends an empty tag");
            $payload = LocalKey::decode($answer['localkey'], self::PSK);
            $this->assertIsArray($payload);
            $this->assertArrayNotHasKey('companyname', $payload, "{$method}: the localkey is not WHMCS's byte for byte");
        }
    }

    public function test_aud7_1_a_suspended_answer_leaves_the_empty_tag_out_too(): void
    {
        ExtensionHelper::createServer($this->service);
        $key = License::query()->sole()->license_key;
        $this->check($key);
        ExtensionHelper::suspendServer($this->service);

        $answer = $this->check($key);

        $this->assertSame('Suspended', $answer['status']);
        $this->assertArrayNotHasKey('companyname', $answer, "WHMCS's zlic.php never sends an empty tag");
    }
}
