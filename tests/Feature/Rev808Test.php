<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\License;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Independent review of #808. Tests that FAIL on ccf7b742 and pass on main (3b746930).
 *
 * REV-808-1 (fix 1, Licenses::setStatus): «Expired is final» was also what kept a license staff ended by hand («Edit
 * license» → Expired, WHMCS's way to revoke one; the importer brings WHMCS's Expired rows as they are) ended while its
 * service goes on. The way back was meant for staff's «Create»/«Unsuspend» on a service they brought back, but
 * setStatus() cannot tell staff's command from the payment's own UnsuspendJob, which always runs with the service
 * already Active: a late payment after a suspension for non-payment now makes the revoked license Active again, with
 * nobody asked.
 */
class Rev808Test extends TestCase
{
    use RefreshDatabase;

    private const IP = '198.51.100.7';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
    }

    private function licensed(): Service
    {
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id,
            'plan_id' => $product->plan->id, 'product_id' => $product->product->id, 'status' => 'active',
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
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

    /**
     * REV-808-1. A license staff set Expired by hand on a running service; the service is later suspended for
     * non-payment by the cron and paid: the payment's UnsuspendJob (no staff command) must leave it Expired, as on main.
     */
    public function test_a_license_staff_expired_by_hand_is_not_revived_by_a_payments_unsuspension(): void
    {
        $service = $this->licensed();
        ExtensionHelper::createServer($service);
        $license = License::query()->where('service_id', $service->id)->sole();
        $this->assertSame('Active', $this->check($license->license_key)['status']);

        // Staff: «Edit license» → Expired (EditService's editLicense action writes exactly this); the service goes on.
        $license->update(['status' => License::STATUS_EXPIRED]);
        $this->assertSame('Expired', $this->check($license->license_key)['status']);

        // The cron suspends it for non-payment: its job runs with the service already suspended.
        $service->fresh()->update(['status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => now()]);
        SuspendJob::dispatchSync($service->fresh(), false);
        $this->assertSame(License::STATUS_EXPIRED, $license->fresh()->status, 'a suspension leaves an Expired license Expired (as on main)');

        // The customer pays: the service is saved active, then the payment's UnsuspendJob runs (RenewServiceService::activate).
        $service->fresh()->update(['status' => Service::STATUS_ACTIVE, 'dunning_suspended_at' => null]);
        UnsuspendJob::dispatchSync($service->fresh());

        $this->assertSame(License::STATUS_EXPIRED, $license->fresh()->status,
            'a late payment brought back the license staff had ended by hand: nobody ran «Create» or «Unsuspend»');
        $this->assertSame('Expired', $this->check($license->license_key)['status'], 'zlic.php answers Active again for a revoked license');
    }
}
