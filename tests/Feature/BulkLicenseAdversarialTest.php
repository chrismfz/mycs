<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\License;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Licensing\BulkLicenseIps;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #821 (bulk license IPs in the app): written without the author's context.
 */
class BulkLicenseAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
    }

    private function licensed(bool $bulk, ?int $clientId = null): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
        foreach (['key_prefix' => 'ZCAgent-', 'key_length' => '16', 'secret_key' => 'TestSecret', 'allow_reissue' => '1', 'bulk' => $bulk ? '1' : '0'] as $key => $value) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $key === 'secret_key', 'type' => in_array($key, ['allow_reissue', 'bulk'], true) ? 'boolean' : 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => (new Product)->getMorphClass()])->save();
        }
        $service = Service::factory()->create(['client_id' => $clientId ?? User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
        ExtensionHelper::createServer($service);

        return $service->load('license', 'product.settings');
    }

    private function check(string $ip, string $key): array
    {
        return json_decode($this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/zlic.php', ['license' => $key, 'license_PSK' => 'TestSecret'])->getContent(), true);
    }

    /**
     * ADV-821-1 (medium): a terminated bulk service keeps its IPs «active» for ever. Another client's bulk license is then
     * refused that IP («already in use by another license. Please contact support.») although the old license answers
     * Expired and nobody uses it: an IP (a re-sold VPS address) is locked out until staff find and remove it by hand, and
     * staff's own «Add IPs» shows them the customer's message, not the holder. §6.3: the IPs close when the service ends.
     */
    public function test_adv_821_1_a_terminated_bulk_license_does_not_keep_its_ips_from_another_client(): void
    {
        $old = $this->licensed(bulk: true);
        BulkLicenseIps::add($old->license, '198.51.100.7');
        ExtensionHelper::terminateServer($old);
        $old->update(['status' => 'cancelled']);
        $this->assertSame('Expired', $this->check('198.51.100.7', $old->license->license_key)['status']);

        $new = $this->licensed(bulk: true);
        try {
            $added = BulkLicenseIps::add($new->license, '198.51.100.7')['added'];
        } catch (DisplayException $e) {
            $this->fail('refused an IP of an ended license: ' . $e->getMessage());
        }
        $this->assertSame(['198.51.100.7'], $added);
        $this->assertSame('Active', $this->check('198.51.100.7', $new->license->license_key)['status']);
    }

    /**
     * ADV-821-2 (low/medium): a bulk license whose product stops being bulk (the checkbox unticked, or the service moved to
     * a single product) still has its list rows, but zlic now takes the single path: valid_ip was never set, so the first
     * caller with the shared PSK (every agent sends the same one) binds it and is answered Active, from an IP that was never
     * on the list. «Never bound by a check» holds only while the setting does; the list rows also keep blocking those IPs.
     */
    public function test_adv_821_2_a_license_with_a_list_is_not_bound_by_a_stranger_when_its_product_stops_being_bulk(): void
    {
        $service = $this->licensed(bulk: true);
        BulkLicenseIps::add($service->license, '198.51.100.7');

        Setting::query()->where('settingable_id', $service->product_id)->where('key', 'bulk')->update(['value' => '0']);

        $answer = $this->check('203.0.113.9', $service->license->license_key);
        $this->assertNotSame('Active', $answer['status'], 'an IP never on the list is answered Active');
        $this->assertNull($service->license->fresh()->valid_ip, 'bound to a stranger');
    }

    /**
     * ADV-821-3 (low): «Edit license» stays on a bulk license with its «Valid IPs» field («Empty: the next check binds it»),
     * while the page hides Valid IPs for a bulk one. An IP staff type there is saved and ignored by zlic: the server is
     * refused while staff believe it is allowed. The field (or the action) should not be offered on a bulk license.
     */
    public function test_adv_821_3_edit_license_does_not_offer_valid_ips_on_a_bulk_license(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $service = $this->licensed(bulk: true);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('editLicense', ['status' => License::STATUS_ACTIVE, 'valid_ip' => '198.51.100.7']);

        $saved = $service->license->fresh()->valid_ip;
        $answer = $this->check('198.51.100.7', $service->license->license_key);
        $this->assertFalse($saved === '198.51.100.7' && $answer['status'] !== 'Active',
            'staff saved 198.51.100.7 as a Valid IP of the bulk license and zlic refuses it: ' . ($answer['message'] ?? ''));
    }
}
