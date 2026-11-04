<?php

namespace Tests\Feature;

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
class CpanelAccountCheckReviewTest extends TestCase
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

    private function account(string $user): CpanelAccount
    {
        return CpanelAccount::query()->where('user', $user)->sole();
    }

    /** R1: the sync gives the account to a new client's service (the old one ended): the old client's temporary extra goes with it. */
    public function test_review_a_sync_remap_to_another_service_forgets_the_temporary_extra(): void
    {
        $this->accounts = [$this->acct('reuse', 'Starter', '30720M')];
        $old = $this->service('reuse');
        AccountSync::sync($this->server);
        $this->account('reuse')->forceFill(['temporary_extra_mb' => 10240, 'temporary_extra_until' => today()->addMonth(), 'temporary_extra_note' => 'old client'])->save();
        AccountSync::sync($this->server);
        $this->assertSame(AccountCheck::TEMPORARY_EXTRA, $this->check('reuse'));

        $old->update(['status' => Service::STATUS_TERMINATED]);
        $new = $this->service('reuse');
        AccountSync::sync($this->server);
        $a = $this->account('reuse');
        $this->assertSame($new->id, $a->service_id, 'remapped by the sync');
        $this->assertNull($a->temporary_extra_mb, 'said of the old client (remap() forgets it, the sync does not)');
    }

    /** R2: a free extra approved, then a temporary extra recorded before it is given in WHM: the approval is dropped at once. */
    public function test_review_recording_a_temporary_extra_keeps_an_approved_free_extra(): void
    {
        $this->accounts = [$this->acct('friend', 'Starter', '30720M')];
        $this->service('friend');
        AccountSync::sync($this->server);
        AccountCheck::approve($a = $this->account('friend'), 'free 10 GB', null);
        $a->save();
        $this->assertSame(AccountCheck::APPROVED, $this->check('friend'));

        $a = $this->account('friend');
        $a->forceFill(['temporary_extra_mb' => 10240, 'temporary_extra_until' => today()->addWeek()]);
        AccountCheck::apply($a);
        $a->save();
        $this->assertNotNull($this->account('friend')->approved_check, 'nothing about the account changed, only a temporary extra was noted');
    }

    /** R3: a free extra approved on top of a temporary one; the temporary one ends and is taken back: the approved extra is «to take back» for ever. */
    public function test_review_an_approved_extra_beside_an_ended_temporary_extra_is_not_to_take_back_once_the_temporary_one_is_taken_back(): void
    {
        $this->accounts = [$this->acct('both', 'Starter', '30720M')];
        $this->service('both');
        AccountSync::sync($this->server);
        $this->account('both')->forceFill(['temporary_extra_mb' => 10240, 'temporary_extra_until' => today()->addDays(3)])->save();
        $this->accounts = [$this->acct('both', 'Starter', '40960M')];
        AccountSync::sync($this->server);
        $this->assertSame(AccountCheck::EXTRA_NOT_BILLED, $this->check('both'));
        AccountCheck::approve($a = $this->account('both'), 'and 10 GB free', null);
        $a->save();
        // Both hold; the temporary one, with its date, is what the list shows.
        $this->assertSame(AccountCheck::TEMPORARY_EXTRA, $this->check('both'));

        $this->travel(4)->days();
        AccountSync::sync($this->server);
        $this->assertSame(AccountCheck::EXTRA_TO_TAKE_BACK, $this->check('both'));
        // Staff take back the 10 GB lent in WHM, the free 10 GB stays.
        $this->accounts = [$this->acct('both', 'Starter', '30720M')];
        AccountSync::sync($this->server);
        $a = $this->account('both');
        $this->assertNotSame(AccountCheck::EXTRA_TO_TAKE_BACK, $a->limits_check, 'the lent extra was taken back; what is left is the approved one');
        $this->assertSame([AccountCheck::APPROVED, 10240, null], [$a->limits_check, $a->limits_check_mb, $a->temporary_extra_mb]);
    }

    /** R4: no disk limit on the server and a temporary extra that ended: the hover starts with an empty amount. */
    public function test_review_the_hover_of_an_extra_to_take_back_without_a_disk_limit_reads_well(): void
    {
        $this->accounts = [$this->acct('nolimit', 'Starter', 'unlimited')];
        $this->service('nolimit');
        AccountSync::sync($this->server);
        // No limit given for free (approved), and a temporary extra that ended: no limit is not the extra lent, but the reminder stays.
        AccountCheck::approve($a = $this->account('nolimit'), 'no limit, on purpose', null);
        $a->save();
        $this->account('nolimit')->forceFill(['temporary_extra_mb' => 10240, 'temporary_extra_until' => today()->subDay()])->save();
        AccountSync::sync($this->server);
        $a = $this->account('nolimit');
        $this->assertSame([AccountCheck::EXTRA_TO_TAKE_BACK, 10240], [$a->limits_check, $a->limits_check_mb]);
        $this->assertSame('10 GB of a temporary extra still there: it ended ' . today()->format('d/m/Y'), AccountCheck::explain($a));
    }
}
