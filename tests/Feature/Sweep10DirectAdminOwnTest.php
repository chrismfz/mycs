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
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * The fix of S10-PROVISIONING-2 leaves the normal paths as they were: DirectAdmin still suspends, unsuspends and removes
 * the service's own account, however its domain is written; a lookup that fails sends nothing; an account DirectAdmin
 * does not know, or a service with no domain, gets DirectAdmin's own answer.
 */
class Sweep10DirectAdminOwnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function serviceOn(Server $server, array $attributes = []): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);

        return Service::factory()->create(array_merge(['client_id' => User::factory()->create()->client()->id,
            'plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'status' => 'active',
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()], $attributes));
    }

    // ------------------------------------------------------------------------------------------ S10-PROVISIONING-2

    /** @param  mixed  $config  DirectAdmin's answer to «whose account is john» */
    private function directAdminJohn(mixed $config, ?string $ours = 'a-example.gr'): Service
    {
        $server = Server::query()->create(['name' => 'DA', 'extension' => 'DirectAdmin', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://da.test:2222', 'username' => 'admin', 'password' => 'secret'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        Http::fake([
            'https://da.test:2222/CMD_API_SHOW_USER_CONFIG*' => $config,
            'https://da.test:2222/CMD_API_SELECT_USERS*' => Http::response('error=0&text=OK'),
        ]);

        return $this->serviceOn($server, ['username' => 'john', 'domain' => $ours])->fresh();
    }

    private function sent(string $field, string $value): void
    {
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'CMD_API_SELECT_USERS')
            && ($request[$field] ?? null) === $value && $request['select0'] === 'john');
    }

    public function test_directadmin_still_acts_on_the_services_own_account(): void
    {
        $service = $this->directAdminJohn(Http::response('username=john&domain=a-example.gr&suspended=no'));

        ExtensionHelper::suspendServer($service);
        $this->sent('suspend', 'suspend');
        ExtensionHelper::unsuspendServer($service->fresh());
        $this->sent('suspend', 'unsuspend');
        ExtensionHelper::terminateServer($service->fresh());
        $this->sent('delete', 'yes');
        $this->assertNull($service->fresh()->username);
    }

    /** @return array<string, array{string, string}> */
    public static function sameDomains(): array
    {
        return ['case' => ['A-Example.GR', 'a-example.gr'], 'www and a dot at its end' => ['www.a-example.gr.', 'a-example.gr'],
            'www on ours' => ['a-example.gr', 'WWW.a-example.gr'], 'IDN, punycode there' => ['xn--hxajbheg2az3al.gr', 'παράδειγμα.gr'],
            'IDN, punycode here' => ['παράδειγμα.gr', 'xn--hxajbheg2az3al.gr']];
    }

    #[DataProvider('sameDomains')]
    public function test_the_same_domain_written_another_way_is_the_services_own(string $theirs, string $ours): void
    {
        $service = $this->directAdminJohn(Http::response('username=john&domain=' . rawurlencode($theirs) . '&suspended=no'), $ours);

        ExtensionHelper::suspendServer($service);

        $this->sent('suspend', 'suspend');
    }

    public function test_an_unsuspension_of_a_strangers_account_is_refused_too(): void
    {
        $service = $this->directAdminJohn(Http::response('username=john&domain=b-example.gr&suspended=yes'));

        try {
            ExtensionHelper::unsuspendServer($service);
            $this->fail('unsuspended an account that holds another domain');
        } catch (Throwable $e) {
            $this->assertStringContainsString('b-example.gr', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'CMD_API_SELECT_USERS'));
    }

    public function test_a_lookup_that_fails_sends_nothing(): void
    {
        $service = $this->directAdminJohn(Http::sequence()->push('down', 500)->pushResponse(fn () => throw new ConnectionException('timed out')));

        foreach (['refused', 'unreachable'] as $how) {
            try {
                ExtensionHelper::terminateServer($service->fresh());
                $this->fail('acted on an account it could not look up: ' . $how);
            } catch (Throwable $e) {
                $this->assertNotInstanceOf(AssertionFailedError::class, $e); // staff's task, as any refusal
            }
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'CMD_API_SELECT_USERS'));
        $this->assertSame('john', $service->fresh()->username);
    }

    public function test_an_account_directadmin_does_not_know_gets_directadmins_own_answer(): void
    {
        $service = $this->directAdminJohn(Http::response('error=1&text=Unable to show user&details=That user does not exist'));

        ExtensionHelper::suspendServer($service);

        $this->sent('suspend', 'suspend');
    }

    public function test_a_service_with_no_domain_is_not_refused(): void
    {
        $service = $this->directAdminJohn(Http::response('username=john&domain=b-example.gr&suspended=no'), null);

        ExtensionHelper::suspendServer($service);

        $this->sent('suspend', 'suspend');
    }
}
