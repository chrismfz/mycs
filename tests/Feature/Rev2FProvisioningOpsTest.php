<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

/** Independent review of the sweep2 «f-provisioning-ops» fixes. */
class Rev2FProvisioningOpsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $sent = [];

    /**
     * REV2-FPO-1 (low). DirectAdmin's adoption compares the domain it was asked for with the one DirectAdmin keeps, byte
     * for byte (lower-cased). DirectAdmin keeps an IDN domain (a Greek .gr one, common at myip/nixpal) in its punycode
     * form, so the account the lost attempt made is never adopted: the paid service stays pending for ever, as before the
     * fix, only with a clearer message.
     */
    public function test_rev2_an_idn_domain_account_made_by_a_lost_attempt_is_adopted(): void
    {
        $unicode = 'παράδειγμα.gr';
        $ascii = (string) idn_to_ascii($unicode);
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls, $ascii) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/CMD_API_SHOW_RESELLER_IPS') {
                return Http::response('list[]=203.0.113.30');
            }
            if ($path === '/CMD_API_ACCOUNT_USER') {
                $this->sent[] = (string) $request->data()['username'];
                if ($calls++ === 0) {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }

                return Http::response('error=1&text=Unable to Create&details=That domain already exists');
            }
            if ($path === '/CMD_API_SHOW_USER_CONFIG') {
                return Http::response('domain=' . $ascii . '&package=basic');
            }

            return Http::response('error=1&text=unexpected', 500);
        });
        $server = Server::query()->create(['name' => 'DA', 'extension' => 'DirectAdmin', 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, ['host' => 'https://da.example.test:2222', 'username' => 'admin', 'password' => 'pw']);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'basic']);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'pending', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1,
            'domain' => $unicode]);
        $service->properties()->create(['key' => 'domain', 'name' => 'Domain', 'value' => $unicode]);

        foreach ([1, 2] as $attempt) {
            try {
                ExtensionHelper::createServer($service->fresh(), whilePending: true);
            } catch (Throwable) {
            }
        }

        $this->assertCount(2, $this->sent);
        $this->assertSame($this->sent[0], $this->sent[1], 'the same account asked for again');
        $this->assertSame('active', $service->fresh()->status, 'the account DirectAdmin made for the IDN domain is adopted');
        $this->assertSame($this->sent[0], $service->fresh()->properties()->where('key', 'directadmin_username')->value('value'));
    }
}
