<?php

namespace Tests\Feature;

use App\Admin\Resources\CpanelAccountResource\Pages\ListCpanelAccounts;
use App\Admin\Resources\ServiceResource;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\CpanelAccount;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Cpanel\AccountSync;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Utilities → Hosting Accounts (owner 2026-10-28: «what goes on in each server», hosting the main product): a WHM
 * server's accounts read (read-only), with their limits, suspension, mail and backups, each mapped to its service.
 */
class CpanelInventoryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $accounts = [];

    private bool $bandwidthDown = false;

    private bool $down = false;

    /** Run as the server answers listaccts (a service changed while it is read). */
    private ?\Closure $whileListing = null;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->server = Server::query()->create(['name' => 'Rigel', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->accounts = [
            ['user' => 'Alpha', 'domain' => 'alpha.test', 'owner' => 'root', 'plan' => 'Business10', 'ip' => '192.0.2.5', 'diskused' => '9300M', 'disklimit' => '10000M',
                'inodesused' => '120000', 'inodeslimit' => '250000', 'suspended' => 0, 'outgoing_mail_hold' => 1, 'outgoing_mail_suspended' => 0,
                'max_email_per_hour' => '200', 'backup' => 1, 'unix_startdate' => '1700000000'],
            ['user' => 'resel1', 'domain' => 'reseller.test', 'owner' => 'root', 'plan' => 'Reseller', 'diskused' => '100M', 'disklimit' => 'unlimited',
                'inodesused' => '10', 'inodeslimit' => '0', 'suspended' => 1, 'suspendreason' => 'Overdue', 'suspendtime' => '1790000000', 'backup' => 0],
            ['user' => 'client1', 'domain' => 'client1.test', 'owner' => 'root', 'plan' => 'resel1_Small', 'diskused' => '1G', 'disklimit' => '2G', 'suspended' => 0, 'suspendreason' => 'not suspended'],
            ['user' => 'sub1', 'domain' => 'sub1.test', 'owner' => 'resel1', 'plan' => 'resel1_Small', 'diskused' => '1G', 'disklimit' => '2G'],
        ];
        Http::fake(function (Request $request) {
            if ($this->down) {
                return Http::failedConnection();
            }
            $call = (string) parse_url($request->url(), PHP_URL_PATH);
            $ok = fn (array $data = []) => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => $data]);

            return match (true) {
                str_ends_with($call, '/listaccts') => tap($ok(['acct' => $this->accounts]), fn () => $this->whileListing && ($this->whileListing)()),
                str_ends_with($call, '/showbw') => $this->bandwidthDown ? Http::response(['metadata' => ['result' => 0, 'reason' => 'denied']])
                    : $ok(['acct' => [['user' => 'alpha', 'totalbytes' => 5 * 1073741824, 'limit' => 10 * 1073741824], ['user' => 'gone1', 'totalbytes' => 1, 'deleted' => 1]]]),
                str_ends_with($call, '/listresellers') => $ok(['reseller' => ['resel1']]),
                default => Http::response(['metadata' => ['result' => 0, 'reason' => 'not here']]),
            };
        });
    }

    private function staff(array $permissions = ['admin.servers.viewAny', 'admin.servers.update']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function service(string $username, string $status = 'active', bool $property = false): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'username' => $property ? 'other' : $username]);
        if ($property) {
            $service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => $username]);
        }

        return $service;
    }

    public function test_the_accounts_are_read_with_their_limits_state_and_one_live_service_each(): void
    {
        $alpha = $this->service('alpha');
        $client = $this->service('client1', property: true);
        $this->service('resel1', 'terminated');

        $sub = $this->service('Sub1');

        $this->assertSame(['seen' => 4, 'new' => 4, 'gone' => 0], AccountSync::sync($this->server));
        $this->assertNull(CpanelAccount::query()->where('user', 'sub1')->value('service_id'), 'a reseller\'s customer\'s account: never by name alone');
        $root = CpanelAccount::query()->where('user', 'sub1')->sole()->fill(['owner' => 'root']);
        $this->assertSame($sub->id, $root->suggestedServiceId(), 'one of root\'s: Sub1, its name lower-cased');

        $a = CpanelAccount::query()->where('user', 'alpha')->sole();
        $this->assertSame(['alpha.test', 'Business10', 9300, 10000, 120000, 250000, 5120, 10240, true, false, 200, true, false, $alpha->id],
            [$a->domain, $a->plan, $a->disk_used_mb, $a->disk_limit_mb, $a->inodes_used, $a->inodes_limit, $a->bandwidth_used_mb, $a->bandwidth_limit_mb,
                $a->mail_held, $a->mail_suspended, $a->max_email_per_hour, $a->backup, $a->reseller, $a->service_id]);
        $this->assertSame(['disk'], $a->nearLimits());
        $r = CpanelAccount::query()->where('user', 'resel1')->sole();
        $this->assertSame([true, true, 'Overdue', null, null, 0, null], [$r->reseller, $r->suspended, $r->suspend_reason, $r->disk_limit_mb, $r->inodes_limit, $r->bandwidth_used_mb, $r->service_id]);
        $this->assertNull(CpanelAccount::query()->where('user', 'client1')->value('suspend_reason'), 'WHM\'s «not suspended» is no reason');
        $this->assertSame($client->id, CpanelAccount::query()->where('user', 'client1')->value('service_id'), 'its cPanel username property');
    }

    public function test_bandwidth_that_cannot_be_read_keeps_what_was_read_and_a_failed_or_empty_read_marks_nothing_gone(): void
    {
        AccountSync::sync($this->server);
        $this->bandwidthDown = true;
        AccountSync::sync($this->server);
        $this->assertSame(5120, CpanelAccount::query()->where('user', 'alpha')->value('bandwidth_used_mb'));

        $this->accounts = [];
        try {
            AccountSync::sync($this->server);
            $this->fail('an empty list where there were accounts is no server emptied');
        } catch (\RuntimeException) {
        }
        $this->down = true;
        $this->artisan('cpanel:sync')->assertExitCode(1);
        $this->assertSame(0, CpanelAccount::query()->whereNotNull('gone_at')->count());
    }

    public function test_an_account_gone_is_kept_with_its_mapping_and_one_back_for_a_new_live_service_is_its(): void
    {
        $old = $this->service('alpha');
        AccountSync::sync($this->server);
        $saved = $this->accounts;
        array_shift($this->accounts);
        AccountSync::sync($this->server);
        $gone = CpanelAccount::query()->where('user', 'alpha')->sole();
        $this->assertNotNull($gone->gone_at);
        $this->assertSame($old->id, $gone->service_id);

        $old->update(['status' => 'terminated']);
        $new = $this->service('alpha');
        $this->accounts = $saved;
        AccountSync::sync($this->server);

        $this->assertSame([null, $new->id], [CpanelAccount::query()->where('user', 'alpha')->value('gone_at'), CpanelAccount::query()->where('user', 'alpha')->value('service_id')]);
    }

    public function test_services_without_their_account_are_listed(): void
    {
        $this->service('alpha');
        $missing = $this->service('nosuch');
        $this->service('nosuch2', 'terminated');

        AccountSync::sync($this->server);

        $this->assertSame([$missing->id], AccountSync::servicesWithoutAccount()->pluck('id')->all());
        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)
            ->mountAction('withoutAccount')->assertMountedActionModalSee(['#' . $missing->id, 'username nosuch']);
    }

    public function test_staff_see_filter_and_map_the_accounts(): void
    {
        AccountSync::sync($this->server);
        $alpha = CpanelAccount::query()->where('user', 'alpha')->sole();
        $reseller = CpanelAccount::query()->where('user', 'resel1')->sole();
        $client = CpanelAccount::query()->where('user', 'client1')->sole();
        $service = $this->service('client1');

        $page = $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)->assertCanSeeTableRecords([$alpha, $reseller, $client]);
        $page->filterTable('near', true)->assertCanSeeTableRecords([$alpha])->assertCanNotSeeTableRecords([$reseller, $client]);
        $sub = CpanelAccount::query()->where('user', 'sub1')->sole();
        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)->filterTable('mail', true)->assertCanSeeTableRecords([$alpha])->assertCanNotSeeTableRecords([$client]);
        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)->filterTable('owner', 'resel1')->assertCanSeeTableRecords([$sub])->assertCanNotSeeTableRecords([$alpha, $client]);
        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)->callTableAction('assign', $client, data: ['service_id' => $service->id])->assertHasNoTableActionErrors();
        $this->assertSame($service->id, $client->fresh()->service_id);
    }

    public function test_staff_who_may_only_look_cannot_map_or_read(): void
    {
        AccountSync::sync($this->server);
        $alpha = CpanelAccount::query()->where('user', 'alpha')->sole();

        $this->livewireAs($this->staff(['admin.servers.viewAny']))->test(ListCpanelAccounts::class)
            ->assertCanSeeTableRecords([$alpha])->assertTableActionHidden('assign', $alpha)->assertActionHidden('refresh');
        $this->livewireAs($this->staff([]))->test(ListCpanelAccounts::class)->assertForbidden();
    }

    /** The service pages: the customer sees its files and that it is near a limit; staff, what is not right on the server. */
    public function test_the_service_pages_show_its_inodes_and_what_is_near_or_not_right(): void
    {
        $alpha = $this->service('alpha');
        AccountSync::sync($this->server);
        $alpha->usage()->create(['disk_used_mb' => 9300, 'disk_limit_mb' => 10000, 'read_at' => now()]);

        $this->livewireAs($alpha->client->users()->first())->test(ServiceShow::class, ['service' => $alpha->fresh()])
            ->assertSee(__('services.usage.inodes'))->assertSee('120000')
            ->assertSee(__('services.usage.near', ['limits' => __('services.usage.limits.disk')]))->assertSee(route('services.upgrade', $alpha));

        $line = ServiceResource::summaryLine($alpha->fresh());
        $this->assertSame('120000 / 250000 (48%)', $line['inodes']);
        $this->assertSame(['Outgoing mail held', 'Near its disk limit'], $line['serverState']);
    }

    /** Review of #495: an older live account is found past a newer gone one; a server never read flags no service. */
    public function test_the_service_finds_its_live_account_and_an_unread_server_flags_nothing(): void
    {
        $alpha = $this->service('alpha');
        $unread = Server::query()->create(['name' => 'New', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $unread->id]);
        Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'username' => 'someone']);
        AccountSync::sync($this->server);
        $moved = Server::query()->create(['name' => 'Moved', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => false]);
        CpanelAccount::query()->create(['server_id' => $moved->id, 'user' => 'alpha', 'service_id' => $alpha->id, 'gone_at' => now()]);

        $this->assertSame($this->server->id, $alpha->fresh()->cpanelAccount?->server_id, 'the live one, past a newer gone one');
        $this->assertNotContains('someone', AccountSync::servicesWithoutAccount()->pluck('username')->all(), 'its server never read: not flagged');
    }

    /** Round 2 of #495: a server reached with a reseller's login maps its accounts; one mapped by hand has its account. */
    public function test_a_reseller_logins_accounts_are_mapped_and_a_hand_mapping_counts(): void
    {
        $this->server->settings()->where('key', 'username')->update(['value' => 'Resel1']);
        $sub = $this->service('sub1');
        $other = $this->service('renamed');

        AccountSync::sync($this->server);
        $this->assertSame($sub->id, CpanelAccount::query()->where('user', 'sub1')->value('service_id'), 'owned by the server\'s login: ours');

        $this->assertContains($other->id, AccountSync::servicesWithoutAccount()->pluck('id')->all());
        CpanelAccount::query()->where('user', 'alpha')->update(['service_id' => $other->id]);
        $this->assertNotContains($other->id, AccountSync::servicesWithoutAccount()->pluck('id')->all());
    }

    /** Owner 2026-10-28: a row fits a smaller screen: one state badge, the mapping cut short, the actions in one menu. */
    public function test_a_row_is_compact_one_state_badge_and_the_mapping_cut_short(): void
    {
        AccountSync::sync($this->server);
        $reseller = CpanelAccount::query()->where('user', 'resel1')->sole();
        $reseller->update(['mail_suspended' => true]);
        $client = CpanelAccount::query()->where('user', 'client1')->sole();
        $client->update(['internal' => true, 'note' => 'our own demo site for the sales team']);

        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)
            ->assertTableColumnStateSet('state', 'Suspended', $reseller)->assertDontSee('Mail suspended')
            ->assertSee('Internal: our own demo...')
            ->assertSee('our own demo site for the sales team') // whole, on hover
            ->callTableAction('internal', $client, data: ['note' => 'demo'])->assertHasNoTableActionErrors();
        $this->assertSame('demo', $client->fresh()->note, 'an action grouped still works');
        $group = collect($this->livewireAs($this->staff())->test(ListCpanelAccounts::class)->instance()->getTable()->getRecordActions())->sole();
        $this->assertInstanceOf(ActionGroup::class, $group, 'the actions in one menu');
    }

    /**
     * Owner 2026-10-28: an account whose state differs from its service's (active here, suspended there; suspended here,
     * active there; ended here, still there) is flagged, on the list (a filter, its «No» a real negative) and the
     * service's page; one that agrees, is pending, gone or never read is not.
     */
    public function test_an_account_whose_state_differs_from_its_service_is_flagged_and_one_that_agrees_is_not(): void
    {
        $this->accounts[2]['suspended'] = 1;                    // client1 suspended on the server
        $this->accounts[] = ['user' => 'agree1', 'owner' => 'root', 'suspended' => 0];
        $this->accounts[] = ['user' => 'agree2', 'owner' => 'root', 'suspended' => 1];
        $this->accounts[] = ['user' => 'new1', 'owner' => 'root', 'suspended' => 1];
        $paying = $this->service('client1');                    // active here
        $unpaid = $this->service('alpha', 'suspended');         // suspended here, active there
        $this->service('agree1');                               // active and active
        $this->service('agree2', 'suspended');                  // suspended and suspended
        $this->service('new1', 'pending');                      // pending: never
        $ended = $this->service('sub1', 'terminated');          // ended here, still there
        $this->travel(2)->seconds();                            // the services older than the read
        AccountSync::sync($this->server);
        $row = fn (string $user) => CpanelAccount::query()->where('user', $user)->sole();
        $row('sub1')->update(['service_id' => $ended->id]);     // a reseller's customer, mapped by hand
        $gone = CpanelAccount::query()->create(['server_id' => $this->server->id, 'user' => 'old1', 'service_id' => $this->service('old1', 'terminated')->id,
            'last_seen_at' => now(), 'gone_at' => now()]);
        $unread = CpanelAccount::query()->create(['server_id' => $this->server->id, 'user' => 'unread1', 'service_id' => $this->service('unread1', 'terminated')->id]);

        $this->assertSame('suspended_on_server', $row('client1')->differs());
        $this->assertSame('active_on_server', $row('alpha')->differs());
        $this->assertSame('ended_here', $row('sub1')->differs());
        foreach (['agree1', 'agree2', 'new1', 'resel1'] as $user) {
            $this->assertNull($row($user)->differs(), $user);
        }
        $this->assertNull($gone->differs());
        $this->assertNull($unread->differs());

        $differing = [$row('client1'), $row('alpha'), $row('sub1')];
        $agreeing = [$row('agree1'), $row('agree2'), $row('new1'), $row('resel1'), $unread];
        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)
            ->assertTableColumnStateSet('state', 'Differs', $row('client1'))
            ->filterTable('differs', true)->assertCanSeeTableRecords($differing)->assertCanNotSeeTableRecords($agreeing);
        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)
            ->filterTable('differs', false)->assertCanSeeTableRecords($agreeing)->assertCanNotSeeTableRecords($differing);
        $this->assertContains(CpanelAccount::DIFFERS['suspended_on_server'], ServiceResource::summaryLine($paying->fresh())['serverState']);

        // MyCS unsuspends it after the read: not «differs» until the server is read again.
        $this->travel(1)->minutes();
        $unpaid->update(['status' => 'active']);
        $this->assertNull($row('alpha')->differs(), 'changed since the read: wait for the next one');
        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)->filterTable('differs', true)->assertCanNotSeeTableRecords([$row('alpha')]);
    }

    /**
     * Review of «Differs»: no false flag from MyCS's own job (open in Pending Module Actions), nor from a reseller's
     * suspension reaching its customer; a service imported with no date is compared; the read is stamped as it began.
     */
    public function test_differs_skips_an_open_job_and_a_resellers_cascade_and_compares_an_undated_import(): void
    {
        $this->accounts[2]['suspended'] = 1;                    // client1
        $this->accounts[3]['suspended'] = 1;                    // sub1, its reseller resel1 suspended
        $client = $this->service('client1');
        $sub = $this->service('sub1');
        $undated = $this->service('alpha', 'suspended');
        $this->travel(1)->minutes();
        AccountSync::sync($this->server);
        CpanelAccount::query()->where('user', 'sub1')->update(['service_id' => $sub->id]);
        Service::query()->whereKey($undated->id)->update(['updated_at' => null]); // as the importer leaves a 0000-00-00 regdate
        $row = fn (string $user) => CpanelAccount::query()->where('user', $user)->sole();

        $this->assertSame('active_on_server', $row('alpha')->differs(), 'no date: older than any read');
        $this->assertNull($row('sub1')->differs(), 'suspended with its reseller: not to unsuspend');
        $this->assertSame('suspended_on_server', $row('client1')->differs());
        ModuleAction::query()->create(['service_id' => $client->id, 'action' => 'unsuspend', 'status' => ModuleAction::STATUS_COMPLETED]);
        $this->assertSame('suspended_on_server', $row('client1')->differs(), 'a job done long ago explains nothing');
        ModuleAction::query()->create(['service_id' => $client->id, 'action' => 'unsuspend', 'status' => ModuleAction::STATUS_OPEN]);
        $this->assertNull($row('client1')->differs(), 'MyCS\'s own unsuspend is open: already to handle');

        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)->filterTable('differs', true)
            ->assertCanSeeTableRecords([$row('alpha')])->assertCanNotSeeTableRecords([$row('sub1'), $row('client1')]);
    }

    /** Review of «Differs»: a service suspended while the server was being read is newer than the read, never flagged. */
    public function test_a_service_changed_while_the_server_is_read_is_not_flagged(): void
    {
        $alpha = $this->service('alpha');
        $this->whileListing = function () use ($alpha) {
            $this->travel(5)->seconds();
            $alpha->update(['status' => 'suspended']); // MyCS's suspend, as the server still lists it active
            $this->travel(5)->seconds();
        };
        AccountSync::sync($this->server);
        $this->assertNull(CpanelAccount::query()->where('user', 'alpha')->sole()->differs(), 'read stamped as it began');

        // Round 2: in the read's own second too (timestamps kept to the second).
        $this->travel(1)->minutes();
        $alpha->update(['status' => 'active']);
        $this->travel(1)->minutes();
        $this->whileListing = fn () => $alpha->update(['status' => 'suspended']);
        AccountSync::sync($this->server);
        $this->assertNull(CpanelAccount::query()->where('user', 'alpha')->sole()->differs(), 'changed in the read\'s own second');
    }
}
