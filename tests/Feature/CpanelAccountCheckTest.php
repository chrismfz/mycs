<?php

namespace Tests\Feature;

use App\Admin\Resources\CpanelAccountResource\Pages\ListCpanelAccounts;
use App\Models\CpanelAccount;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Cpanel\AccountCheck;
use App\Services\Cpanel\AccountSync;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hosting Accounts, the package and limits check (ROADMAP, Admin; owner 2026-11-03: «give me a little more space for a while»
 * is forgotten): an account against its package's disk and bandwidth and the extra disk we bill. Read-only.
 */
class CpanelAccountCheckTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $accounts = [];

    /** @var array<string, array<string, mixed>|null> package => WHM's getpkginfo «pkg»; null: the call fails */
    private array $packages = [];

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->server = Server::query()->create(['name' => 'Rigel', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->packages = [
            'Starter' => ['QUOTA' => '20480', 'BWLIMIT' => '102400'],
            'Starter-20GB' => ['QUOTA' => '20480', 'BWLIMIT' => '102400'],
            'resel1_Starter' => ['QUOTA' => '20480', 'BWLIMIT' => '102400'],
            'Unlimited' => ['QUOTA' => 'unlimited', 'BWLIMIT' => 'unlimited'],
        ];
        Http::fake(function (Request $request) {
            $call = (string) parse_url($request->url(), PHP_URL_PATH);
            $ok = fn (array $data = []) => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => $data]);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match (true) {
                str_ends_with($call, '/listaccts') => $ok(['acct' => $this->accounts]),
                str_ends_with($call, '/showbw') => $ok(['acct' => array_map(fn (array $a) => ['user' => strtolower($a['user']), 'totalbytes' => 1073741824, 'limit' => ($a['bw'] ?? 100) * 1073741824], $this->accounts)]),
                str_ends_with($call, '/listresellers') => $ok(['reseller' => ['resel1']]),
                str_ends_with($call, '/getpkginfo') => ($this->packages[$query['pkg'] ?? ''] ?? null) === null
                    ? Http::response(['metadata' => ['result' => 0, 'reason' => 'no such package']])
                    : $ok(['pkg' => $this->packages[$query['pkg']]]),
                default => Http::response(['metadata' => ['result' => 0, 'reason' => 'not here']]),
            };
        });
    }

    /** @return array<string, mixed> */
    private function acct(string $user, string $plan, string $disk, array $more = []): array
    {
        return $more + ['user' => $user, 'domain' => $user . '.test', 'owner' => 'root', 'plan' => $plan, 'diskused' => '1000M', 'disklimit' => $disk,
            'inodesused' => '10', 'inodeslimit' => '250000', 'suspended' => 0, 'backup' => 1, 'unix_startdate' => '1700000000'];
    }

    private function service(string $username, string $package = 'Starter', int|string|null $extraGb = null, string $status = 'active'): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
        Setting::query()->updateOrCreate(['settingable_type' => $product->product->getMorphClass(), 'settingable_id' => $product->product->id, 'key' => 'package'],
            ['value' => $package, 'type' => 'string', 'encrypted' => false]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'username' => $username]);
        if ($extraGb !== null) {
            $service->properties()->create(['key' => 'extra_disk_gb', 'name' => 'Extra disk space (GB)', 'value' => (string) $extraGb]);
        }

        return $service;
    }

    private function check(string $user): ?string
    {
        return CpanelAccount::query()->where('user', $user)->sole()->limits_check;
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['admin.servers.viewAny', 'admin.servers.update']])->id]);
    }

    public function test_the_package_limits_are_read_and_an_account_with_what_we_bill_is_ok(): void
    {
        $this->accounts = [$this->acct('exbakery', 'Starter', '20480M')];
        $this->service('exbakery');
        AccountSync::sync($this->server);

        $a = CpanelAccount::query()->where('user', 'exbakery')->sole();
        $this->assertSame([20480, 102400, AccountCheck::OK, null], [$a->plan_disk_mb, $a->plan_bandwidth_mb, $a->limits_check, $a->limits_check_mb]);
        $this->assertNotNull($a->limits_checked_at);
    }

    public function test_more_disk_than_the_package_and_what_we_bill_is_an_extra_not_billed(): void
    {
        $this->accounts = [$this->acct('studionord', 'Starter', '30720M')];
        $this->service('studionord');
        AccountSync::sync($this->server);

        $a = CpanelAccount::query()->where('user', 'studionord')->sole();
        $this->assertSame([AccountCheck::EXTRA_NOT_BILLED, 10240], [$a->limits_check, $a->limits_check_mb]);
        $this->assertSame('10 GB more disk than its package and what we bill', AccountCheck::explain($a));
    }

    public function test_an_extra_we_bill_is_expected_and_one_billed_but_not_applied_is_found(): void
    {
        $this->accounts = [$this->acct('greenleaf', 'Starter', '30720M'), $this->acct('riverside', 'Starter', '20480M')];
        $this->service('greenleaf', extraGb: '10');
        $this->service('riverside', extraGb: '+10GB');
        AccountSync::sync($this->server);

        $this->assertSame(AccountCheck::OK, $this->check('greenleaf'), '20 + 10 billed is 30');
        $riverside = CpanelAccount::query()->where('user', 'riverside')->sole();
        $this->assertSame([AccountCheck::BILLED_NOT_APPLIED, -10240], [$riverside->limits_check, $riverside->limits_check_mb]);
    }

    public function test_less_than_the_package_with_nothing_billed_is_below_the_package(): void
    {
        $this->accounts = [$this->acct('smallone', 'Starter', '10240M')];
        $this->service('smallone');
        AccountSync::sync($this->server);

        $this->assertSame(AccountCheck::BELOW_PACKAGE, $this->check('smallone'));
    }

    public function test_a_difference_under_the_tolerance_is_rounding(): void
    {
        $this->accounts = [$this->acct('rounded', 'Starter', '20530M')];
        $this->service('rounded');
        AccountSync::sync($this->server);

        $this->assertSame(AccountCheck::OK, $this->check('rounded'), '50 MB over is not a finding');
    }

    public function test_another_package_on_the_server_than_the_services_is_found_and_a_resellers_prefix_is_not(): void
    {
        $this->accounts = [$this->acct('northwind', 'Starter-20GB', '20480M'), $this->acct('prefixed', 'resel1_Starter', '20480M')];
        $this->service('northwind');
        $this->service('prefixed');
        AccountSync::sync($this->server);

        $this->assertSame(AccountCheck::PLAN_DIFFERS, $this->check('northwind'), '«MyIP_Starter-20GB» for «MyIP_Starter»');
        $this->assertSame(AccountCheck::OK, $this->check('prefixed'), 'a reseller\'s package is stored without its prefix');
    }

    public function test_a_service_own_package_property_wins_over_the_products(): void
    {
        $this->accounts = [$this->acct('optioned', 'Starter-20GB', '20480M')];
        $this->service('optioned', 'Starter')->properties()->create(['key' => 'package', 'name' => 'Package', 'value' => 'Starter-20GB']);
        AccountSync::sync($this->server);

        $this->assertSame(AccountCheck::OK, $this->check('optioned'));
    }

    public function test_a_different_bandwidth_limit_is_found(): void
    {
        $this->accounts = [$this->acct('bwone', 'Starter', '20480M', ['bw' => 200])];
        $this->service('bwone');
        AccountSync::sync($this->server);

        $this->assertSame(AccountCheck::BANDWIDTH_DIFFERS, $this->check('bwone'));
    }

    public function test_the_worst_finding_is_the_one_kept(): void
    {
        $this->accounts = [$this->acct('both', 'Starter-20GB', '30720M', ['bw' => 200])];
        $this->service('both');
        AccountSync::sync($this->server);

        $this->assertSame(AccountCheck::EXTRA_NOT_BILLED, $this->check('both'), 'disk before package before bandwidth');
    }

    public function test_a_package_without_a_disk_limit_is_not_checked_and_no_limit_on_the_server_for_one_with_a_limit_is_an_extra(): void
    {
        $this->accounts = [$this->acct('unl', 'Unlimited', 'unlimited', ['bw' => 0]), $this->acct('free', 'Starter', 'unlimited')];
        $this->service('unl', 'Unlimited');
        $this->service('free');
        AccountSync::sync($this->server);

        $this->assertSame(AccountCheck::NOT_CHECKED, $this->check('unl'));
        $free = CpanelAccount::query()->where('user', 'free')->sole();
        $this->assertSame([AccountCheck::EXTRA_NOT_BILLED, null], [$free->limits_check, $free->limits_check_mb]);
        $this->assertSame('No disk limit on the server, the package has one', AccountCheck::explain($free));
    }

    public function test_nothing_is_checked_for_an_account_with_no_live_service_or_whose_package_was_not_read(): void
    {
        $this->accounts = [$this->acct('nomap', 'Starter', '30720M'), $this->acct('inner', 'Starter', '30720M'), $this->acct('ended', 'Starter', '30720M'),
            $this->acct('mystery', 'NoSuchPackage', '30720M')];
        $this->service('ended', status: 'terminated');
        $this->service('mystery');
        AccountSync::sync($this->server);
        CpanelAccount::query()->where('user', 'inner')->update(['internal' => true]);
        AccountSync::sync($this->server);

        foreach (['nomap', 'inner', 'ended', 'mystery'] as $user) {
            $this->assertNull($this->check($user), $user);
        }
        $this->assertNull(CpanelAccount::query()->where('user', 'mystery')->value('plan_disk_mb'), 'a package WHM would not tell is unknown, not unlimited');
    }

    public function test_an_account_gone_from_the_server_loses_its_check(): void
    {
        $this->accounts = [$this->acct('leaving', 'Starter', '30720M'), $this->acct('staying', 'Starter', '20480M')];
        $this->service('leaving');
        $this->service('staying');
        AccountSync::sync($this->server);
        $this->assertSame(AccountCheck::EXTRA_NOT_BILLED, $this->check('leaving'));

        $this->accounts = [$this->acct('staying', 'Starter', '20480M')];
        AccountSync::sync($this->server);

        $this->assertNull($this->check('leaving'));
    }

    public function test_the_list_filters_on_the_check_and_mapping_it_to_a_service_checks_it_at_once(): void
    {
        $this->accounts = [$this->acct('exbakery', 'Starter', '20480M'), $this->acct('studionord', 'Starter', '30720M'), $this->acct('floating', 'Starter', '30720M')];
        $this->service('exbakery');
        $this->service('studionord');
        AccountSync::sync($this->server);
        $ok = CpanelAccount::query()->where('user', 'exbakery')->sole();
        $extra = CpanelAccount::query()->where('user', 'studionord')->sole();
        $floating = CpanelAccount::query()->where('user', 'floating')->sole();

        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)->filterTable('limits_check', 'attention')
            ->assertCanSeeTableRecords([$extra])->assertCanNotSeeTableRecords([$ok, $floating]);
        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)->filterTable('limits_check', AccountCheck::OK)
            ->assertCanSeeTableRecords([$ok])->assertCanNotSeeTableRecords([$extra]);

        $this->assertNull($floating->limits_check, 'not mapped: nothing to compare');
        $service = $this->service('floating');
        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)->callTableAction('assign', $floating, data: ['service_id' => $service->id])->assertHasNoTableActionErrors();
        $this->assertSame(AccountCheck::EXTRA_NOT_BILLED, $floating->fresh()->limits_check);

        $this->livewireAs($this->staff())->test(ListCpanelAccounts::class)->callTableAction('internal', $floating, data: ['note' => 'our site'])->assertHasNoTableActionErrors();
        $this->assertNull($floating->fresh()->limits_check, 'internal: not ours to bill');
    }
}
