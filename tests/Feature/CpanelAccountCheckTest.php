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
                // As WHM answers: a package it does not know is «Failed to load package», a login without the right is refused.
                str_ends_with($call, '/getpkginfo') => match (true) {
                    !array_key_exists($query['pkg'] ?? '', $this->packages) => Http::response(['metadata' => ['result' => 0,
                        'reason' => 'Failed to load package “' . ($query['pkg'] ?? '') . '”: No such file or directory']]),
                    $this->packages[$query['pkg']] === null => Http::response(['metadata' => ['result' => 0, 'reason' => 'Access denied']]),
                    default => $ok(['pkg' => $this->packages[$query['pkg']]]),
                },
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
        $this->packages['Premium_Starter'] = ['QUOTA' => '20480', 'BWLIMIT' => '102400'];
        $this->accounts = [$this->acct('northwind', 'Starter-20GB', '20480M'), $this->acct('prefixed', 'resel1_Starter', '20480M', ['owner' => 'resel1']),
            $this->acct('rootprefix', 'Premium_Starter', '20480M')];
        $this->service('northwind');
        $prefixed = $this->service('prefixed');
        $this->service('rootprefix');
        AccountSync::sync($this->server);
        // A reseller's customer's account is never mapped by its name alone: staff map it.
        $row = CpanelAccount::query()->where('user', 'prefixed')->sole();
        $row->update(['service_id' => $prefixed->id]);
        AccountCheck::apply($row);
        $row->save();

        $this->assertSame(AccountCheck::PLAN_DIFFERS, $this->check('northwind'), '«MyIP_Starter-20GB» for «MyIP_Starter»');
        $this->assertSame(AccountCheck::OK, $this->check('prefixed'), 'a reseller\'s package is stored without its prefix');
        $this->assertSame(AccountCheck::PLAN_DIFFERS, $this->check('rootprefix'), 'root\'s «Premium_Starter» is not «Starter»: only the owner\'s prefix is dropped');
    }

    public function test_a_package_gone_from_whm_does_not_stop_the_ones_after_it(): void
    {
        $this->accounts = [$this->acct('lost1', 'GoneA', '20480M'), $this->acct('lost2', 'GoneB', '20480M'), $this->acct('lost3', 'GoneC', '20480M'),
            $this->acct('studionord', 'Starter', '30720M')];
        foreach (['lost1', 'lost2', 'lost3'] as $user) {
            $this->service($user);
        }
        $this->service('studionord');
        AccountSync::sync($this->server);

        $this->assertNull($this->check('lost1'));
        $this->assertSame(AccountCheck::EXTRA_NOT_BILLED, $this->check('studionord'), 'the package after three that WHM does not know is still read');
    }

    public function test_a_login_that_cannot_read_any_package_asks_only_a_few_times(): void
    {
        $this->packages = array_fill_keys(array_map(fn (int $i) => "Plan{$i}", range(1, 6)), null);
        $this->accounts = array_map(fn (int $i) => $this->acct("acc{$i}", "Plan{$i}", '20480M'), range(1, 6));
        AccountSync::sync($this->server);

        Http::assertSentCount(1 + 1 + 1 + 3, 'listaccts, showbw, listresellers and three getpkginfo, then no more');
        $this->assertNull(CpanelAccount::query()->whereNotNull('plan_disk_mb')->first());
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

    public function test_a_package_with_no_bandwidth_key_is_unlimited_and_matches_an_account_with_no_bandwidth_limit(): void
    {
        $this->packages['V16'] = ['QUOTA' => '16384', 'BWLIMIT' => null]; // as the live servers say it
        $this->packages['V17'] = ['QUOTA' => '16384'];
        $this->accounts = [$this->acct('nopulsar', 'V16', '16384M', ['bw' => 0]), $this->acct('nokey', 'V17', '16384M', ['bw' => 0])];
        $this->service('nopulsar', 'V16');
        $this->service('nokey', 'V17');
        AccountSync::sync($this->server);

        foreach (['nopulsar', 'nokey'] as $user) {
            $a = CpanelAccount::query()->where('user', $user)->sole();
            $this->assertSame([16384, 0, AccountCheck::OK], [$a->plan_disk_mb, $a->plan_bandwidth_mb, $a->limits_check], $user);
        }
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

    private function account(string $user): CpanelAccount
    {
        return CpanelAccount::query()->where('user', $user)->sole();
    }

    /** «A little more space for a while» (owner 2026-11-04): expected until its date, then a reminder, forgotten once taken back. */
    public function test_a_temporary_extra_is_expected_until_its_date_then_is_to_take_back_and_is_forgotten_once_taken_back(): void
    {
        $this->accounts = [$this->acct('lent', 'Starter', '30720M')];
        $this->service('lent');
        AccountSync::sync($this->server);
        $this->account('lent')->forceFill(['temporary_extra_mb' => 10240, 'temporary_extra_until' => today()->addDays(7), 'temporary_extra_note' => 'moving a site'])->save();
        AccountSync::sync($this->server);

        $a = $this->account('lent');
        $this->assertSame([AccountCheck::TEMPORARY_EXTRA, null], [$a->limits_check, $a->limits_check_mb]);
        $this->assertSame('+10 GB until ' . today()->addDays(7)->format('d/m/Y') . ' · moving a site', AccountCheck::explain($a));

        $this->travel(7)->days();
        AccountSync::sync($this->server);
        $this->assertSame(AccountCheck::TEMPORARY_EXTRA, $this->check('lent'), 'its last day still counts');

        $this->travel(1)->days();
        AccountSync::sync($this->server);
        $a = $this->account('lent');
        $this->assertSame([AccountCheck::EXTRA_TO_TAKE_BACK, 10240], [$a->limits_check, $a->limits_check_mb]);
        $this->assertSame('10 GB of a temporary extra still there: it ended ' . today()->format('d/m/Y') . ' · moving a site', AccountCheck::explain($a));

        $this->accounts = [$this->acct('lent', 'Starter', '20480M')];
        AccountSync::sync($this->server);
        $a = $this->account('lent');
        $this->assertSame([AccountCheck::OK, null, null], [$a->limits_check, $a->temporary_extra_mb, $a->temporary_extra_until], 'taken back: nothing left to remind');
    }

    public function test_more_than_the_temporary_extra_is_still_an_extra_not_billed_and_one_not_given_yet_is_ok(): void
    {
        $this->accounts = [$this->acct('greedy', 'Starter', '40960M'), $this->acct('notyet', 'Starter', '20480M')];
        $this->service('greedy');
        $this->service('notyet');
        AccountSync::sync($this->server);
        CpanelAccount::query()->update(['temporary_extra_mb' => 10240, 'temporary_extra_until' => today()->addMonth()]);
        AccountSync::sync($this->server);

        $greedy = $this->account('greedy');
        $this->assertSame([AccountCheck::EXTRA_NOT_BILLED, 10240], [$greedy->limits_check, $greedy->limits_check_mb], '30 GB more, 10 of them lent');
        $this->assertSame(AccountCheck::OK, $this->check('notyet'));
    }

    /** Approved with a note (owner 2026-11-04): quiet while nothing it was given on changes, then back and the approval dropped. */
    public function test_an_approved_finding_is_quiet_until_the_account_changes(): void
    {
        $this->accounts = [$this->acct('friend', 'Starter', '30720M')];
        $this->service('friend');
        AccountSync::sync($this->server);
        $staff = $this->staff();

        $this->livewireAs($staff)->test(ListCpanelAccounts::class)->callTableAction('approve', $this->account('friend'), data: ['note' => 'free extra, a good client'])
            ->assertHasNoTableActionErrors();
        $a = $this->account('friend');
        $this->assertSame([AccountCheck::APPROVED, 10240, AccountCheck::EXTRA_NOT_BILLED, $staff->id], [$a->limits_check, $a->limits_check_mb, $a->approved_check, $a->approved_by]);
        $this->assertStringContainsString('Extra not billed 10 GB · free extra, a good client', (string) AccountCheck::explain($a));

        AccountSync::sync($this->server);
        $this->assertSame(AccountCheck::APPROVED, $this->check('friend'), 'the next read, the same account');

        $this->accounts = [$this->acct('friend', 'Starter', '40960M')];
        AccountSync::sync($this->server);
        $a = $this->account('friend');
        $this->assertSame([AccountCheck::EXTRA_NOT_BILLED, 10240, AccountCheck::EXTRA_NOT_BILLED], [$a->limits_check, $a->limits_check_mb, $a->approved_check],
            'more again: the 10 GB more is new, the free 10 GB still approved');

        $this->accounts = [$this->acct('friend', 'Starter', '25600M')];
        AccountSync::sync($this->server);
        $a = $this->account('friend');
        $this->assertSame([AccountCheck::EXTRA_NOT_BILLED, 5120, null, null], [$a->limits_check, $a->limits_check_mb, $a->approved_check, $a->approved_state],
            'the free extra partly taken back: what is left is something new to look at');
    }

    public function test_an_approval_does_not_hide_another_finding_and_what_we_bill_changing_drops_it(): void
    {
        $this->accounts = [$this->acct('both', 'Starter-20GB', '20480M', ['bw' => 200])];
        $service = $this->service('both');
        AccountSync::sync($this->server);
        $this->assertSame(AccountCheck::PLAN_DIFFERS, $this->check('both'));
        AccountCheck::approve($a = $this->account('both'), 'on purpose', null);
        $a->save();

        $this->assertSame(AccountCheck::BANDWIDTH_DIFFERS, $this->check('both'), 'the package approved, the bandwidth still found');

        $service->properties()->create(['key' => 'extra_disk_gb', 'name' => 'Extra disk space (GB)', 'value' => '10']);
        AccountSync::sync($this->server);
        $this->assertNull($this->account('both')->approved_check, 'we bill something else now');
        $this->assertSame(AccountCheck::BILLED_NOT_APPLIED, $this->check('both'));
    }

    public function test_staff_give_and_clear_a_temporary_extra_and_withdraw_an_approval_from_the_list_and_another_mapping_forgets_them(): void
    {
        $this->accounts = [$this->acct('lent', 'Starter', '30720M')];
        $this->service('lent');
        AccountSync::sync($this->server);
        $staff = $this->staff();
        $list = fn () => $this->livewireAs($staff)->test(ListCpanelAccounts::class);

        $list()->callTableAction('temporary_extra', $this->account('lent'), data: ['gb' => 10, 'until' => today()->addWeek()->toDateString(), 'note' => 'a move'])
            ->assertHasNoTableActionErrors();
        $a = $this->account('lent');
        $this->assertSame([AccountCheck::TEMPORARY_EXTRA, 10240, $staff->id], [$a->limits_check, $a->temporary_extra_mb, $a->temporary_extra_by]);
        $list()->callTableAction('temporary_extra', $a, data: ['gb' => 10, 'until' => today()->subDay()->toDateString()])->assertHasTableActionErrors(['until']);

        $list()->callTableAction('clear_temporary_extra', $a)->assertHasNoTableActionErrors();
        $this->assertSame([AccountCheck::EXTRA_NOT_BILLED, null], [$this->check('lent'), $this->account('lent')->temporary_extra_mb]);

        $list()->callTableAction('approve', $this->account('lent'), data: ['note' => 'ok'])->assertHasNoTableActionErrors();
        $list()->callTableAction('unapprove', $this->account('lent'))->assertHasNoTableActionErrors();
        $this->assertSame([AccountCheck::EXTRA_NOT_BILLED, null], [$this->check('lent'), $this->account('lent')->approved_check]);

        $list()->callTableAction('approve', $this->account('lent'), data: ['note' => 'ok'])->assertHasNoTableActionErrors();
        $this->account('lent')->forceFill(['temporary_extra_mb' => 1024, 'temporary_extra_until' => today()->addWeek()])->save();
        $other = $this->service('lent2');
        $list()->callTableAction('assign', $this->account('lent'), data: ['service_id' => $other->id])->assertHasNoTableActionErrors();
        $a = $this->account('lent');
        $this->assertSame([null, null, AccountCheck::EXTRA_NOT_BILLED], [$a->approved_check, $a->temporary_extra_mb, $a->limits_check], 'said of the other client');
    }
}
