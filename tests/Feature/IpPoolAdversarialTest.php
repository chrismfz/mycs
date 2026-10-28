<?php

namespace Tests\Feature;

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
use Throwable;

/**
 * Adversarial review of #505 (IP pools). Each test is named for a defect and fails on the code as reviewed (ADV-505-n).
 * Documentation ranges only (RFC 5737, RFC 3849).
 */
class IpPoolAdversarialTest extends TestCase
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

    private function whmcs(): PDO
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE mod_pvewhmcs_ip_pools (id INTEGER PRIMARY KEY, title TEXT, gateway TEXT)');
        $whmcs->exec('CREATE TABLE mod_pvewhmcs_ip_addresses (id INTEGER PRIMARY KEY, pool_id INTEGER, ipaddress TEXT, mask TEXT)');
        $whmcs->exec('CREATE TABLE mod_pvewhmcs_vms (id INTEGER PRIMARY KEY, ipaddress TEXT, v6prefix TEXT)');

        return $whmcs;
    }

    /**
     * ADV-505-1: ip_addresses.service_id is nullOnDelete and released_at stays null while assigned, so deleting a service
     * (DeleteService, «delete only the record» of a terminated one) turns its held address into a free one that is first
     * in line («never released first»): the next VPS gets the address still configured on the old VM.
     */
    public function test_adv_505_1_deleting_a_service_puts_its_held_address_first_in_line(): void
    {
        $pool = $this->pool(add: "192.0.2.2\n192.0.2.3");
        $old = $this->service();
        $held = IpAllocator::assign($old, $pool);
        $this->assertSame('192.0.2.2', $held->address);
        $old->forceFill(['status' => Service::STATUS_TERMINATED])->save();

        (new DeleteService)->handle($old->fresh());
        $this->assertNull(Service::query()->find($old->id), 'the service is gone');

        $next = IpAllocator::assign($this->service(), $pool);
        $this->assertNotSame('192.0.2.2', $next->address, 'the deleted service\'s address went straight to the next customer');
    }

    /**
     * ADV-505-2: the uniqueness of an IPv6 prefix is by its string only. Two pools of different prefix lengths (or one
     * pool whose prefix length staff edit after prefixes exist: the field stays editable) hold overlapping prefixes, and
     * the same addresses go to two services.
     */
    public function test_adv_505_2_overlapping_ipv6_prefixes_go_to_two_services(): void
    {
        $sixtyFour = IpPool::query()->create(['name' => 'v6 /64', 'family' => 6, 'network' => '2001:db8::/48', 'prefix' => 64]);
        $fiftySix = IpPool::query()->create(['name' => 'v6 /56', 'family' => 6, 'network' => '2001:db8::/48', 'prefix' => 56]);
        $sixtyFour->add('2001:db8:0:5::/64');

        try {
            $fiftySix->add('2001:db8::/56'); // holds 2001:db8:0:5::/64
        } catch (InvalidArgumentException) {
        }

        $a = $this->service();
        $b = $this->service();
        IpAllocator::assign($a, $sixtyFour);
        try {
            IpAllocator::assign($b, $fiftySix);
        } catch (DisplayException) {
        }
        $this->assertSame(0, IpAddress::query()->where('address', '2001:db8::/56')->count(),
            '2001:db8::/56 overlaps 2001:db8:0:5::/64, already in a pool: given to services #' . $a->id . ' and #' . $b->id);
    }

    /**
     * ADV-505-3: «the gateway never» is a string comparison of the gateway with the prefix («2001:db8::/64» is never
     * «2001:db8::1»): the prefix holding the router's own address is given to a customer.
     */
    public function test_adv_505_3_an_ipv6_prefix_holding_the_gateway_is_given_out(): void
    {
        $pool = IpPool::query()->create(['name' => 'v6', 'family' => 6, 'network' => '2001:db8::/48', 'prefix' => 64, 'gateway' => '2001:db8::1']);

        $this->expectException(InvalidArgumentException::class);
        $pool->add('2001:db8::/64');
    }

    /**
     * ADV-505-4: only a network line («a.b.c.0/24») leaves out the network and broadcast addresses; a single address or a
     * range adds them, and a customer is given 192.0.2.255 of a /24, which they cannot use.
     */
    public function test_adv_505_4_the_network_and_broadcast_addresses_are_added_by_a_line_or_a_range(): void
    {
        $pool = $this->pool(['network' => '192.0.2.0/24', 'prefix' => 24], null);

        foreach (['192.0.2.0', '192.0.2.255', '192.0.2.250-192.0.2.255'] as $line) {
            try {
                $pool->add($line);
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertSame([], $pool->addresses()->whereIn('address', ['192.0.2.0', '192.0.2.255'])->pluck('address')->all(),
            'the /24\'s network or broadcast address is in the pool, to be given');
    }

    /**
     * ADV-505-5: the gateway is checked against the addresses only when they are added. Edited afterwards, the pool's
     * gateway may be an address a customer already holds (or the next free one): two machines on one address.
     */
    public function test_adv_505_5_the_gateway_can_be_edited_onto_an_address_a_service_holds(): void
    {
        $pool = $this->pool();
        $given = IpAllocator::assign($this->service(), $pool);

        $this->livewireAs($this->staff())->test(EditIpPool::class, ['record' => $pool->getRouteKey()])
            ->fillForm(['gateway' => $given->address])
            ->call('save')->assertHasFormErrors(['gateway']);
        $this->assertSame('192.0.2.1', $pool->fresh()->gateway);
    }

    /**
     * ADV-505-6: the import takes a pool's network from its first address and then adds them all with IpPool::add,
     * uncaught: one WHMCS pool whose addresses are not all in that network (masks stored as /32, a second subnet, a typo,
     * an IPv6 row) aborts the whole run. The pools after it and every assignment are not imported, on every re-run.
     */
    public function test_adv_505_6_one_odd_whmcs_pool_aborts_the_whole_import(): void
    {
        $whmcs = $this->whmcs();
        $whmcs->exec("INSERT INTO mod_pvewhmcs_ip_pools VALUES (1, 'Two subnets', '192.0.2.1'), (2, 'Fine', '203.0.113.1')");
        $whmcs->exec("INSERT INTO mod_pvewhmcs_ip_addresses VALUES (1, 1, '192.0.2.10', '255.255.255.0'), (2, 1, '198.51.100.10', '255.255.255.0'),
            (3, 2, '203.0.113.10', '255.255.255.0')");
        $service = $this->service();
        $whmcs->exec("INSERT INTO mod_pvewhmcs_vms VALUES ({$service->id}, '203.0.113.10', '')");

        try {
            $counts = (new WhmcsIpPoolImport($whmcs))->run();
        } catch (Throwable $e) {
            $this->fail('The import aborted: ' . $e->getMessage());
        }

        $this->assertNotEmpty($counts['conflicts'], 'the odd pool is told to staff');
        $this->assertSame('203.0.113.10', $service->fresh()->dedicated_ip, 'the next pool and its assignment are imported');
    }

    /**
     * ADV-505-7: «re-runnable … kept as staff left it»: an address staff released (or deleted) after the import is given
     * back (re-added) on the next run, because nothing records that the import already did it. Needs an owner decision
     * (WHMCS as the truth until cut-over?), but as documented it is a defect.
     */
    public function test_adv_505_7_a_rerun_undoes_what_staff_released(): void
    {
        $whmcs = $this->whmcs();
        $whmcs->exec("INSERT INTO mod_pvewhmcs_ip_pools VALUES (1, 'Cloud', '192.0.2.1')");
        $whmcs->exec("INSERT INTO mod_pvewhmcs_ip_addresses VALUES (1, 1, '192.0.2.10', '255.255.255.0')");
        $service = $this->service();
        $whmcs->exec("INSERT INTO mod_pvewhmcs_vms VALUES ({$service->id}, '192.0.2.10', '')");
        (new WhmcsIpPoolImport($whmcs))->run();
        $ip = IpAddress::query()->where('address', '192.0.2.10')->sole();

        IpAllocator::release($ip, $service->id); // staff: the VM moved to another address

        (new WhmcsIpPoolImport($whmcs))->run();
        $this->assertNull($ip->fresh()->service_id, 'the re-run gave it back to the service staff took it from');
    }

    /**
     * ADV-505-8: assign() judges «ended» by the Service model it is handed, not by the row it writes: a model loaded
     * before the service was terminated (a page, a job, the import's loop) gets an address for a terminated service,
     * which is then held at once.
     */
    public function test_adv_505_8_a_stale_service_model_gets_an_address_after_termination(): void
    {
        $pool = $this->pool();
        $stale = $this->service();
        Service::query()->whereKey($stale->id)->update(['status' => Service::STATUS_TERMINATED]);

        $this->expectException(DisplayException::class);
        IpAllocator::assign($stale, $pool);
    }

    /**
     * ADV-505-9: «the dedicated IP follows its first IPv4»: releasing the first leaves dedicated_ip empty though the
     * service still holds another IPv4 of the pools (the customer area and the modules read dedicated_ip).
     */
    public function test_adv_505_9_the_dedicated_ip_does_not_follow_to_the_remaining_ipv4(): void
    {
        $pool = $this->pool();
        $service = $this->service();
        $first = IpAllocator::assign($service, $pool);
        $second = IpAllocator::assign($service, $pool);

        IpAllocator::release($first, $service->id);

        $this->assertSame($second->address, $service->fresh()->dedicated_ip);
    }

    /**
     * ADV-505-10: IpRange::contains checks only a prefix's first address, so an IPv6 pool whose prefix length is shorter
     * than its network (nothing stops it) adds a prefix far larger than the network: 2001:db8::/32 «in» 2001:db8::/48.
     */
    public function test_adv_505_10_an_ipv6_prefix_larger_than_the_network_is_added(): void
    {
        $pool = IpPool::query()->create(['name' => 'v6', 'family' => 6, 'network' => '2001:db8::/48', 'prefix' => 32]);

        $this->expectException(InvalidArgumentException::class);
        $pool->add('2001:db8::/32');
    }

    /**
     * ADV-505-11: an IPv6 pool (its network is optional) takes an IPv4-mapped address, «::ffff:192.0.2.5», a second
     * spelling of 192.0.2.5 that the unique index does not see: the address is given by both an IPv4 and an IPv6 pool.
     */
    public function test_adv_505_11_an_ipv4_mapped_address_is_a_second_spelling_of_an_ipv4_one(): void
    {
        $v4 = $this->pool(add: '192.0.2.5');
        $v6 = IpPool::query()->create(['name' => 'v6', 'family' => 6, 'prefix' => 128]);

        try {
            $v6->add('::ffff:192.0.2.5');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(1, $v4->addresses()->count());
        $this->assertSame(0, $v6->addresses()->count(), '::ffff:192.0.2.5/128 is 192.0.2.5, already in the IPv4 pool');
    }

    /** Guard (passes): a reserve from a page opened before the address was given acts on nothing. */
    public function test_guard_a_stale_reserve_does_not_take_a_given_address(): void
    {
        $pool = $this->pool(add: '192.0.2.2');
        $stale = $pool->addresses()->sole();
        IpAllocator::assign($this->service(), $pool);

        try {
            IpAllocator::reserve($stale, true, 'router');
            $this->fail('reserved an address a service holds');
        } catch (DisplayException) {
        }
        $this->assertFalse((bool) $stale->fresh()->reserved);
    }

    /** Guard (passes): an address of another pool is never given through this one. */
    public function test_guard_an_address_of_another_pool_is_refused(): void
    {
        $a = $this->pool(add: '192.0.2.2');
        $b = $this->pool(['name' => 'B', 'network' => '198.51.100.0/29', 'prefix' => 29, 'gateway' => '198.51.100.1'], '198.51.100.2');

        $this->expectException(DisplayException::class);
        IpAllocator::assign($this->service(), $b, $a->addresses()->sole());
    }
}
