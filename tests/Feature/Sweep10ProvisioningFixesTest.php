<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\License;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The fix of S10-PROVISIONING-1 leaves the normal paths as they were: only «Create» on a service that is Active again
 * brings an Expired license back («Unsuspend» does not: REV-808-1), with its key and bindings; a Suspended one comes
 * back as before, an Active one is untouched, an ended service's stays Expired.
 */
class Sweep10ProvisioningFixesTest extends TestCase
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

    private function check(string $key, string $ip = self::IP): array
    {
        return (array) json_decode($this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/zlic.php', ['license' => $key, 'license_PSK' => 'TestSecretValue'])->getContent(), true);
    }

    public function test_the_license_brought_back_is_the_same_one_key_bindings_and_reissues(): void
    {
        $service = $this->licensed();
        ExtensionHelper::createServer($service);
        $before = License::query()->where('service_id', $service->id)->sole();
        $this->assertSame('Active', $this->check($before->license_key)['status']); // binds it to IP
        $before->refresh();

        ExtensionHelper::terminateServer($service->fresh());
        $service->fresh()->update(['status' => Service::STATUS_TERMINATED]);
        $service->fresh()->update(['status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addMonth()]);
        ExtensionHelper::createServer($service->fresh());

        $after = License::query()->where('service_id', $service->id)->sole();
        $this->assertSame(License::STATUS_ACTIVE, $after->status);
        $this->assertSame([$before->id, $before->license_key, $before->valid_ip, $before->valid_domain, $before->valid_directory, $before->reissues],
            [$after->id, $after->license_key, $after->valid_ip, $after->valid_domain, $after->valid_directory, $after->reissues]);
        $this->assertSame(self::IP, $after->valid_ip);
        $this->assertSame('Invalid', $this->check($after->license_key, '203.0.113.9')['status'], 'the binding is the one it had');
    }

    public function test_a_suspended_license_comes_back_and_an_active_one_stays_as_before(): void
    {
        $service = $this->licensed();
        ExtensionHelper::createServer($service);
        $license = License::query()->where('service_id', $service->id)->sole();
        $this->check($license->license_key);
        $bound = $license->fresh()->getAttributes();

        ExtensionHelper::createServer($service->fresh());
        ExtensionHelper::unsuspendServer($service->fresh());
        $this->assertEquals($bound, $license->fresh()->getAttributes(), 'an Active license is left exactly as it was');

        ExtensionHelper::suspendServer($service->fresh());
        $this->assertSame('Suspended', $this->check($license->license_key)['status']);
        ExtensionHelper::unsuspendServer($service->fresh());
        $this->assertSame('Active', $this->check($license->license_key)['status']);

        ExtensionHelper::suspendServer($service->fresh());
        ExtensionHelper::createServer($service->fresh());
        $this->assertSame('Active', $this->check($license->license_key)['status']);
    }

    public function test_an_expired_license_of_a_service_that_did_not_come_back_stays_expired(): void
    {
        foreach ([Service::STATUS_TERMINATED, Service::STATUS_CANCELLED, Service::STATUS_SUSPENDED] as $status) {
            $service = $this->licensed();
            ExtensionHelper::createServer($service);
            $license = License::query()->where('service_id', $service->id)->sole();
            ExtensionHelper::terminateServer($service->fresh());
            $service->fresh()->update(['status' => $status]);

            ExtensionHelper::createServer($service->fresh());
            ExtensionHelper::unsuspendServer($service->fresh());

            $this->assertSame(License::STATUS_EXPIRED, $license->fresh()->status, $status);
            Server::query()->whereKey($service->product->server_id)->delete();
        }
    }
}
