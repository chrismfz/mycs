<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\License;
use App\Models\LicenseIp;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Licensing\BulkLicenseIps;
use App\Services\Licensing\Licenses;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A bulk license (docs/zonecloud-bulk-license.md, PR 2): one key for a list of IPs staff keep, each change dated; zlic
 * answers a listed IP and never binds one; no reissue; an IP is on one bulk license at a time.
 */
class BulkLicenseTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $this->service = $this->licensed(bulk: true);
        ExtensionHelper::createServer($this->service);
        $this->service->load('license', 'product.settings');
    }

    /** A service of a new licensing product (bulk or single) of a new client, or of $client's. */
    private function licensed(bool $bulk, ?int $clientId = null): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
        foreach (['key_prefix' => 'ZCAgent-', 'key_length' => '16', 'secret_key' => 'TestSecret', 'allow_reissue' => '1', 'bulk' => $bulk ? '1' : '0'] as $key => $value) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $key === 'secret_key', 'type' => in_array($key, ['allow_reissue', 'bulk'], true) ? 'boolean' : 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => (new Product)->getMorphClass()])->save();
        }

        return Service::factory()->create(['client_id' => $clientId ?? User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
    }

    private function check(string $ip, ?string $key = null): array
    {
        return json_decode($this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/zlic.php', ['license' => $key ?? $this->service->license->license_key, 'license_PSK' => 'TestSecret'])->getContent(), true);
    }

    public function test_a_listed_ip_is_answered_alone_and_its_good_check_is_recorded(): void
    {
        Carbon::setTestNow('2026-11-12 10:00:00');
        BulkLicenseIps::add($this->service->license, "198.51.100.7 web1\n198.51.100.8 web2");

        $answer = $this->check('198.51.100.7');

        $this->assertSame('Active', $answer['status']);
        $this->assertSame('198.51.100.7', $answer['validip']);
        $this->assertArrayHasKey('localkey', $answer);
        $this->assertSame('2026-11-12 10:00:00', LicenseIp::query()->where('ip', '198.51.100.7')->sole()->last_ok_at?->toDateTimeString());
        $this->assertNull(LicenseIp::query()->where('ip', '198.51.100.8')->sole()->last_ok_at, 'the other server never checked');
        $this->assertSame('Active', $this->check('198.51.100.8')['status']);
    }

    public function test_an_empty_list_binds_nobody_and_an_ip_off_it_is_refused(): void
    {
        $this->assertSame('IP Address Invalid', $this->check('203.0.113.9')['message'] ?? null);
        $this->assertNull($this->service->license->fresh()->valid_ip, 'never bound');

        BulkLicenseIps::add($this->service->license, '198.51.100.7');
        $this->assertSame('IP Address Invalid', $this->check('203.0.113.9')['message'] ?? null);
        $this->assertNull($this->service->license->fresh()->valid_ip);
    }

    public function test_a_removed_ip_is_refused_and_put_back_as_a_new_row(): void
    {
        Carbon::setTestNow('2026-11-01 09:00:00');
        BulkLicenseIps::add($this->service->license, '198.51.100.7');
        $first = LicenseIp::query()->sole();

        Carbon::setTestNow('2026-11-05 12:00:00');
        $this->assertSame(1, BulkLicenseIps::remove($this->service->license, [$first->id]));
        $this->assertSame('IP Address Invalid', $this->check('198.51.100.7')['message'] ?? null);
        $this->assertSame(0, BulkLicenseIps::remove($this->service->license, [$first->id]), 'already off');

        Carbon::setTestNow('2026-11-09 08:00:00');
        BulkLicenseIps::add($this->service->license, '198.51.100.7');
        $this->assertSame('Active', $this->check('198.51.100.7')['status']);

        $rows = LicenseIp::query()->orderBy('id')->get();
        $this->assertSame([['2026-11-01 09:00:00', '2026-11-05 12:00:00'], ['2026-11-09 08:00:00', null]],
            $rows->map(fn ($row) => [$row->added_at->toDateTimeString(), $row->removed_at?->toDateTimeString()])->all());
    }

    public function test_addresses_are_stored_as_one_spelling_with_their_names_and_kept_when_already_there(): void
    {
        $result = BulkLicenseIps::add($this->service->license, "2001:0DB8::0001  web 6\n198.51.100.7, 198.51.100.7 again ;198.51.100.9");

        $this->assertSame(['2001:db8::1', '198.51.100.7', '198.51.100.9'], $result['added']);
        $this->assertSame(['2001:db8::1' => 'web 6', '198.51.100.7' => 'again', '198.51.100.9' => null],
            LicenseIp::query()->orderBy('id')->pluck('label', 'ip')->all());
        $this->assertSame('Active', $this->check('2001:db8:0:0::1')['status']);

        $again = BulkLicenseIps::add($this->service->license, "198.51.100.7\n198.51.100.10");
        $this->assertSame(['198.51.100.10'], $again['added']);
        $this->assertSame(['198.51.100.7'], $again['kept']);
        $this->assertSame(4, LicenseIp::query()->count());
    }

    public function test_one_bad_entry_adds_nothing(): void
    {
        foreach (["198.51.100.7\nnot-an-ip", "198.51.100.7\n198.51.100.300", '   ', "198.51.100.7\n10.0.0.0/8"] as $text) {
            try {
                BulkLicenseIps::add($this->service->license, $text);
                $this->fail("accepted «{$text}»");
            } catch (DisplayException) {
                $this->assertSame(0, LicenseIp::query()->count(), $text);
            }
        }
    }

    public function test_an_ip_on_another_bulk_license_is_refused_and_one_on_the_same_clients_single_is_a_warning(): void
    {
        $other = $this->licensed(bulk: true);
        ExtensionHelper::createServer($other);
        BulkLicenseIps::add($other->load('license')->license, '198.51.100.7');

        try {
            BulkLicenseIps::add($this->service->license, '198.51.100.7');
            $this->fail('one IP on two clients\' bulk licenses');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('contact support', $e->getMessage());
            $this->assertStringNotContainsString((string) $other->id, $e->getMessage(), 'nothing of the other client');
        }

        $mine = $this->licensed(bulk: true, clientId: $this->service->client_id);
        ExtensionHelper::createServer($mine);
        try {
            BulkLicenseIps::add($mine->load('license')->license, '198.51.100.8');
            BulkLicenseIps::add($this->service->license, '198.51.100.8');
            $this->fail('one IP on two bulk licenses of one client');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('#' . $mine->id, $e->getMessage());
        }

        $single = $this->licensed(bulk: false, clientId: $this->service->client_id);
        ExtensionHelper::createServer($single);
        $single->load('license')->license->update(['valid_ip' => '203.0.113.1,198.51.100.9']);
        $result = BulkLicenseIps::add($this->service->license, '198.51.100.9');
        $this->assertSame(['198.51.100.9'], $result['added']);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('#' . $single->id, $result['warnings'][0]);
        $this->assertSame('Active', $this->check('198.51.100.9')['status'], 'both answer: keys are separate');
        $this->assertSame('Active', $this->check('198.51.100.9', $single->license->license_key)['status']);
    }

    public function test_a_removed_ip_may_go_to_another_bulk_license(): void
    {
        BulkLicenseIps::add($this->service->license, '198.51.100.7');
        BulkLicenseIps::remove($this->service->license, [LicenseIp::query()->sole()->id]);

        $other = $this->licensed(bulk: true);
        ExtensionHelper::createServer($other);
        $this->assertSame(['198.51.100.7'], BulkLicenseIps::add($other->load('license')->license, '198.51.100.7')['added']);
    }

    public function test_a_single_license_takes_no_list_and_is_answered_as_before(): void
    {
        $single = $this->licensed(bulk: false);
        ExtensionHelper::createServer($single);
        $license = $single->load('license')->license;

        $this->expectException(DisplayException::class);
        try {
            BulkLicenseIps::add($license, '198.51.100.7');
        } finally {
            $this->assertSame('Active', $this->check('203.0.113.5', $license->license_key)['status'], 'the first check binds a single one');
            $this->assertSame('203.0.113.5', $license->fresh()->valid_ip);
        }
    }

    public function test_a_bulk_license_is_never_reissued(): void
    {
        $license = $this->service->license;

        $this->assertFalse(Licenses::canReissue($license));
        foreach ([false, true] as $byStaff) {
            try {
                Licenses::reissue($license, '192.0.2.1', byStaff: $byStaff);
                $this->fail('reissued');
            } catch (DisplayException) {
                $this->assertSame(License::STATUS_ACTIVE, $license->fresh()->status);
            }
        }
    }

    public function test_suspended_and_expired_follow_the_service_without_the_list(): void
    {
        BulkLicenseIps::add($this->service->license, '198.51.100.7');

        ExtensionHelper::suspendServer($this->service);
        $answer = $this->check('203.0.113.9');
        $this->assertSame('Suspended', $answer['status']);
        $this->assertArrayNotHasKey('validip', $answer);
        ExtensionHelper::unsuspendServer($this->service);
        $this->assertSame('Active', $this->check('198.51.100.7')['status']);
        ExtensionHelper::terminateServer($this->service);
        $this->assertSame('Expired', $this->check('198.51.100.7')['status']);
    }

    public function test_staff_add_and_remove_ips_from_the_service_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->assertActionHidden('reissueLicense')
            ->callAction('addLicenseIps', ['ips' => "198.51.100.7 web1\n198.51.100.8"])
            ->assertHasNoActionErrors();
        $this->assertSame(['198.51.100.7', '198.51.100.8'], LicenseIp::query()->orderBy('id')->pluck('ip')->all());

        $id = LicenseIp::query()->where('ip', '198.51.100.8')->value('id');
        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->callAction('removeLicenseIps', ['ids' => [$id]])
            ->assertHasNoActionErrors();
        $this->assertSame(['198.51.100.7'], LicenseIp::query()->active()->pluck('ip')->all());
        $this->assertNotNull(LicenseIp::query()->find($id)->removed_at);

        $single = $this->licensed(bulk: false);
        ExtensionHelper::createServer($single);
        $this->livewireAs($staff)->test(EditService::class, ['record' => $single->getRouteKey()])
            ->assertActionHidden('addLicenseIps')->assertActionHidden('removeLicenseIps')->assertActionVisible('reissueLicense');
    }
}
