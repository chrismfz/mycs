<?php

namespace Tests\Feature;

use App\Admin\Resources\LicenseResource\Pages\ListLicenses;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Models\License;
use App\Models\LicenseInstall;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Licensing\LicenseTelemetry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Nixpal\Licensing\Token;
use Tests\TestCase;

/**
 * POST /licensing/v1/check (docs/licensing.md, «Τηλεμετρία»): zlic.php's answer, from the same code, and what the
 * software says of itself, kept after the answer and never changing it.
 */
class LicensingCheckTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '198.51.100.7';

    private Service $service;

    private License $license;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD']);
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['key_prefix' => ['ZCAgent-', false], 'key_length' => ['16', false], 'secret_key' => ['TestSecret', true]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => $product->product->getMorphClass()])->save();
        }
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1,
            'expires_at' => now()->addMonth(), 'server_id' => $server->id]);
        ExtensionHelper::createServer($this->service);
        $this->license = License::query()->sole();
    }

    /** @param  array<string, mixed>  $telemetry */
    private function check(array $telemetry = [], ?string $key = null, string $ip = self::IP, string $psk = 'TestSecret'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/licensing/v1/check', ['license' => $key ?? $this->license->license_key, 'license_PSK' => $psk] + ($telemetry === [] ? [] : ['telemetry' => $telemetry]));
    }

    /** @return array<string, mixed> zlic's answer without what changes on every call (the check token's hash, the local key over it) */
    private static function steady(TestResponse $response): array
    {
        return array_diff_key((array) json_decode((string) $response->getContent(), true), ['md5hash' => 1, 'localkey' => 1]);
    }

    private function telemetry(array $overrides = []): array
    {
        return $overrides + ['product' => 'agent', 'version' => '2026.11.14.2129', 'build_time' => '2026-11-14T21:29:26', 'commit' => 'abc1234',
            'sha256' => str_repeat('ab', 32), 'install_id' => '0f8e2c1a-3b4d-4e5f-8a9b-0c1d2e3f4a5b', 'client_time' => now()->getTimestamp(),
            'os' => 'linux', 'arch' => 'amd64', 'licensed' => true, 'failures' => 0];
    }

    public function test_it_answers_as_zlic_php_for_json_and_forms_and_any_key(): void
    {
        $this->freezeTime();
        $v1 = $this->check($this->telemetry());
        $v1->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->assertSame('Active', $v1->json('status'));
        $this->assertNotEmpty($v1->json('localkey'));
        $zlic = $this->withServerVariables(['REMOTE_ADDR' => self::IP])->post('/zlic.php', ['license' => $this->license->license_key, 'license_PSK' => 'TestSecret']);
        $this->assertSame(self::steady($zlic), self::steady($v1));

        $form = $this->withServerVariables(['REMOTE_ADDR' => self::IP])->post('/licensing/v1/check', ['license' => $this->license->license_key, 'license_PSK' => 'TestSecret']);
        $this->assertSame(self::steady($zlic), self::steady($form));

        // Unknown, a wrong PSK, another address, a key with a blank (never trimmed, as zlic.php): the same answer, and
        // nothing kept from a caller that did not prove it holds the license (review R1).
        foreach ([['ZCAgent-nothere', 'TestSecret', self::IP], [$this->license->license_key, 'wrong', self::IP], [$this->license->license_key, 'TestSecret', '203.0.113.9'],
            [$this->license->license_key . ' ', 'TestSecret', self::IP]] as [$key, $psk, $ip]) {
            $this->assertSame(self::steady($this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/zlic.php', ['license' => $key, 'license_PSK' => $psk])),
                self::steady($this->check($this->telemetry(['install_id' => 'eeeeeeee-0000-4000-8000-00000000000' . strlen($psk)]), $key, $ip, $psk)), "{$key} / {$psk} / {$ip}");
        }
        // The two good checks above: with its telemetry, and the form's without.
        $this->assertSame([['Active', '0f8e2c1a-3b4d-4e5f-8a9b-0c1d2e3f4a5b'], ['Active', '']],
            LicenseInstall::query()->orderBy('id')->get()->map(fn (LicenseInstall $i) => [$i->last_status, $i->install_id])->all());
        $missing = $this->postJson('/licensing/v1/check', ['license' => $this->license->license_key]);
        $missing->assertStatus(400);
        $this->assertSame($this->post('/zlic.php', ['license' => $this->license->license_key])->getContent(), $missing->getContent());
        $this->get('/licensing/v1/check')->assertStatus(405);
    }

    public function test_it_keeps_what_the_install_says_and_sees_an_upgrade_and_a_downgrade(): void
    {
        Carbon::setTestNow('2026-12-10 12:00:00'); // after both builds: a build time in the future is left out
        $this->check($this->telemetry(['client_time' => now()->getTimestamp() + 3 * 86400]))->assertOk();

        $install = LicenseInstall::query()->sole();
        $this->assertSame([$this->license->id, self::IP, 'agent', '0f8e2c1a-3b4d-4e5f-8a9b-0c1d2e3f4a5b', '2026.11.14.2129', 'abc1234', str_repeat('ab', 32),
            'linux', 'amd64', 3 * 86400, true, 0, 'Active', 1, null, null],
            [$install->license_id, $install->ip, $install->product, $install->install_id, $install->version, $install->commit, $install->binary_sha256,
                $install->os, $install->arch, $install->clock_skew, $install->licensed, $install->failures, $install->last_status, $install->checks,
                $install->previous_version, $install->downgraded_at]);
        $this->assertSame('2026-11-14 21:29:26', $install->build_time->format('Y-m-d H:i:s'));

        $this->check($this->telemetry(['version' => '2026.12.01.0900', 'build_time' => '2026-12-01T09:00:00']));
        $install->refresh();
        $this->assertSame(['2026.12.01.0900', '2026.11.14.2129', 2, null, 0], [$install->version, $install->previous_version, $install->checks, $install->downgraded_at, $install->clock_skew]);

        // Back to the older build: a downgrade.
        $this->check($this->telemetry());
        $install->refresh();
        $this->assertSame(['2026.11.14.2129', '2026.12.01.0900', 3], [$install->version, $install->previous_version, $install->checks]);
        $this->assertTrue($install->downgraded_at->equalTo(now()));

        // A date with its offset is kept in UTC (review R2): 21:29+02:00 is 19:29Z, older than the build it runs.
        $this->check($this->telemetry(['build_time' => '2026-11-14T21:29:26+02:00']));
        $this->assertSame('2026-11-14 19:29:26', $install->fresh()->build_time->format('Y-m-d H:i:s'));

        // Another install of the same key, and a node on the same address: rows of their own.
        $this->check($this->telemetry(['install_id' => 'ffffffff-0000-4000-8000-000000000001']));
        $this->check($this->telemetry(['product' => 'node']));
        $this->assertSame(3, LicenseInstall::query()->count());
    }

    public function test_what_a_client_sends_wrong_is_left_out_and_never_changes_the_answer(): void
    {
        $this->freezeTime();
        foreach ([
            ['product' => ['agent'], 'version' => str_repeat('9', 41), 'build_time' => '2099-01-01T00:00:00', 'commit' => 'not-hex', 'sha256' => 'abc',
                'install_id' => 'short', 'client_time' => 'yesterday', 'os' => '<script>', 'arch' => str_repeat('x', 17), 'licensed' => 'yes', 'failures' => -1],
            ['product' => 'toaster', 'build_time' => '1999-01-01', 'client_time' => 99, 'failures' => 2_000_000],
        ] as $junk) {
            $answer = $this->check($junk);
            $answer->assertOk();
            $this->assertSame('Active', $answer->json('status'));
        }
        $this->check()->assertOk(); // no telemetry at all
        // A form's strings, a float clock (review R5).
        $this->check(['install_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'licensed' => 'false', 'failures' => '12', 'client_time' => now()->getTimestamp() + 7200.5]);
        $form = LicenseInstall::query()->where('install_id', 'aaaaaaaa-0000-4000-8000-000000000001')->sole();
        $this->assertSame([false, 12, 7200], [$form->licensed, $form->failures, $form->clock_skew]);
        $form->delete();
        $this->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->postJson('/licensing/v1/check', ['license' => $this->license->license_key, 'license_PSK' => 'TestSecret', 'telemetry' => 'a string'])->assertOk();

        $install = LicenseInstall::query()->sole();
        $this->assertSame(['', '', null, null, null, null, null, null, null, null, null, null, 4],
            [$install->product, $install->install_id, $install->version, $install->build_time, $install->commit, $install->binary_sha256,
                $install->os, $install->arch, $install->clock_skew, $install->licensed, $install->failures, $install->downgraded_at, $install->checks]);
    }

    public function test_an_unknown_key_leaves_nothing_and_a_failed_write_never_changes_the_answer(): void
    {
        $this->check($this->telemetry(), 'ZCAgent-nothere')->assertOk();
        $this->assertSame(0, LicenseInstall::query()->count());

        Schema::drop('license_installs');
        $answer = $this->check($this->telemetry());
        $answer->assertOk();
        $this->assertSame('Active', $answer->json('status'));
    }

    /** Review 2-1 of #863: «Expired» is signed to any address; only the license's own place may write what it runs. */
    public function test_an_ended_license_is_kept_only_from_where_it_runs(): void
    {
        $this->check($this->telemetry())->assertOk(); // binds it to IP
        $this->license->update(['status' => License::STATUS_EXPIRED]);

        $foreign = $this->check($this->telemetry(['install_id' => 'bbbbbbbb-0000-4000-8000-000000000001']), ip: '203.0.113.9');
        $this->assertSame('Expired', $foreign->json('status'), 'answered as zlic.php would');
        $this->check($this->telemetry(['install_id' => 'bbbbbbbb-0000-4000-8000-000000000002']));

        $this->assertSame([['0f8e2c1a-3b4d-4e5f-8a9b-0c1d2e3f4a5b', 'Active'], ['bbbbbbbb-0000-4000-8000-000000000002', 'Expired']],
            LicenseInstall::query()->orderBy('id')->get()->map(fn (LicenseInstall $i) => [$i->install_id, $i->last_status])->all());
    }

    /** Review 2-2: dates in the app's timezone, as Eloquent keeps them; a downgrade of less than the offset is still one. */
    public function test_dates_follow_the_app_timezone(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Athens');
        try {
            Carbon::setTestNow(Carbon::parse('2026-12-10 12:00:00', 'Europe/Athens'));
            $this->check($this->telemetry(['build_time' => '2026-11-14T21:29:26Z']))->assertOk();
            $install = LicenseInstall::query()->sole();
            $this->assertTrue($install->build_time->equalTo(Carbon::parse('2026-11-14T21:29:26Z')));

            $this->check($this->telemetry(['build_time' => '2026-11-14T20:29:26Z'])); // an hour older
            $this->assertNotNull($install->fresh()->downgraded_at);
        } finally {
            date_default_timezone_set($zone);
        }
    }

    /** Review 2-3: the cap counts the installs of the last days; old ones wait for the retention and block nothing. */
    public function test_the_cap_counts_recent_installs_only(): void
    {
        $this->freezeTime();
        $this->check()->assertOk(); // binds it
        $rows = fn (int $daysAgo, string $prefix) => array_map(fn ($n) => ['license_id' => $this->license->id, 'ip' => self::IP, 'product' => 'agent',
            'install_id' => $prefix . $n, 'checks' => 1, 'first_seen_at' => now()->subDays($daysAgo), 'last_seen_at' => now()->subDays($daysAgo)],
            range(1, LicenseTelemetry::MAX_INSTALLS));
        LicenseInstall::query()->insert($rows(LicenseTelemetry::MAX_INSTALLS_DAYS + 1, 'old-install-'));

        $this->check($this->telemetry(['install_id' => 'cccccccc-0000-4000-8000-000000000001']));
        $this->assertTrue(LicenseInstall::query()->where('install_id', 'cccccccc-0000-4000-8000-000000000001')->exists());

        LicenseInstall::query()->insert($rows(1, 'new-install-'));
        $this->check($this->telemetry(['install_id' => 'cccccccc-0000-4000-8000-000000000002']));
        $this->assertFalse(LicenseInstall::query()->where('install_id', 'cccccccc-0000-4000-8000-000000000002')->exists(), 'past the cap');
    }

    public function test_staff_see_the_installs_and_their_signals(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
        $this->freezeTime();
        $other = Service::factory()->create(['client_id' => $this->service->client_id, 'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1]);
        $quiet = License::query()->create(['service_id' => $other->id, 'license_key' => 'ZCAgent-00000000000000aa', 'status' => License::STATUS_ACTIVE, 'reissues' => 0]);
        $row = fn (License $license, array $values) => LicenseInstall::query()->create($values + ['license_id' => $license->id, 'ip' => self::IP,
            'product' => 'agent', 'install_id' => uniqid(), 'version' => '2026.11.14.2129', 'checks' => 1, 'first_seen_at' => now()->subMonth(), 'last_seen_at' => now()]);
        // One install: its clock a minute off, its address changed, and a node on the same key: nothing to see (review R3).
        $row($quiet, ['clock_skew' => 60, 'install_id' => 'install-a']);
        $row($quiet, ['ip' => '192.0.2.50', 'install_id' => 'install-a']);
        $row($quiet, ['product' => 'node', 'install_id' => 'install-n']);
        $row($quiet, ['install_id' => '']); // a check without telemetry

        $this->livewireAs($staff)->withQueryParams(['tab' => 'signals'])->test(ListLicenses::class)->assertCanNotSeeTableRecords([$this->license, $quiet]);

        $row($this->license, ['clock_skew' => -2 * 86400, 'downgraded_at' => now()->subDay(), 'previous_version' => '2026.12.01.0900']);
        $this->livewireAs($staff)->withQueryParams(['tab' => 'signals'])->test(ListLicenses::class)
            ->assertCanSeeTableRecords([$this->license])->assertCanNotSeeTableRecords([$quiet]);

        // A second install of a product of a standalone license in the last days is a sign too.
        $row($quiet, ['ip' => '203.0.113.9', 'install_id' => 'install-b']);
        $this->livewireAs($staff)->withQueryParams(['tab' => 'signals'])->test(ListLicenses::class)->assertCanSeeTableRecords([$quiet]);

        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->assertSee('agent 2026.11.14.2129')->assertSee('⚠ downgrade')->assertSee('(was 2026.12.01.0900)')->assertSee('⚠ clock -2d');
    }

    /** «Signed token»: with Active, for the caller's key and address, 30 days from the check. */
    public function test_an_active_answer_carries_a_token_the_clients_can_verify(): void
    {
        $pair = Token::keypair();
        config(['services.license_token.secret' => $pair['secret'], 'services.license_token.kid' => 'k20261115']);
        $keys = ['k20261115' => $pair['public']];
        Carbon::setTestNow('2026-11-15 12:00:00');
        $now = now()->getTimestamp();

        // A yearly license: 30 days, a new one at every check.
        $this->service->update(['expires_at' => now()->addYear()]);
        $answer = $this->check($this->telemetry());
        $claims = Token::verify($answer->json('token'), $keys, $now);
        $this->assertSame(['v' => 1, 'kid' => 'k20261115', 'key' => $this->license->license_key, 'install' => '0f8e2c1a-3b4d-4e5f-8a9b-0c1d2e3f4a5b', 'ip' => self::IP, 'status' => 'Active',
            'due' => '2027-11-15', 'iat' => $now, 'exp' => $now + 30 * 86400], $claims);
        // Everything else is zlic.php's.
        $zlic = $this->withServerVariables(['REMOTE_ADDR' => self::IP])->post('/zlic.php', ['license' => $this->license->license_key, 'license_PSK' => 'TestSecret']);
        $this->assertNull($zlic->json('token'), 'zlic.php never has one');
        $this->assertSame(self::steady($zlic), array_diff_key(self::steady($answer), ['token' => 1]));

        // Due in 5 days, or overdue and still Active (not suspended yet): 30 days all the same, the status decides
        // (owner 2026-11-15, option A; review: an expired token for an Active answer).
        foreach ([now()->addDays(5), now()->subDays(3)] as $due) {
            $this->service->update(['expires_at' => $due]);
            $claims = Token::verify($this->check()->json('token'), $keys, $now);
            $this->assertSame([$due->format('Y-m-d'), $now + 30 * 86400], [$claims['due'], $claims['exp']]);
        }

        // Not for an answer that is not Active, nor from another address.
        $foreign = $this->check(ip: '203.0.113.9');
        $this->assertSame(['Invalid', 'IP Address Invalid', null], [$foreign->json('status'), $foreign->json('message'), $foreign->json('token')]);
        // No telemetry, no install: the claim is there, empty (the client checks it against its own).
        $this->assertSame('', Token::verify($this->check()->json('token'), $keys, $now)['install']);
        $this->license->update(['status' => License::STATUS_SUSPENDED]);
        $suspended = $this->check();
        $this->assertSame('Suspended', $suspended->json('status'));
        $this->assertNull($suspended->json('token'));
    }

    public function test_without_a_key_or_with_a_broken_one_the_answer_goes_without_a_token(): void
    {
        config(['services.license_token.secret' => null]);
        $plain = $this->check();
        $this->assertSame('Active', $plain->json('status'));
        $this->assertNull($plain->json('token'));

        // A key that is not one, then a good key with a kid that is not one: no token, said once a day in all (not a
        // report per check, with the key in its trace).
        Log::spy();
        config(['services.license_token.secret' => base64_encode('not a key')]);
        $broken = $this->check();
        $this->assertSame('Active', $broken->json('status'));
        $this->assertNull($broken->json('token'));

        config(['services.license_token.secret' => Token::keypair()['secret'], 'services.license_token.kid' => 'k.1']);
        $this->assertNull($this->check()->json('token'));
        $this->assertNull($this->check()->json('token'));
        Log::shouldHaveReceived('error')->once();

        // The test vector's key: its secret is in the repository, so never one (review of #864).
        $vector = json_decode((string) file_get_contents(base_path('packages/nixpal-licensing/tests/fixtures/token/v1.json')), true);
        config(['services.license_token.secret' => $vector['secret'], 'services.license_token.kid' => 'k1']);
        $this->assertSame([Token::TEST_PUBLIC_KEY, null], [$vector['public'], $this->check()->json('token')]);
        $this->artisan('mycs:license-token-key --show')->assertExitCode(1);
    }

    /** In shadow: the clients say whether their last token verified; a bad one is a signal. */
    public function test_the_clients_check_of_the_token_is_kept_and_a_bad_one_is_a_signal(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
        $this->check($this->telemetry(['token' => 'ok']));
        $this->assertSame('ok', LicenseInstall::query()->sole()->token_check);
        $this->livewireAs($staff)->withQueryParams(['tab' => 'signals'])->test(ListLicenses::class)->assertCanNotSeeTableRecords([$this->license]);

        $this->check($this->telemetry(['token' => 'bad']));
        $this->assertSame('bad', LicenseInstall::query()->sole()->token_check);
        $this->livewireAs($staff)->withQueryParams(['tab' => 'signals'])->test(ListLicenses::class)->assertCanSeeTableRecords([$this->license]);
        $this->assertStringContainsString('⚠ token bad', LicenseTelemetry::describe(LicenseInstall::query()->sole()));

        $this->check($this->telemetry(['token' => 'forged']));
        $this->assertSame('bad', LicenseInstall::query()->sole()->token_check, 'anything else is left out');
    }

    public function test_the_key_command_makes_a_pair_and_shows_the_public_half(): void
    {
        $this->artisan('mycs:license-token-key')->expectsOutputToContain('MYCS_LICENSE_TOKEN_KEY=')->assertExitCode(0);

        config(['services.license_token.secret' => null]);
        $this->artisan('mycs:license-token-key --show')->assertExitCode(1);
        $pair = Token::keypair();
        config(['services.license_token.secret' => $pair['secret'], 'services.license_token.kid' => 'k1']);
        $this->artisan('mycs:license-token-key --show')->expectsOutputToContain($pair['public'])->assertExitCode(0);
    }

    /** The console's «API»: the endpoint of the last check from where the license runs (v0 zlic.php, v1 the new one). */
    public function test_the_license_keeps_the_endpoint_of_its_last_check(): void
    {
        $this->assertNull($this->license->fresh()->last_endpoint);
        $this->check()->assertOk();
        $this->assertSame('v1', $this->license->fresh()->last_endpoint);

        $this->withServerVariables(['REMOTE_ADDR' => self::IP])->post('/zlic.php', ['license' => $this->license->license_key, 'license_PSK' => 'TestSecret']);
        $this->assertSame('v0', $this->license->fresh()->last_endpoint);

        // Not anyone's failure: from another address, through v1, it stays v0 (review).
        $this->assertSame('Invalid', $this->check(ip: '203.0.113.9')->json('status'));
        $this->assertSame('v0', $this->license->fresh()->last_endpoint);

        // An ended license still calling from its own address shows its endpoint; from elsewhere, no.
        $this->license->update(['status' => License::STATUS_EXPIRED]);
        $this->check()->assertOk();
        $this->assertSame('v1', $this->license->fresh()->last_endpoint);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post('/zlic.php', ['license' => $this->license->license_key, 'license_PSK' => 'TestSecret']);
        $this->assertSame('v1', $this->license->fresh()->last_endpoint);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => ['*']])->id]);
        $this->livewireAs($staff)->test(ListLicenses::class)->assertCanSeeTableRecords([$this->license])
            ->assertTableColumnStateSet('last_endpoint', 'v1', $this->license);
    }

    /** «Last call» (last_access) in the app's timezone: Carbon 3's createFromTimestamp() is UTC, read back hours off. */
    public function test_the_last_call_is_now_whatever_the_timezone(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Athens');
        try {
            // zlic's clock is time(), not the test's: within a minute of it, never hours off.
            $this->check()->assertOk(); // binds: the bind's write
            $this->assertLessThan(60, abs($this->license->fresh()->last_access->getTimestamp() - time()), 'the bind');

            $this->license->update(['last_access' => now()->subDay()]);
            $this->withServerVariables(['REMOTE_ADDR' => self::IP])->post('/zlic.php', ['license' => $this->license->license_key, 'license_PSK' => 'TestSecret']);
            $this->assertLessThan(60, abs($this->license->fresh()->last_access->getTimestamp() - time()), 'the touch of a bound license');
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function test_describe_says_the_install_in_one_line(): void
    {
        Carbon::setTestNow('2026-11-15 12:00:00');
        $install = new LicenseInstall(['product' => 'node', 'version' => '2026.11.14.2129', 'ip' => self::IP, 'last_seen_at' => now()->subHours(2),
            'os' => 'linux', 'arch' => 'amd64', 'licensed' => false, 'failures' => 3, 'clock_skew' => 90000]);

        $this->assertSame('node 2026.11.14.2129 · 198.51.100.7 · 2 hours ago · linux/amd64 · degraded (3 failures) · ⚠ clock +1d', LicenseTelemetry::describe($install));
    }
}
