<?php

namespace Tests\Feature;

use App\Admin\Resources\IpPoolResource;
use App\Admin\Resources\IpPoolResource\Pages\CreateIpPool;
use App\Admin\Resources\IpPoolResource\Pages\EditIpPool;
use App\Admin\Resources\IpPoolResource\Pages\ViewIpPool;
use App\Admin\Resources\IpPoolResource\RelationManagers\AddressesRelationManager;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Exceptions\DisplayException;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\ProxmoxGuest;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Ip\IpAllocator;
use App\Services\Proxmox\WhmcsIpPoolImport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PDO;
use Tests\TestCase;

/**
 * The VPS IP pools (docs/proxmox.md, B3): a pool's addresses, given to a service and taken back, never one to two; the
 * service's dedicated IP following; what is seen on the cluster against what is given; the WHMCS import.
 * Documentation ranges only (RFC 5737, RFC 3849).
 */
class IpPoolTest extends TestCase
{
    use RefreshDatabase;

    private function pool(array $attributes = [], ?string $add = '192.0.2.0/29'): IpPool
    {
        $pool = IpPool::query()->create($attributes + ['name' => 'VPS', 'family' => 4, 'network' => '192.0.2.0/29', 'prefix' => 29,
            'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53, 198.51.100.53']);
        if ($add !== null) {
            $pool->add($add);
        }

        return $pool;
    }

    private function service(string $status = 'active', array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
    }

