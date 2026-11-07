<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Servers\DirectAdmin\DirectAdmin;
use Tests\TestCase;
use Throwable;

/** The fixes of sweep2 «f-provisioning-ops», beyond the verifier's tests: DirectAdmin's create adopts, or forgets. */
class Sweep2FProvisioningOpsFixesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{string, string}> */
    private array $sent = [];

    /** @param  callable(int): mixed  $create  the answer to the n-th CMD_API_ACCOUNT_USER (0-based) */
    private function directAdminService(callable $create, ?string $configDomain = null): Service
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls, $create, $configDomain) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/CMD_API_SHOW_RESELLER_IPS') {
                return Http::response('list[]=203.0.113.30');
            }
            if ($path === '/CMD_API_ACCOUNT_USER') {
                $this->sent[] = [(string) $request->data()['username'], (string) $request->data()['passwd']];

                return $create($calls++);
            }
            if ($path === '/CMD_API_SHOW_USER_CONFIG' && $configDomain !== null) {
                return Http::response('domain=' . $configDomain . '&package=basic');
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
            'domain' => 'site.example.test']);
        $service->properties()->create(['key' => 'domain', 'name' => 'Domain', 'value' => 'site.example.test']);

        return $service;
    }

    private function attempt(Service $service): void
    {
        try {
            ExtensionHelper::createServer($service->fresh(), whilePending: true);
        } catch (Throwable) {
        }
    }

    public function test_a_retry_adopts_the_account_its_lost_attempt_made_with_the_password_it_was_mailed(): void
    {
        $service = $this->directAdminService(function (int $n) {
            if ($n === 0) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response('error=1&text=Unable to Create&details=That domain already exists');
        }, configDomain: 'site.example.test');

        $this->attempt($service);
        $this->attempt($service);

        $service = $service->fresh();
        [[$first, $password], [$second, $again]] = $this->sent;
        $this->assertSame($first, $second, 'the same account asked for again');
        $this->assertSame($password, $again, 'with the password DirectAdmin mailed');
        $this->assertSame('active', $service->status, 'adopted: the service starts');
        $this->assertSame($first, $service->properties()->where('key', 'directadmin_username')->value('value'));
        $this->assertSame($first, $service->username);
        $this->assertSame($password, $service->password);
        $this->assertNull($service->properties()->where('key', DirectAdmin::PENDING)->value('value'), 'no longer pending');
        $this->assertSame(0, ModuleAction::query()->open()->where('service_id', $service->id)->count(), 'the failed attempt closed');
    }

    public function test_an_account_of_another_domain_under_that_name_is_never_adopted(): void
    {
        $service = $this->directAdminService(function (int $n) {
            if ($n === 0) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response('error=1&text=Unable to Create&details=That user already exists');
        }, configDomain: 'other.example.test');

        $this->attempt($service);
        $this->attempt($service);

        $service = $service->fresh();
        $this->assertSame('pending', $service->status);
        $this->assertNull($service->properties()->where('key', 'directadmin_username')->value('value'));
        $this->assertNull($service->username);
    }

    public function test_a_first_attempt_refused_keeps_nothing_and_the_next_draws_a_new_name(): void
    {
        $service = $this->directAdminService(fn (int $n) => $n === 0
            ? Http::response('error=1&text=Unable to Create&details=No such package')
            : Http::response('error=0&text=User created'));

        $this->attempt($service);
        $this->assertNull($service->fresh()->properties()->where('key', DirectAdmin::PENDING)->value('value'), 'refused: nothing made');
        $this->assertNull($service->fresh()->password, 'nor its password kept');

        $this->attempt($service);

        $service = $service->fresh();
        [[$first], [$second, $password]] = $this->sent;
        $this->assertNotSame($first, $second);
        $this->assertSame('active', $service->status);
        $this->assertSame($second, $service->properties()->where('key', 'directadmin_username')->value('value'));
        $this->assertSame($password, $service->password);
    }

    public function test_an_answer_with_no_error_field_keeps_the_name_for_the_retry(): void
    {
        $service = $this->directAdminService(fn (int $n) => $n === 0 ? Http::response('<html>proxy error</html>') : Http::response('error=0&text=User created'));

        $this->attempt($service);
        $this->assertNotNull($service->fresh()->properties()->where('key', DirectAdmin::PENDING)->value('value'), 'an odd answer: maybe made');
        $this->attempt($service);

        [[$first, $p1], [$second, $p2]] = $this->sent;
        $this->assertSame([$first, $p1], [$second, $p2]);
        $this->assertSame('active', $service->fresh()->status);
    }
}
