<?php

namespace Tests\Feature;

use App\Admin\Resources\CpanelAccountResource;
use App\Admin\Resources\CpanelAccountResource\Pages\ListCpanelAccounts;
use App\Admin\Resources\ServiceResource;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\CpanelAccount;
use App\Models\CpanelLveSample;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Cpanel\LveSync;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CloudLinux's limits (owner 2026-10-28, stage 3): what each account uses of them, read through WHM as cPanel's
 * ResourceUsage tells it (read-only), kept two days; the peak of the last 24 hours on Hosting Accounts and the service's
 * pages, with «near its limit».
 */
class CpanelLveTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    /** @var array<string, list<array<string, mixed>>|null> get_usages's rows by account; null: it fails */
    private array $usages = [];

    /** @var list<string> the accounts asked */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->server = Server::query()->create(['name' => 'Rigel', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key', 'protected_accounts' => 'ours'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->usages = [
            'alpha' => self::rows(cpu: 95, memory: 300 * 1048576, memoryLimit: 1024 * 1048576, ep: 3, io: 2048 * 1024),
            'beta' => self::rows(cpu: 2, memory: 50 * 1048576, memoryLimit: 1024 * 1048576, ep: 1, io: 0),
        ];
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (!str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/json-api/cpanel') || ($query['cpanel_jsonapi_func'] ?? '') !== 'get_usages') {
                return Http::response(['metadata' => ['result' => 0, 'reason' => 'not here']]);
            }
            $user = (string) ($query['cpanel_jsonapi_user'] ?? '');
            $this->asked[] = $user;
            $rows = $this->usages[$user] ?? null;

            return $rows === null ? Http::response(['result' => ['status' => 0, 'errors' => ['no such user'], 'data' => null]])
                : Http::response(['result' => ['status' => 1, 'errors' => null, 'data' => $rows]]);
        });
        foreach (['alpha', 'beta', 'ours', 'paused'] as $user) {
            CpanelAccount::query()->create(['server_id' => $this->server->id, 'user' => $user, 'suspended' => $user === 'paused', 'last_seen_at' => now()]);
        }
        CpanelAccount::query()->create(['server_id' => $this->server->id, 'user' => 'left', 'gone_at' => now()]);
    }

    /** @return list<array<string, mixed>> get_usages as rigel answers it (read 2026-10-28) */
    private static function rows(int $cpu, int $memory, int $memoryLimit, int $ep, int $io): array
    {
        return [
            ['id' => 'disk_usage', 'usage' => 1048918816, 'maximum' => 1610612736],
            ['id' => 'lvecpu', 'usage' => $cpu, 'maximum' => 100],
            ['id' => 'lveep', 'usage' => $ep, 'maximum' => 70],
            ['id' => 'lvememphy', 'usage' => $memory, 'maximum' => $memoryLimit],
            ['id' => 'lveiops', 'usage' => 0, 'maximum' => 1024],
            ['id' => 'lveio', 'usage' => $io, 'maximum' => 41943040],
            ['id' => 'lvenproc', 'usage' => 2, 'maximum' => 100],
        ];
    }

    private function account(string $user): CpanelAccount
    {
        return CpanelAccount::query()->where('user', $user)->sole();
    }

    public function test_each_live_accounts_use_and_limits_are_read_never_a_protected_suspended_or_gone_one(): void
    {
        $this->assertSame(['read' => 2, 'cloudlinux' => true], LveSync::sync($this->server));

        $this->assertSame(['alpha', 'beta'], $this->asked, 'not ours (protected), the suspended or the gone');
        $alpha = $this->account('alpha');
        $this->assertSame(1024, $alpha->lve_memory_limit_mb);
        $this->assertSame(70, $alpha->lve_entry_processes_limit);
        $this->assertSame(40960, $alpha->lve_io_limit_kbs);
        $this->assertSame(1024, $alpha->lve_iops_limit);
        $sample = $alpha->lveSamples()->sole();
        $this->assertSame([95, 300, 3, 2, 2048, 0], [$sample->cpu, $sample->memory_mb, $sample->entry_processes, $sample->processes, $sample->io_kbs, $sample->iops]);
        $this->assertSame(['cpu'], $alpha->lveNear());
        $this->assertSame([], $this->account('beta')->lveNear());
    }

    public function test_the_peak_is_of_the_last_24_hours_and_readings_are_kept_two_days(): void
    {
        $alpha = $this->account('alpha');
        $alpha->update(['lve_read_at' => now(), 'lve_memory_limit_mb' => 1024]);
        $old = CpanelLveSample::query()->create(['cpanel_account_id' => $alpha->id, 'sampled_at' => now()->subHours(30), 'cpu' => 100, 'memory_mb' => 1000]);
        $gone = CpanelLveSample::query()->create(['cpanel_account_id' => $alpha->id, 'sampled_at' => now()->subHours(50), 'cpu' => 100]);
        CpanelLveSample::query()->create(['cpanel_account_id' => $alpha->id, 'sampled_at' => now()->subHours(5), 'cpu' => 40, 'memory_mb' => 600]);
        $this->usages['alpha'] = self::rows(cpu: 10, memory: 200 * 1048576, memoryLimit: 1024 * 1048576, ep: 1, io: 0);

        LveSync::sync($this->server);
        LveSync::prune();

        $lve = $this->account('alpha')->lve();
        $this->assertSame(['peak' => 40, 'now' => 10, 'limit' => 100], $lve['cpu'], 'the 30-hour-old 100% is not today\'s');
        $this->assertSame(['peak' => 600, 'now' => 200, 'limit' => 1024], $lve['memory']);
        $this->assertNotNull($old->fresh(), 'kept two days');
        $this->assertNull($gone->fresh(), 'older ones go');
    }

    public function test_a_server_without_cloudlinux_is_told_and_one_account_failing_leaves_the_rest(): void
    {
        $this->usages = ['alpha' => [['id' => 'disk_usage', 'usage' => 1, 'maximum' => 2]], 'beta' => [['id' => 'disk_usage', 'usage' => 1, 'maximum' => 2]]];
        $this->assertSame(['read' => 0, 'cloudlinux' => false], LveSync::sync($this->server));
        $this->assertSame(0, CpanelLveSample::query()->count());

        $this->usages = ['beta' => self::rows(1, 1, 1, 1, 1)]; // alpha's call fails
        $this->assertSame(['read' => 1, 'cloudlinux' => true], LveSync::sync($this->server));

        $this->usages = [];
        $this->expectException(\Throwable::class);
        LveSync::sync($this->server); // none could be read: told, not taken for «no CloudLinux»
    }

    /** Review of #497: an account without CloudLinux's rows (excluded from LVE), first or in the middle, never drops the others'. */
    public function test_an_account_without_them_first_or_between_leaves_the_others_read(): void
    {
        foreach (['gamma', 'delta'] as $user) {
            CpanelAccount::query()->create(['server_id' => $this->server->id, 'user' => $user, 'last_seen_at' => now()]);
        }
        $none = [['id' => 'disk_usage', 'usage' => 1, 'maximum' => 2]];
        $this->usages['alpha'] = $none; // first asked
        $this->usages['delta'] = $none; // between
        $this->usages['gamma'] = self::rows(5, 1048576, 1073741824, 1, 0);

        $this->assertSame(['read' => 2, 'cloudlinux' => true], LveSync::sync($this->server));
        $this->assertSame(['beta', 'gamma'], CpanelAccount::query()->whereNotNull('lve_read_at')->orderBy('user')->pluck('user')->all());
    }

    /** Review of #497: a server failing every call stops after a few; nothing to read is not «no CloudLinux». */
    public function test_a_failing_server_stops_early_and_nothing_to_read_is_said(): void
    {
        foreach (['c1', 'c2', 'c3', 'c4', 'c5'] as $user) {
            CpanelAccount::query()->create(['server_id' => $this->server->id, 'user' => $user, 'last_seen_at' => now()]);
        }
        $this->usages = [];
        try {
            LveSync::sync($this->server);
            $this->fail('a server that fails every call is told');
        } catch (\Throwable) {
        }
        $this->assertCount(3, $this->asked, 'stopped after three failures in a row');

        CpanelAccount::query()->where('user', '!=', 'ours')->update(['suspended' => true]);
        $this->assertSame(['read' => 0, 'cloudlinux' => null], LveSync::sync($this->server), 'only a protected account live');
    }

    /** Review of #497: old readings go for every server; a suspended account, or a reading hours old, is not shown as now. */
    public function test_old_readings_go_everywhere_and_stale_or_suspended_is_not_shown(): void
    {
        $other = Server::query()->create(['name' => 'Off', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => false]);
        $offAccount = CpanelAccount::query()->create(['server_id' => $other->id, 'user' => 'x']);
        $old = CpanelLveSample::query()->create(['cpanel_account_id' => $offAccount->id, 'sampled_at' => now()->subHours(50), 'cpu' => 1]);
        $this->artisan('cpanel:lve')->assertExitCode(0);
        $this->assertNull($old->fresh(), 'a disabled server\'s old readings go too');

        $beta = $this->account('beta');
        CpanelLveSample::query()->where('cpanel_account_id', $beta->id)->update(['sampled_at' => now()->subHours(5)]);
        $this->assertNull($beta->fresh()->lve()['cpu']['now'], 'five hours old: not now');
        $this->assertSame(2, $beta->fresh()->lve()['cpu']['peak']);

        $alpha = $this->account('alpha');
        $this->assertSame(['cpu'], $alpha->lveNear());
        $alpha->update(['suspended' => true]);
        $this->assertSame([], $alpha->fresh()->lve(), 'suspended: not read, its readings not shown');
    }

    public function test_hosting_accounts_show_the_peaks_cut_the_package_and_filter_the_ones_near(): void
    {
        LveSync::sync($this->server);
        $alpha = $this->account('alpha');
        $alpha->update(['plan' => 'toplevel_MyWebsites_PresentationClients-1gbDiskSpace-30gbBW']);
        $beta = $this->account('beta');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['admin.servers.viewAny']])->id]);

        $this->livewireAs($staff)->test(ListCpanelAccounts::class)
            ->assertCanSeeTableRecords([$alpha, $beta])
            ->assertSee('CPU 95% · RAM 29%')
            ->assertSee('toplevel_MyWebsites_Pres...')
            ->assertDontSee('toplevel_MyWebsites_PresentationClients-1gbDiskSpace-30gbBW</')
            ->filterTable('lve_near', true)->assertCanSeeTableRecords([$alpha])->assertCanNotSeeTableRecords([$beta]);

        $loaded = CpanelAccount::query()->whereKey($alpha->id)->tap(fn ($q) => CpanelAccount::withLvePeaks($q))->sole();
        $this->assertStringContainsString('CPU: 95% of its limit · RAM: 300.0 MB / 1.0 GB', (string) CpanelAccountResource::lveDetail($loaded));
        $this->assertSame('—', CpanelAccountResource::lveSummary($this->account('paused')));
    }

    public function test_the_service_pages_show_the_peaks_and_near_a_limit(): void
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'username' => 'alpha']);
        $this->account('alpha')->update(['service_id' => $service->id]);
        $service->usage()->create(['disk_used_mb' => 100, 'disk_limit_mb' => 10000, 'read_at' => now()]);
        LveSync::sync($this->server);

        $this->livewireAs($service->client->users()->first())->test(ServiceShow::class, ['service' => $service->fresh()])
            ->assertSee(__('services.usage.lve_title'))->assertSee(__('services.usage.lve.entry_processes'))
            ->assertSee(__('services.usage.lve_cpu_of', ['percent' => '95%']))
            ->assertSee(__('services.usage.near', ['limits' => __('services.usage.limits.cpu')]));

        $this->assertContains('Near its CloudLinux cpu limit (24h)', ServiceResource::summaryLine($service->fresh())['serverState']);

        // A reseller's own account's limits are not its accounts': none shown.
        $this->account('alpha')->update(['reseller' => true]);
        $this->livewireAs($service->client->users()->first())->test(ServiceShow::class, ['service' => $service->fresh()])
            ->assertDontSee(__('services.usage.lve_title'));
    }
}