    private function staff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
    }

    public function test_a_network_adds_its_hosts_without_the_gateway_and_nothing_twice(): void
    {
        $pool = $this->pool();

        $this->assertSame(['192.0.2.2', '192.0.2.3', '192.0.2.4', '192.0.2.5', '192.0.2.6'], $pool->addresses()->orderBy('id')->pluck('address')->all());
        $other = $this->pool(['name' => 'Other', 'network' => '192.0.2.0/24', 'prefix' => 24], null);
        $this->assertSame(['added' => 1, 'skipped' => ['192.0.2.3']], $other->add("192.0.2.3\n192.0.2.100"));
        $this->assertSame(5, $pool->addresses()->count(), 'one in another pool is not moved');
    }

    public function test_what_is_out_of_the_pool_is_refused_whole(): void
    {
        $pool = $this->pool(add: null);

        foreach (["192.0.2.2\n198.51.100.7", '192.0.2.1', '2001:db8::1', 'nonsense'] as $lines) {
            try {
                $pool->add($lines);
                $this->fail("{$lines} was added");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, $pool->addresses()->count(), 'nothing of a refused batch');
    }

    public function test_an_ipv6_pool_holds_prefixes_of_its_length(): void
    {
        $pool = IpPool::query()->create(['name' => 'v6', 'family' => 6, 'network' => '2001:db8::/48', 'prefix' => 64, 'gateway' => '2001:db8::1']);

        $this->assertSame(2, $pool->add("2001:db8:0:1::\n2001:DB8:0:2:0::/64")['added']);
        $this->assertSame(['2001:db8:0:1::/64', '2001:db8:0:2::/64'], $pool->addresses()->orderBy('id')->pluck('address')->all());
        $this->expectException(InvalidArgumentException::class);
        $pool->add('2001:db8:0:3::/56');
    }

    public function test_the_next_free_is_given_one_never_released_first_and_the_dedicated_ip_follows(): void
    {
        $pool = $this->pool();
        $pool->addresses()->where('address', '192.0.2.2')->update(['released_at' => now()->subDay()]);
        $service = $this->service();

        $ip = IpAllocator::assign($service, $pool);

        $this->assertSame('192.0.2.3', $ip->address, 'one just freed waits');
        $this->assertSame('192.0.2.3', $service->fresh()->dedicated_ip);
        $this->assertNotNull($ip->assigned_at);
        $this->assertDatabaseHas('audits', ['auditable_id' => $service->id, 'event' => 'ip_assigned']);

        // A second one leaves the dedicated IP as it is, and one staff typed stays theirs.
        $second = IpAllocator::assign($service, $pool);
        $this->assertSame('192.0.2.3', $service->fresh()->dedicated_ip);
        $this->assertEqualsCanonicalizing(['192.0.2.3', $second->address], $service->ipAddresses()->pluck('address')->all());
        $typed = $this->service(attributes: ['dedicated_ip' => '198.51.100.20']);
        IpAllocator::assign($typed, $pool);
        $this->assertSame('198.51.100.20', $typed->fresh()->dedicated_ip);
    }

    public function test_one_address_is_never_given_to_two(): void
    {
        $pool = $this->pool(add: '192.0.2.2');
        $address = $pool->addresses()->first();
        $first = $this->service();
        $second = $this->service();

        IpAllocator::assign($first, $pool, $address);
        try {
            IpAllocator::assign($second, $pool, $address); // the page opened before: its copy still says free
            $this->fail('given twice');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('no longer free', $e->getMessage());
        }
        $this->expectException(DisplayException::class);
        IpAllocator::assign($second, $pool); // none left
    }

    public function test_an_ended_service_is_given_nothing_and_holds_its_address_until_released(): void
    {
        $pool = $this->pool();
        $service = $this->service();
        $ip = IpAllocator::assign($service, $pool);
        $service->forceFill(['status' => Service::STATUS_TERMINATED])->save();

        $this->assertSame(IpAddress::HELD, $ip->fresh()->state());
        $this->assertSame(1, IpAddress::whereHeld(IpAddress::query())->count());
        $this->assertSame(4, IpAddress::whereFree(IpAddress::query())->count(), 'held is never given meanwhile');
        try {
            IpAllocator::assign($service, $pool);
            $this->fail('an ended service was given an address');
        } catch (DisplayException) {
        }

        IpAllocator::release($ip->fresh());
        $this->assertSame(IpAddress::FREE, $ip->fresh()->state());
        $this->assertNotNull($ip->fresh()->released_at);
        $this->assertNull($service->fresh()->dedicated_ip);
        $this->assertDatabaseHas('audits', ['auditable_id' => $service->id, 'event' => 'ip_released']);
    }

    public function test_a_release_from_a_stale_page_acts_on_nothing(): void
    {
        $pool = $this->pool(add: '192.0.2.2');
        $first = $this->service();
        $second = $this->service();
        $ip = IpAllocator::assign($first, $pool);
        IpAllocator::release($ip);
        IpAllocator::assign($second, $pool);

        $this->expectException(DisplayException::class);
        IpAllocator::release($ip, $first->id); // the first one's page, opened before
    }

    public function test_only_a_free_address_is_reserved(): void
    {
        $pool = $this->pool();
        $ip = IpAllocator::assign($this->service(), $pool);
        $free = $pool->addresses()->whereNull('service_id')->first();

        IpAllocator::reserve($free, true, 'our router');
        $this->assertSame([IpAddress::RESERVED, 'our router'], [$free->state(), $free->note]);
        $this->expectException(DisplayException::class);
        IpAllocator::reserve($ip, true);
    }

    public function test_what_the_cluster_shows_against_what_is_given(): void
    {
        $pool = $this->pool();
        $server = Server::query()->create(['name' => 'PVE', 'extension' => 'Proxmox', 'type' => 'server', 'enabled' => true]);
        $mine = $this->service();
        $theirs = $this->service();
        $given = IpAllocator::assign($mine, $pool);
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 101, 'node' => 'vega', 'name' => 'a', 'ips' => [$given->address], 'service_id' => $theirs->id]);
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 102, 'node' => 'vega', 'name' => 'b', 'ips' => ['192.0.2.6/29']]);

        $this->assertStringContainsString("service #{$theirs->id}'s, not #{$mine->id}'s", AddressesRelationManager::mismatch($given));
        $this->assertStringContainsString('In use on VM 102, but free here', AddressesRelationManager::mismatch($pool->addresses()->where('address', '192.0.2.6')->first()));
        $this->assertNull(AddressesRelationManager::mismatch($pool->addresses()->where('address', '192.0.2.5')->first()));
    }

    public function test_staff_create_a_pool_with_its_addresses_and_give_one_from_the_service_page(): void
    {
        $staff = $this->staff();
        $service = $this->service();

        $this->livewireAs($staff)->test(CreateIpPool::class)
            ->fillForm(['name' => 'VPS', 'family' => 4, 'network' => '192.0.2.0/29', 'prefix' => 29, 'gateway' => '192.0.2.1', 'add_hosts' => true])
            ->call('create')->assertHasNoFormErrors();
        $pool = IpPool::query()->sole();
        $this->assertSame(5, $pool->addresses()->count());

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('assignIp', ['pool' => $pool->id])->assertNotified()
            ->assertFormSet(['dedicated_ip' => '192.0.2.2']);
        $this->assertSame('192.0.2.2', $service->fresh()->dedicated_ip);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('releaseIp', ['address' => $service->ipAddresses()->value('id')])->assertNotified();
        $this->assertSame(0, $service->ipAddresses()->count());
    }

    public function test_a_network_with_host_bits_or_a_gateway_outside_is_refused(): void
    {
        $this->livewireAs($this->staff())->test(CreateIpPool::class)
            ->fillForm(['name' => 'VPS', 'family' => 4, 'network' => '192.0.2.5/24', 'prefix' => 24, 'gateway' => '198.51.100.1'])
            ->call('create')->assertHasFormErrors(['network', 'gateway']);
        $this->assertSame(0, IpPool::query()->count());
    }

    public function test_a_pool_a_service_holds_an_address_of_is_not_deleted_and_readers_change_nothing(): void
    {
        $pool = $this->pool();
        IpAllocator::assign($this->service(), $pool);

        $this->assertFalse($this->staff()->can('delete', $pool));
        $reader = $this->staff(['admin.servers.viewAny']);
        $this->livewireAs($reader)->test(AddressesRelationManager::class, ['ownerRecord' => $pool, 'pageClass' => EditIpPool::class])
            ->assertTableActionHidden('release', $pool->addresses()->whereNotNull('service_id')->first())
            ->assertTableActionHidden('add');
        $this->actingAs($this->staff(['admin.services.viewAny']), 'staff');
        $this->get(IpPoolResource::getUrl())->assertForbidden();
    }

    public function test_the_whmcs_pool_is_imported_with_who_holds_what_and_again_changes_nothing(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE mod_pvewhmcs_ip_pools (id INTEGER PRIMARY KEY, title TEXT, gateway TEXT)');
        $whmcs->exec('CREATE TABLE mod_pvewhmcs_ip_addresses (id INTEGER PRIMARY KEY, pool_id INTEGER, ipaddress TEXT, mask TEXT)');
        $whmcs->exec('CREATE TABLE mod_pvewhmcs_vms (id INTEGER PRIMARY KEY, ipaddress TEXT, v6prefix TEXT)');
        $whmcs->exec("INSERT INTO mod_pvewhmcs_ip_pools VALUES (1, 'Cloud', '192.0.2.1')");
        foreach (['192.0.2.10', '192.0.2.11', '192.0.2.12'] as $i => $ip) {
            $whmcs->exec('INSERT INTO mod_pvewhmcs_ip_addresses VALUES (' . ($i + 1) . ", 1, '{$ip}', '255.255.255.0')");
        }
        $service = $this->service();
        $whmcs->exec("INSERT INTO mod_pvewhmcs_vms VALUES ({$service->id}, '192.0.2.10', '1'), (99999, '192.0.2.11', ''), (" . ($service->id + 50000) . ", '198.51.100.9', '')");

        $counts = (new WhmcsIpPoolImport($whmcs))->run();

        $this->assertSame([1, 3, 1, 1], [$counts['pools'], $counts['addresses'], $counts['given'], $counts['without_service']]);
        $this->assertCount(1, $counts['outside']);
        $pool = IpPool::query()->sole();
        $this->assertSame(['192.0.2.0/24', 24, '192.0.2.1'], [$pool->network, $pool->prefix, $pool->gateway]);
        $this->assertSame('192.0.2.10', $service->fresh()->dedicated_ip);

        $again = (new WhmcsIpPoolImport($whmcs))->run();
        $this->assertSame([0, 0, 0], [$again['pools'], $again['addresses'], $again['given']]);
        $this->assertSame([], $again['conflicts']);
    }

    /** Review of #505, 2 and 3: an IPv4 pool's mask is its network's, whatever is sent. */
    public function test_an_ipv4_pools_mask_is_its_networks(): void
    {
        $pool = $this->pool(['network' => '192.0.2.0/24', 'prefix' => 28], null);
        $this->assertSame(24, $pool->prefix);

        $pool->update(['prefix' => 16]);
        $this->assertSame(24, $pool->fresh()->prefix);
    }

    /** Review of #505, 5: a block of a wider pool keeps its edge addresses; only the pool's own network and broadcast go. */
    public function test_a_block_of_a_wider_pool_keeps_its_edges(): void
    {
        $pool = $this->pool(['network' => '192.0.2.0/24', 'prefix' => 24], null);

        $this->assertSame(32, $pool->add('192.0.2.64/27')['added']);
        $this->assertSame(2, $pool->add('192.0.2.0/30')['added'], '.0 the network\'s, .1 the gateway');
        $this->assertFalse($pool->addresses()->whereIn('address', ['192.0.2.0', '192.0.2.1'])->exists());
    }

    /** Review of #505, 10: staff who may only read see a pool's addresses. */
    public function test_a_reader_sees_a_pools_addresses(): void
    {
        $pool = $this->pool();
        $reader = $this->staff(['admin.servers.viewAny']);

        $this->actingAs($reader, 'staff');
        $this->get(IpPoolResource::getUrl('view', ['record' => $pool]))->assertOk();
        $this->livewireAs($reader)->test(AddressesRelationManager::class, ['ownerRecord' => $pool, 'pageClass' => ViewIpPool::class])
            ->assertCanSeeTableRecords($pool->addresses()->get())->assertSee('192.0.2.2');
        $this->get(IpPoolResource::getUrl('edit', ['record' => $pool]))->assertForbidden();
    }

    /** Review of #505, 12: an address given while its delete was open is not deleted. */
    public function test_an_address_given_meanwhile_is_not_deleted(): void
    {
        $pool = $this->pool(add: '192.0.2.2');
        $address = $pool->addresses()->sole();
        $staff = $this->staff();
        $page = $this->livewireAs($staff)->test(AddressesRelationManager::class, ['ownerRecord' => $pool, 'pageClass' => EditIpPool::class])
            ->mountTableAction('delete', $address);
        IpAllocator::assign($this->service(), $pool, $address);

        $page->callMountedTableAction();
        $this->assertTrue($address->fresh()?->service_id !== null);
    }
}
