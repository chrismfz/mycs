<?php

namespace Tests\Feature;

use App\Admin\Resources\ModuleActionResource\Pages\ListModuleActions;
use App\Contracts\ReadsAccounts;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Jobs\Server\UpgradeJob;
use App\Models\CpanelAccount;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\MailcowDomain;
use App\Models\ModuleAction;
use App\Models\ProxmoxGuest;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Cpanel\AccountSync;
use App\Services\Domains\DomainSyncService;
use App\Services\Mailcow\DomainSync;
use App\Services\Mailcow\MailSetup;
use App\Services\Mcp\Proposals;
use App\Services\Proxmox\GuestSync;
use App\Services\Servers\ServerAccount;
use App\Services\Servers\StrayAccounts;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The stray accounts rule (owner 2026-11-08), for every server module: after each read, ONE «check this» task per account
 * that is ended in MyCS but still on the server, and per running account no service has; closed on its own when a later
 * read no longer finds that. Only tasks: nothing is run on a server. A registrar sync's warning is the same kind of task.
 */
class StrayAccountsRuleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<ServerAccount> what the fake module's read reports */
    private array $report = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->freezeTime();
    }

    private function reader(): ReadsAccounts
    {
        return new class($this) implements ReadsAccounts
        {
            public function __construct(private readonly StrayAccountsRuleTest $test) {}

            public function accounts(Server $server): iterable
            {
                return $this->test->reported();
            }
        };
    }

    /** @return list<ServerAccount> */
    public function reported(): array
    {
        return $this->report;
    }

    private function server(string $name = 'Rigel', string $extension = 'CPanel'): Server
    {
        return Server::query()->create(['name' => $name, 'extension' => $extension, 'type' => 'server', 'enabled' => true]);
    }

    private function service(string $status = 'active', ?Server $server = null, array $attributes = []): Service
    {
        $product = $this->createProduct();
        if ($server !== null) {
            $product->product->update(['server_id' => $server->id]);
        }
        $service = Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status]);
        // Changed before the read, not during it.
        Service::query()->whereKey($service->id)->update(['updated_at' => now()->subHour()]);

        return $service->fresh();
    }

    /** Nothing asked of a server (a terminate, a suspend…): the rule only opens tasks. */
    private function assertNoServerJob(): void
    {
        foreach ([CreateJob::class, SuspendJob::class, UnsuspendJob::class, TerminateJob::class, UpgradeJob::class] as $job) {
            Queue::assertNotPushed($job);
        }
    }

    /** @return Collection<int, ModuleAction> */
    private function checks(?string $status = ModuleAction::STATUS_OPEN)
    {
        return ModuleAction::query()->whereIn('action', ModuleAction::STRAY)->when($status, fn ($q) => $q->where('status', $status))->orderBy('id')->get();
    }

    public function test_an_account_ended_in_mycs_and_one_not_mapped_open_one_task_each_never_one_per_read(): void
    {
        $server = $this->server();
        $ended = $this->service('terminated', $server);
        $live = $this->service('active', $server);
        $this->report = [
            new ServerAccount('old', 'old (old.test)', true, $ended->id),
            new ServerAccount('ours', 'ours (ours.test)', true, $live->id),
            new ServerAccount('stray', 'stray (stray.test)', true, null),
            new ServerAccount('asleep', 'asleep', false, null), // suspended and nobody's: not «running»
            new ServerAccount('house', 'house', true, null, internal: true),
        ];

        $this->assertSame(['opened' => 2, 'closed' => 0], StrayAccounts::check($server, $this->reader(), now()));
        $this->assertSame(['opened' => 0, 'closed' => 0], StrayAccounts::check($server, $this->reader(), now()), 'one task, not one per read');

        $tasks = $this->checks();
        $this->assertSame([[ModuleAction::STRAY_ENDED, "{$server->id}:old", $ended->id], [ModuleAction::STRAY_UNMAPPED, "{$server->id}:stray", null]],
            $tasks->map(fn (ModuleAction $t) => [$t->action, $t->subject, $t->service_id])->all());
        $this->assertStringContainsString("its service #{$ended->id} terminated in MyCS", (string) $tasks[0]->last_error);
        $this->assertNotNull($tasks[0]->given_up_at, 'staff\'s at once: the dashboard\'s «Needs attention»');
        $this->assertSame('CPanel', $tasks[0]->module);
        $this->assertSame(Service::STATUS_TERMINATED, $ended->fresh()->status);
        $this->assertNoServerJob(); // only tasks: nothing run on the server
    }

    public function test_a_task_closes_on_its_own_once_a_read_no_longer_finds_it(): void
    {
        $server = $this->server();
        $ended = $this->service('cancelled', $server);
        $other = $this->service('active', $server);
        $this->report = [new ServerAccount('old', 'old', true, $ended->id), new ServerAccount('a', 'a', true, null),
            new ServerAccount('b', 'b', true, null), new ServerAccount('c', 'c', true, null)];
        StrayAccounts::check($server, $this->reader(), now());
        $this->assertCount(4, $this->checks());

        $ended->forceFill(['status' => 'active'])->saveQuietly(); // active again
        $this->report = [new ServerAccount('old', 'old', true, $ended->id),
            new ServerAccount('a', 'a', true, $other->id), // mapped
            new ServerAccount('b', 'b', true, null, internal: true)]; // marked internal; «c» gone from the server
        $this->assertSame(['opened' => 0, 'closed' => 4], StrayAccounts::check($server, $this->reader(), now()));
        $this->assertCount(0, $this->checks());
        $this->assertSame(['cleared: a later read no longer finds it'], $this->checks(ModuleAction::STATUS_RESOLVED)->pluck('resolution')->unique()->values()->all());
    }

    public function test_a_difference_an_open_mycs_action_explains_is_no_task_and_a_service_changed_during_the_read_waits(): void
    {
        $server = $this->server();
        $ended = $this->service('terminated', $server);
        $this->report = [new ServerAccount('old', 'old', true, $ended->id)];
        StrayAccounts::check($server, $this->reader(), now());
        $this->assertCount(1, $this->checks());

        // Its terminate failed and waits in Pending Module Actions: that row is the one to look at.
        ModuleAction::recordFailure($ended, 'terminate', 'CPanel', new \RuntimeException('WHM said no'));
        StrayAccounts::check($server, $this->reader(), now());
        $this->assertCount(0, $this->checks(), 'explained by the open terminate: closed');

        ModuleAction::query()->where('action', 'terminate')->update(['status' => ModuleAction::STATUS_RESOLVED]);
        $ended->forceFill(['updated_at' => now()->addMinute()])->saveQuietly(); // changed after the read began
        StrayAccounts::check($server, $this->reader(), now());
        $this->assertCount(0, $this->checks(), 'the next read decides');

        $this->travel(2)->minutes();
        StrayAccounts::check($server, $this->reader(), now());
        $this->assertCount(1, $this->checks());
        // Undecided again: the open one is left as it is, neither closed nor doubled.
        $ended->forceFill(['updated_at' => now()->addMinute()])->saveQuietly();
        StrayAccounts::check($server, $this->reader(), now());
        $this->assertCount(1, $this->checks());
    }

    public function test_one_staff_checked_stays_closed_while_it_stays_so_and_a_later_return_is_a_new_task(): void
    {
        $server = $this->server();
        $this->report = [new ServerAccount('stray', 'stray', true, null)];
        StrayAccounts::check($server, $this->reader(), now());
        $this->checks()->sole()->markResolved(null);

        StrayAccounts::check($server, $this->reader(), now());
        $this->assertCount(0, $this->checks(), 'checked by staff: not opened again while it stays so');

        $this->report = [];
        StrayAccounts::check($server, $this->reader(), now()); // gone from the server
        $this->report = [new ServerAccount('stray', 'stray', true, null)];
        StrayAccounts::check($server, $this->reader(), now()); // back: a new one
        $this->assertCount(1, $this->checks());
        $this->assertCount(2, $this->checks(null));
    }

    public function test_an_account_that_turns_from_ended_to_not_mapped_moves_to_the_other_check_and_servers_are_apart(): void
    {
        $rigel = $this->server();
        $orion = $this->server('Orion');
        $ended = $this->service('terminated', $rigel);
        $this->report = [new ServerAccount('bob', 'bob', true, $ended->id)];
        StrayAccounts::check($rigel, $this->reader(), now());
        $this->report = [new ServerAccount('bob', 'bob', true, null)];
        StrayAccounts::check($orion, $this->reader(), now());
        $this->assertSame([ModuleAction::STRAY_ENDED, ModuleAction::STRAY_UNMAPPED], $this->checks()->pluck('action')->all(), 'one per server and account');

        StrayAccounts::check($rigel, $this->reader(), now()); // its mapping gone (the service deleted)
        $open = $this->checks();
        $this->assertSame([["{$orion->id}:bob", ModuleAction::STRAY_UNMAPPED], ["{$rigel->id}:bob", ModuleAction::STRAY_UNMAPPED]],
            $open->map(fn (ModuleAction $t) => [$t->subject, $t->action])->all());
        $this->assertSame('cleared: now another check', $this->checks(ModuleAction::STATUS_RESOLVED)->sole()->resolution);
    }

    public function test_a_long_remote_id_fits_the_subject(): void
    {
        $server = $this->server('Mail', 'Mailcow');
        $name = str_repeat('a', 60) . '.' . str_repeat('b', 60) . '.' . str_repeat('c', 60) . '.example.test';
        $this->report = [new ServerAccount($name, $name, true, null)];
        StrayAccounts::check($server, $this->reader(), now());
        StrayAccounts::check($server, $this->reader(), now());

        $task = $this->checks()->sole();
        $this->assertLessThanOrEqual(191, mb_strlen((string) $task->subject));
        $this->assertStringContainsString($name, (string) $task->last_error);
    }

    public function test_staff_see_a_stray_without_a_service_by_its_server_check_it_and_cannot_retry_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $server = $this->server();
        $this->report = [new ServerAccount('stray', 'stray (stray.test)', true, null)];
        StrayAccounts::check($server, $this->reader(), now());
        $task = $this->checks()->sole();
        try {
            Proposals::propose(Proposals::RETRY_MODULE_ACTION, ['module_action_id' => $task->id], $staff);
            $this->fail('the assistant proposed to retry a check');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('nothing to retry', $e->getMessage());
        }

        $this->livewireAs($staff)->test(ListModuleActions::class)
            ->assertCanSeeTableRecords([$task])
            ->assertSee('Rigel · stray')
            ->assertSee('Check: not mapped')
            ->assertTableActionHidden('retry', $task)
            ->callTableAction('resolve', $task);
        $this->assertSame([ModuleAction::STATUS_RESOLVED, ModuleAction::RESOLVED_BY_STAFF, $staff->id],
            [$task->fresh()->status, $task->fresh()->resolution, $task->fresh()->resolved_by_staff_id]);
    }

    public function test_cpanel_ended_accounts_and_running_unmapped_ones_are_tasks_a_resellers_customers_are_not(): void
    {
        $server = $this->server();
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $acct = fn (string $user, string $owner = 'root', int $suspended = 0) => ['user' => $user, 'domain' => "{$user}.test", 'owner' => $owner,
            'plan' => 'Small', 'diskused' => '1M', 'disklimit' => '10M', 'suspended' => $suspended];
        $accounts = [$acct('alpha'), $acct('old'), $acct('stray'), $acct('resel1'), $acct('sub1', 'resel1'), $acct('asleep', suspended: 1), $acct('house')];
        Http::fake(function (Request $request) use (&$accounts) {
            $call = (string) parse_url($request->url(), PHP_URL_PATH);
            $ok = fn (array $data = []) => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => $data]);

            return match (true) {
                str_ends_with($call, '/listaccts') => $ok(['acct' => $accounts]),
                str_ends_with($call, '/listresellers') => $ok(['reseller' => ['resel1']]),
                default => Http::response(['metadata' => ['result' => 0, 'reason' => 'not here']]),
            };
        });
        $alpha = $this->service('active', $server, ['username' => 'alpha']);
        $this->service('active', $server, ['username' => 'resel1']);
        $ended = $this->service('terminated', $server, ['username' => 'old']);
        CpanelAccount::query()->create(['server_id' => $server->id, 'user' => 'old', 'service_id' => $ended->id]);
        CpanelAccount::query()->create(['server_id' => $server->id, 'user' => 'house', 'internal' => true]);

        AccountSync::sync($server);
        AccountSync::sync($server);

        $this->assertSame($alpha->id, CpanelAccount::query()->where('user', 'alpha')->value('service_id'));
        $this->assertSame([[ModuleAction::STRAY_ENDED, "{$server->id}:old", $ended->id], [ModuleAction::STRAY_UNMAPPED, "{$server->id}:stray", null]],
            $this->checks()->map(fn (ModuleAction $t) => [$t->action, $t->subject, $t->service_id])->all());
        $this->assertSame('ended_here', CpanelAccount::query()->with('service')->where('user', 'old')->sole()->differs(), 'the same as the list\'s «Differs»');
        $this->assertNoServerJob();
        $this->assertSame(Service::STATUS_TERMINATED, $ended->fresh()->status);

        // Removed from the server by hand, and the stray mapped: both close on the next read.
        $accounts = [$acct('alpha'), $acct('stray'), $acct('resel1'), $acct('sub1', 'resel1'), $acct('asleep', suspended: 1), $acct('house')];
        CpanelAccount::query()->where('user', 'stray')->update(['service_id' => $alpha->id]);
        AccountSync::sync($server);
        $this->assertCount(0, $this->checks());
    }

    public function test_proxmox_a_guest_whose_service_ended_and_a_running_unmapped_one_are_tasks_templates_and_internal_ones_are_not(): void
    {
        $vega = 'https://vega.example.test:8006';
        $guest = fn (int $vmid, string $name, string $status, int $template = 0) => ['vmid' => $vmid, 'type' => 'qemu', 'node' => 'vega', 'name' => $name,
            'status' => $status, 'template' => $template];
        $resources = [$guest(101, 'old-vps', 'running'), $guest(102, 'off', 'stopped'), $guest(103, 'mystery', 'running'),
            $guest(104, 'debian-tpl', 'running', 1), $guest(105, 'monitor', 'running')];
        Http::fake(function (Request $request) use (&$resources) {
            $path = strtok(substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/api2/json/')), '?');

            return match (true) {
                $path === 'cluster/resources' => Http::response(['data' => $resources]),
                $path === 'nodes' => Http::response(['data' => [['node' => 'vega', 'status' => 'online']]]),
                str_ends_with((string) $path, '/config') => Http::response(['data' => []]),
                default => Http::response(['data' => null], 500),
            };
        });
        $server = $this->server('PVE', 'Proxmox');
        ExtensionHelper::saveSettings($server, ['host' => $vega, 'token_id' => 'mycs@pve!api', 'token_secret' => 's3cret']);
        $server = $server->fresh();
        $ended = $this->service('terminated', $server);
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 101, 'type' => 'qemu', 'node' => 'vega', 'name' => 'old-vps', 'status' => 'running', 'service_id' => $ended->id]);
        ProxmoxGuest::query()->create(['server_id' => $server->id, 'vmid' => 105, 'type' => 'qemu', 'node' => 'vega', 'name' => 'monitor', 'status' => 'running', 'internal' => true]);

        GuestSync::sync($server);
        GuestSync::sync($server);

        $this->assertSame([[ModuleAction::STRAY_ENDED, "{$server->id}:101", $ended->id], [ModuleAction::STRAY_UNMAPPED, "{$server->id}:103", null]],
            $this->checks()->map(fn (ModuleAction $t) => [$t->action, $t->subject, $t->service_id])->all());
        $this->assertStringContainsString('101 old-vps on vega', (string) $this->checks()->first()->last_error);
        $this->assertNoServerJob();

        $resources = [$guest(102, 'off', 'stopped'), $guest(103, 'mystery', 'stopped'), $guest(104, 'debian-tpl', 'running', 1), $guest(105, 'monitor', 'running')];
        GuestSync::sync($server); // 101 destroyed by hand, 103 stopped
        $this->assertCount(0, $this->checks());
    }

    public function test_mailcow_a_domain_whose_service_ended_and_an_active_unmapped_one_are_tasks(): void
    {
        MailSetup::$resolve = fn () => [];
        $api = 'https://mail.example.test';
        $domain = fn (string $name, int $active = 1) => ['domain_name' => $name, 'active_int' => $active, 'mboxes_in_domain' => 0, 'max_quota_for_domain' => 0, 'bytes_total' => 0];
        $domains = [$domain('old.test'), $domain('off.test', 0), $domain('stray.test'), $domain('house.test')];
        Http::fake(function (Request $request) use (&$domains) {
            return match ((string) parse_url($request->url(), PHP_URL_PATH)) {
                '/api/v1/get/domain/all' => Http::response($domains),
                '/api/v1/get/mailbox/all' => Http::response([]),
                default => Http::response([]),
            };
        });
        $server = $this->server('Mymail', 'Mailcow');
        foreach (['host' => $api, 'apikey' => 'api-key-1'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $ended = $this->service('cancelled', $server, ['domain' => 'old.test']);
        MailcowDomain::query()->create(['server_id' => $server->id, 'domain' => 'old.test', 'service_id' => $ended->id]);
        MailcowDomain::query()->create(['server_id' => $server->id, 'domain' => 'house.test', 'internal' => true]);

        DomainSync::sync($server, dns: false);
        DomainSync::sync($server, dns: false);

        $this->assertSame([[ModuleAction::STRAY_ENDED, "{$server->id}:old.test", $ended->id], [ModuleAction::STRAY_UNMAPPED, "{$server->id}:stray.test", null]],
            $this->checks()->map(fn (ModuleAction $t) => [$t->action, $t->subject, $t->service_id])->all());

        $domains = [$domain('off.test', 0), $domain('stray.test', 0), $domain('house.test')];
        DomainSync::sync($server, dns: false);
        $this->assertCount(0, $this->checks());
    }

    public function test_a_registrar_syncs_warning_is_one_domain_task_closed_by_a_sync_with_nothing_to_warn_about(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        $prod = 'https://api.openprovider.eu';
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $domain = Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'registrar_connection_id' => $op->id, 'expires_at' => '2026-11-17']);
        $expiry = '1999-01-01 00:00:00'; // garbled: never moved back
        Http::fake(function (Request $request) use (&$expiry, $prod) {
            $record = ['domain' => ['name' => 'nixpal', 'extension' => 'com'], 'id' => 30391502, 'status' => 'ACT', 'expiration_date' => $expiry,
                'name_servers' => [['name' => 'ns1.nixpal.com']], 'is_locked' => true];

            return str_starts_with($request->url(), $prod . '/v1/auth/login') ? Http::response(['data' => ['token' => 'tok']])
                : (str_contains($request->url(), 'full_name=') ? Http::response(['data' => ['results' => [$record]]]) : Http::response(['data' => $record]));
        });
        $sync = app(DomainSyncService::class);
        $tasks = fn (?string $status = ModuleAction::STATUS_OPEN) => ModuleAction::query()->where('action', ModuleAction::DOMAIN_CHECK)
            ->when($status, fn ($q) => $q->where('status', $status))->get();

        $sync->sync($domain->fresh());
        $sync->sync($domain->fresh());
        $task = $tasks()->sole();
        $this->assertSame([$domain->id, null, 'openprovider'], [$task->domain_id, $task->service_id, $task->module]);
        $this->assertStringContainsString('is before ours (2026-11-17): not applied', (string) $task->last_error);
        $this->assertStringContainsString('is before ours', (string) $domain->fresh()->sync_error, 'the domain\'s own note as before');

        $sync->sync($domain->fresh(), false); // a dry run writes nothing
        $this->assertCount(1, $tasks());

        $expiry = '2027-11-17 00:00:00';
        $sync->sync($domain->fresh());
        $this->assertCount(0, $tasks());
        $this->assertSame('cleared: a later sync no longer warns', $tasks(null)->sole()->resolution);

        // The domain's own renewal open in Pending Module Actions: that row is staff's already.
        ModuleAction::query()->create(['domain_id' => $domain->id, 'action' => ModuleAction::DOMAIN_RENEW, 'status' => ModuleAction::STATUS_OPEN]);
        $expiry = '1999-01-01 00:00:00';
        $sync->sync($domain->fresh());
        $this->assertCount(0, $tasks());
    }
}
