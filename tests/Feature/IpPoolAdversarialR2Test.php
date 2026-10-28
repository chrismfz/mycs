<?php

namespace Tests\Feature;

use App\Admin\Resources\IpPoolResource\Pages\CreateIpPool;
use App\Admin\Resources\IpPoolResource\Pages\EditIpPool;
use App\Exceptions\DisplayException;
use App\Models\IpAddress;
use App\Models\IpPool;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Ip\IpAllocator;
use App\Services\Proxmox\WhmcsIpPoolImport;
use App\Services\Service\DeleteService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #505, round 2 (the fixes of d720edbe). Each test is named for a defect and fails on the code as
 * reviewed (ADV-505-R2-n); the guards, labelled, pass. Documentation ranges only (RFC 5737, RFC 3849).
 */
class IpPoolAdversarialR2Test extends TestCase
{
    use RefreshDatabase;

    private function pool(array $attributes = [], ?string $add = '192.0.2.0/29'): IpPool
    {
        $pool = IpPool::query()->create($attributes + ['name' => 'VPS', 'family' => 4, 'network' => '192.0.2.0/29', 'prefix' => 29,
            'gateway' => '192.0.2.1', 'nameservers' => '192.0.2.53']);
        if ($add !== null) {
            $pool->add($add);
        }

        return $pool;
    }

    private function service(string $status = 'active'): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function whmcs(): PDO
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE mod_pvewhmcs_ip_pools (id INTEGER PRIMARY KEY, title TEXT, gateway TEXT)');
        $whmcs->exec('CREATE TABLE mod_pvewhmcs_ip_addresses (id INTEGER PRIMARY KEY, pool_id INTEGER, ipaddress TEXT, mask TEXT)');
        $whmcs->exec('CREATE TABLE mod_pvewhmcs_vms (id INTEGER PRIMARY KEY, ipaddress TEXT, v6prefix TEXT)');

