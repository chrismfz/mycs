<?php

namespace Tests\Feature;

use App\Admin\Resources\CpanelAccountResource\Pages\ListCpanelAccounts;
use App\Admin\Resources\ServiceResource;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\CpanelAccount;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Cpanel\AccountSync;
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
            ['user' => 'client1', 'domain' => 'client1.test', 'owner' => 'resel1', 'plan' => 'resel1_Small', 'diskused' => '1G', 'disklimit' => '2G'],
        ];
        Http::fake(function (Request $request) {
            if ($this->down) {
                return Http::failedConnection();
            }
            $call = (string) parse_url($request->url(), PHP_URL_PATH);
            $ok = fn (array $data = []) => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => $data]);

            return match (true) {
                str_ends_with($call, '/listaccts') => $ok(['acct' => $this->accounts]),
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

        $this->assertSame(['seen' => 3, 'new' => 3, 'gone' => 0], AccountSync::sync($this->server));

        $a = CpanelAccount::query()->where('user', 'alpha')->sole();
        $this->assertSame(['alpha.test', 'Business10', 9300, 10000, 120000, 250000, 5120, 10240, true, false, 200, true, false, $alpha->id],
            [$a->domain, $a->plan, $a->disk_used_mb, $a->disk_limit_mb, $a->inodes_used, $a->inodes_limit, $a->bandwidth_used_mb, $a->bandwidth_limit_mb,
                $a->mail_held, $a->mail_suspended, $a->max_email_per_hour, $a->backup, $a->reseller, $a->service_id]);
        $this->assertSame(['disk'], $a->nearLimits());
        $r = CpanelAccount::query()->where('user', 'resel1')->sole();
        $this->assertSame([true, true, 'Overdue', null, null, 0, null], [$r->reseller, $r->suspended, $r->suspend_reason, $r->disk_limit_mb, $r->inodes_limit, $r->bandwidth_used_mb, $r->service_id]);
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
        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)->filterTable('mail', true)->assertCanSeeTableRecords([$alpha])->assertCanNotSeeTableRecords([$client]);
        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)->filterTable('owner', 'resel1')->assertCanSeeTableRecords([$client])->assertCanNotSeeTableRecords([$alpha]);
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
}
