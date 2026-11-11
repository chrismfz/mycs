<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\License;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Throwable;

/**
 * Sweep 10, provisioning (adversarial verifier). Tests that FAIL on f9f145bb:
 * - S10-PROVISIONING-1: a licensing service staff bring back after it ended keeps its license Expired: «Create server»
 *   and «Unsuspend server» answer as done, zlic.php keeps answering Expired.
 * - S10-PROVISIONING-2: DirectAdmin suspends and deletes an account by its name alone, never asking DirectAdmin whose
 *   it is (cPanel's ownAccount, mailcow's ownedAdmin).
 */
class Sweep10ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '198.51.100.7';

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

    // ------------------------------------------------------------------------------------------ S10-PROVISIONING-1

    private function licensed(): Service
    {
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $service = $this->serviceOn($server);
        foreach (['key_prefix' => ['ZCAgent-', false], 'key_length' => ['16', false], 'secret_key' => ['TestSecretValue', true]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => 'string',
                'settingable_id' => $service->product_id, 'settingable_type' => $service->product->getMorphClass()])->save();
        }

        return $service;
    }

    private function check(string $key): array
    {
        return (array) json_decode($this->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->post('/zlic.php', ['license' => $key, 'license_PSK' => 'TestSecretValue'])->getContent(), true);
    }

    /** The service ended (terminate ran, as the cron's TerminateJob does), then staff set it Active again on its page. */
    private function endedAndBroughtBack(): array
    {
        $service = $this->licensed();
        ExtensionHelper::createServer($service);
        $key = License::query()->where('service_id', $service->id)->sole()->license_key;
        $this->assertSame('Active', $this->check($key)['status']);

        ExtensionHelper::terminateServer($service->fresh());
        $service->fresh()->update(['status' => Service::STATUS_TERMINATED]);
        $this->assertSame('Expired', $this->check($key)['status']);

        $service->fresh()->update(['status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addMonth()]);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);

        return [$service->fresh(), $key];
    }

    /** «Create server» on the service that runs again: answers as done (the key), the license stays Expired. */
    public function test_create_on_a_service_brought_back_makes_its_license_answer_again(): void
    {
        [$service, $key] = $this->endedAndBroughtBack();

        try {
            ExtensionHelper::createServer($service);
        } catch (Throwable $e) {
            // A refusal that sends staff to «Edit license» would be an answer too: then nothing claims it was done.
            $this->assertStringContainsStringIgnoringCase('license', $e->getMessage());

            return;
        }

        $this->assertSame('Active', $this->check($key)['status'], '«Create server» said done on an Active service, zlic.php still answers Expired');
    }

    /** «Unsuspend server» on it: the same, «Expired is final» although the service is Active. */
    public function test_unsuspend_on_a_service_brought_back_makes_its_license_answer_again(): void
    {
        [$service, $key] = $this->endedAndBroughtBack();

        try {
            ExtensionHelper::unsuspendServer($service);
        } catch (Throwable $e) {
            $this->assertStringContainsStringIgnoringCase('license', $e->getMessage());

            return;
        }

        $this->assertSame('Active', $this->check($key)['status'], '«Unsuspend server» said done on an Active service, zlic.php still answers Expired');
    }

    // ------------------------------------------------------------------------------------------ S10-PROVISIONING-2

    /** An imported DirectAdmin service: username «john» kept from WHMCS, domain a-example.gr; on the server john holds $theirs. */
    private function directAdminJohn(string $theirs): Service
    {
        $server = Server::query()->create(['name' => 'DA', 'extension' => 'DirectAdmin', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://da.test:2222', 'username' => 'admin', 'password' => 'secret'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        Http::fake([
            'https://da.test:2222/CMD_API_SHOW_USER_CONFIG*' => Http::response('username=john&domain=' . $theirs . '&suspended=no'),
            'https://da.test:2222/CMD_API_SELECT_USERS*' => Http::response('error=0&text=OK'),
            'https://da.test:2222/*' => Http::response('error=1&text=unexpected'),
        ]);
        $service = $this->serviceOn($server, ['username' => 'john', 'domain' => 'a-example.gr']);
        $service->properties()->create(['key' => 'domain', 'name' => 'Domain', 'value' => 'a-example.gr']);

        return $service->fresh();
    }

    public function test_directadmin_never_deletes_an_account_that_holds_another_domain(): void
    {
        $service = $this->directAdminJohn('b-example.gr');

        try {
            ExtensionHelper::terminateServer($service);
        } catch (Throwable) {
            // a refusal left to staff is the right answer (cPanel's ownAccount)
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'CMD_API_SELECT_USERS') && ($request['delete'] ?? null) === 'yes');
    }

    public function test_directadmin_never_suspends_an_account_that_holds_another_domain(): void
    {
        $service = $this->directAdminJohn('b-example.gr');

        try {
            ExtensionHelper::suspendServer($service);
        } catch (Throwable) {
            // a refusal left to staff is the right answer
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'CMD_API_SELECT_USERS') && ($request['suspend'] ?? null) === 'suspend');
    }
}