        return $whmcs;
    }

    /**
     * ADV-505-R2-1: gatewayConflict lets the gateway onto a reserved address (the way staff are told to do it: «reserve it
     * first»), but «Back to the pool» (IpAllocator::reserve false) never asks whether the address is a pool's gateway: the
     * router's address is free again and the next VPS is given it.
     */
    public function test_adv_505_r2_1_unreserving_the_gateway_gives_it_to_a_customer(): void
    {
        $pool = $this->pool(); // hosts .2 to .6, gateway .1
        $six = $pool->addresses()->where('address', '192.0.2.6')->sole();
        IpAllocator::reserve($six, true, 'the new router');
        $this->livewireAs($this->staff())->test(EditIpPool::class, ['record' => $pool->getRouteKey()])
            ->fillForm(['gateway' => '192.0.2.6'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('192.0.2.6', $pool->fresh()->gateway);

        try {
            IpAllocator::reserve($six->fresh(), false);
        } catch (DisplayException) {
        }
        for ($i = 0; $i < 5; $i++) {
            try {
                IpAllocator::assign($this->service(), $pool->fresh());
            } catch (DisplayException) {
            }
        }

        $this->assertNull($six->fresh()->service_id, 'the pool\'s gateway, 192.0.2.6, was given to service #' . $six->fresh()->service_id);
    }

    /**
     * ADV-505-R2-2: IpPool::add leaves out only its own pool's gateway. An address that is another pool's gateway is added
     * and given: a pool of routed /32s (the import's, no network) or a second pool over the same network takes
     * 192.0.2.1, the router of the pool 192.0.2.0/24. gatewayConflict guards only the other way round (the gateway set
     * after the address). The same hole lets in another pool's network or broadcast address (192.0.2.255).
     */
    public function test_adv_505_r2_2_another_pools_gateway_is_added_and_given(): void
    {
        $this->pool(['name' => 'LAN', 'network' => '192.0.2.0/24', 'prefix' => 24], null);
        $routed = IpPool::query()->create(['name' => 'Routed', 'family' => 4, 'network' => null, 'prefix' => 32, 'whmcs_id' => 7]);
        $second = IpPool::query()->create(['name' => 'Half', 'family' => 4, 'network' => '192.0.2.0/25', 'prefix' => 25]);

        foreach ([[$routed, '192.0.2.1'], [$second, '192.0.2.0/25'], [$routed, '192.0.2.255']] as [$pool, $line]) {
            try {
                $pool->add($line);
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame([], IpAddress::query()->whereIn('address', ['192.0.2.1', '192.0.2.255'])->pluck('address')->all(),
            'the gateway (or broadcast address) of «LAN» is an address of another pool, free to be given');
    }

    /** ADV-505-R2-2, IPv6: a prefix added to a second IPv6 pool holds the first pool's gateway. */
    public function test_adv_505_r2_2_an_ipv6_prefix_of_another_pool_holds_its_gateway(): void
    {
        IpPool::query()->create(['name' => 'v6 A', 'family' => 6, 'network' => '2001:db8::/48', 'prefix' => 64, 'gateway' => '2001:db8:0:1::1']);
        $b = IpPool::query()->create(['name' => 'v6 B', 'family' => 6, 'prefix' => 64]);

        try {
            $b->add('2001:db8:0:1::/64');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(0, $b->addresses()->count(), '2001:db8:0:1::/64 holds the gateway of «v6 A»');
    }

    /**
     * ADV-505-R2-3: the re-run skips a pool «brought before» by finding it: one staff deleted (retired, set up anew by
     * hand) is not found, so the next run makes it again with all its addresses and gives them back to the services
     * WHMCS still lists. Of ADV-505-7 («released (or deleted) … given back») only the address half was closed.
     */
    public function test_adv_505_r2_3_a_rerun_brings_back_a_pool_staff_deleted(): void
    {
        $whmcs = $this->whmcs();
        $whmcs->exec("INSERT INTO mod_pvewhmcs_ip_pools VALUES (1, 'Old cloud', '192.0.2.1')");
        $whmcs->exec("INSERT INTO mod_pvewhmcs_ip_addresses VALUES (1, 1, '192.0.2.10', '255.255.255.0')");
        $service = $this->service();
        $whmcs->exec("INSERT INTO mod_pvewhmcs_vms VALUES ({$service->id}, '192.0.2.10', '')");
        (new WhmcsIpPoolImport($whmcs))->run();
        $pool = IpPool::query()->where('whmcs_id', 1)->sole();

        // Staff: the VM moved to a new network; its old address released, the old pool deleted.
        IpAllocator::release($pool->addresses()->sole(), $service->id);
        $pool->delete();

        (new WhmcsIpPoolImport($whmcs))->run();
        $this->assertSame(0, IpPool::query()->count(), 'the deleted pool came back');
        $this->assertSame(0, IpAddress::query()->where('service_id', $service->id)->count(), 'and its address went back to the service');
    }

    /**
     * ADV-505-R2-4: refuseOverlaps compares every new prefix with every earlier one and every prefix of every pool, through
     * inet_pton each time: O(n²). The 4096 a paste may hold (IpRange::MAX) take about two minutes (measured: 130 s), far
     * past a request's limit; 1024, about eight seconds. A sorted sweep (or a range query on packed bounds) is linear.
     */
    public function test_adv_505_r2_4_adding_a_thousand_ipv6_prefixes_takes_seconds(): void
    {
        $pool = IpPool::query()->create(['name' => 'v6', 'family' => 6, 'network' => '2001:db8::/48', 'prefix' => 64]);
        $lines = implode("\n", array_map(fn (int $i) => sprintf('2001:db8:0:%x::/64', $i), range(1, 1024)));

        $started = microtime(true);
        $pool->add($lines);
        $seconds = microtime(true) - $started;

        $this->assertSame(1024, $pool->addresses()->count());
        $this->assertLessThan(3.0, $seconds, sprintf('1024 prefixes took %.1f s', $seconds));
    }

    /**
     * ADV-505-R2-5: the gateway must be in the network (form rule), so an IPv6 pool with a network cannot have the usual
     * IPv6 gateway, the router's link-local fe80::1 (RFC 4861: routers advertise from link-local; Hetzner, OVH and most
     * Proxmox setups give «gateway fe80::1»). Staff leave the network blank to save it, and lose the network check.
     */
    public function test_adv_505_r2_5_an_ipv6_link_local_gateway_is_refused(): void
    {
        $this->livewireAs($this->staff())->test(CreateIpPool::class)
            ->fillForm(['name' => 'v6', 'family' => 6, 'network' => '2001:db8::/48', 'prefix' => 64, 'gateway' => 'fe80::1'])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame('fe80::1', IpPool::query()->sole()->gateway);
    }

    /** Guard (passes): a service deleted by DeleteService gives its addresses back, last in line, its dedicated IP aside. */
    public function test_guard_a_deleted_services_address_waits_its_turn(): void
    {
        $pool = $this->pool(add: "192.0.2.2\n192.0.2.3");
        $old = $this->service();
        IpAllocator::assign($old, $pool);
        $old->forceFill(['status' => Service::STATUS_TERMINATED])->save();
        $this->travel(1)->minutes();

        (new DeleteService)->handle($old->fresh());

        $this->assertNotNull(IpAddress::query()->where('address', '192.0.2.2')->value('released_at'));
        $this->assertSame('192.0.2.3', IpAllocator::assign($this->service(), $pool)->address);
    }

    /** Guard (passes): a pool's network is the source of its IPv4 prefix, whatever the form or the import passes. */
    public function test_guard_the_ipv4_prefix_follows_the_network(): void
    {
        $pool = IpPool::query()->create(['name' => 'X', 'family' => 4, 'network' => '198.51.100.0/26', 'prefix' => 24]);

        $this->assertSame(26, $pool->fresh()->prefix);
        $this->assertSame('255.255.255.192', $pool->fresh()->netmask());
    }

    /** Guard (passes): an IPv6 prefix overlapping another pool's is refused in either order (a /56 after a /64, and back). */
    public function test_guard_overlaps_are_refused_both_ways(): void
    {
        $a = IpPool::query()->create(['name' => 'A', 'family' => 6, 'network' => '2001:db8::/48', 'prefix' => 56]);
        $b = IpPool::query()->create(['name' => 'B', 'family' => 6, 'network' => '2001:db8::/48', 'prefix' => 64]);
        $a->add('2001:db8:0:100::/56');

        $this->expectException(InvalidArgumentException::class);
        $b->add('2001:db8:0:1ff::/64');
    }
}
