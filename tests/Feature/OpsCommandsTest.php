<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Contact;
use App\Models\CronStat;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\License;
use App\Models\LicenseAccessLog;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ops\Dossier;
use App\Services\Ops\Health;
use App\Services\Ops\LogTail;
use App\Services\Ops\ZlicProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The ops commands (docs/ops.md): read-only health, dossiers and probes for testing and troubleshooting, the same
 * answer the MCP gives. Nothing they print is a secret, and nothing they run writes.
 */
class OpsCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['key_prefix' => ['ZCWeb-', false], 'key_length' => ['16', false], 'secret_key' => ['OpsTestSecret', true]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => $product->product->getMorphClass()])->save();
        }
        $user = User::factory()->create();
        $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => now()->addMonth(),
            'username' => 'opsuser', 'password' => 'Pa55word!secret']);
        ExtensionHelper::createServer($this->service);
    }

    public function test_health_reports_each_check_and_exits_by_the_worst(): void
    {
        $this->artisan('app:schedule-heartbeat-command');
        EmailLog::query()->create(['subject' => 's', 'to' => 'a@example.test', 'body' => 'b', 'status' => EmailLog::STATUS_SENDING, 'sending_at' => now()->subHour()]);

        $report = Health::report();

        $this->assertSame(['build', 'runtime', 'database', 'scheduler', 'cron', 'queue', 'mail', 'module_actions', 'licenses', 'domains', 'ticket_mail', 'tax', 'vat_rates'], array_keys($report['checks']));
        $this->assertSame(Health::OK, $report['checks']['scheduler']['status']);
        $this->assertSame(Health::FAIL, $report['checks']['mail']['status'], 'a stuck mail');
        $this->assertSame(Health::FAIL, $report['status']);
        $this->artisan('mycs:health')->assertExitCode(2);
        $this->artisan('mycs:health --json')->expectsOutputToContain('"module_actions"')->assertExitCode(2);
    }

    public function test_the_service_dossier_has_its_story_and_no_secret(): void
    {
        (new ModuleAction)->forceFill(['service_id' => $this->service->id, 'action' => 'suspend', 'status' => ModuleAction::STATUS_OPEN, 'attempts' => 2, 'last_error' => 'Timeout'])->save();

        $this->artisan('mycs:service', ['id' => $this->service->id, '--json' => true])
            ->expectsOutputToContain('"module_actions"')
            ->expectsOutputToContain('Timeout')
            ->expectsOutputToContain('ZCWeb-…' . substr(License::query()->sole()->license_key, -4))
            ->doesntExpectOutputToContain(License::query()->sole()->license_key)
            ->doesntExpectOutputToContain('Pa55word')
            ->doesntExpectOutputToContain('OpsTestSecret')
            ->assertSuccessful();
        $this->artisan('mycs:service', ['id' => License::query()->sole()->license_key])->assertSuccessful(); // by its domain (= the key)
        $this->artisan('mycs:service', ['id' => 999999])->assertFailed();
    }

    public function test_module_and_mail_errors_leave_without_their_secrets(): void
    {
        $error = 'cURL error 28 for https://vps.example.test:4085/index.php?act=adduser&adminapikey=k&adminapipass=OpsTestSecret&password=CustPass1 '
            . 'https://cp.example.test:2087/cpsess1234567890/json-api; Authorization: Bearer abcdef0123456789abcdef';
        (new ModuleAction)->forceFill(['service_id' => $this->service->id, 'action' => 'create', 'status' => ModuleAction::STATUS_OPEN, 'attempts' => 1, 'last_error' => $error])->save();
        EmailLog::query()->create(['subject' => 's', 'to' => 'a@example.test', 'body' => 'b', 'status' => EmailLog::STATUS_FAILED,
            'error' => 'SMTP login failed for https://smtp.example.test/?token=SmtpTok3n', 'related_type' => $this->service->getMorphClass(), 'related_id' => $this->service->id]);

        $json = json_encode(Dossier::service($this->service->fresh()));

        foreach (['OpsTestSecret', 'CustPass1', 'cpsess1234567890', 'abcdef0123456789abcdef', 'SmtpTok3n'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
        $this->assertStringContainsString('cURL error 28', $json, 'the error itself stays');
    }

    public function test_lookups_by_license_key_email_and_an_ambiguous_number(): void
    {
        $key = License::query()->sole()->license_key;
        $this->service->update(['domain' => 'other.example.test']);
        $this->artisan('mycs:service', ['id' => $key, '--json' => true])->expectsOutputToContain('"id": ' . $this->service->id)->assertSuccessful();

        $email = $this->service->client->primaryContact->email;
        (new Contact)->forceFill(['client_id' => User::factory()->create()->client()->id, 'first_name' => 'X', 'last_name' => 'Y', 'email' => $email])->save();
        $this->artisan('mycs:client', ['id' => $email, '--json' => true])->expectsOutputToContain('"account_id": ' . $this->service->client_id)->assertSuccessful();

        $client = $this->service->client_id;
        $first = Invoice::factory()->create(['client_id' => $client, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => now()]);
        $second = Invoice::factory()->create(['client_id' => $client, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => now()]);
        DB::table('invoices')->where('id', $first->id)->update(['number' => 'N-' . $first->id]);
        DB::table('invoices')->where('id', $second->id)->update(['number' => (string) $first->id]); // an imported WHMCS number
        $this->artisan('mycs:invoice', ['id' => (string) $first->id])->assertFailed();
        $this->artisan('mycs:invoice', ['id' => '#' . $first->id, '--json' => true])->expectsOutputToContain('"id": ' . $first->id)->assertSuccessful();
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_health_warns_with_exit_1(): void
    {
        $this->artisan('app:schedule-heartbeat-command'); // the rows as the scheduler writes them, not as a test would
        Setting::query()->updateOrCreate(['key' => 'last_cron_run', 'settingable_type' => CronStat::class], ['value' => now()->toDateTimeString()]);
        config(['settings.mail_disable' => true]);

        $this->assertSame(Health::WARN, Health::report()['status']);
        $this->artisan('mycs:health')->assertExitCode(1);
    }

    public function test_the_client_and_invoice_dossiers(): void
    {
        $client = $this->service->client;

        $this->artisan('mycs:client', ['id' => $client->id, '--json' => true])->expectsOutputToContain('"services"')->assertSuccessful();
        $this->artisan('mycs:client', ['id' => $client->primaryContact->email])->assertSuccessful();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $this->artisan('mycs:invoice', ['id' => $invoice->id, '--json' => true])->expectsOutputToContain('"transactions"')->assertSuccessful();
    }

    public function test_the_zlic_probe_answers_without_writing_or_leaking_the_psk(): void
    {
        $license = License::query()->sole();
        $before = $license->fresh()->toArray();

        $unbound = ZlicProbe::answer($license->license_key, '198.51.100.7');
        $this->assertSame(['Active', '198.51.100.7', true], [$unbound['status'], $unbound['would_bind'], $unbound['psk_set']]);

        $license->update(['valid_ip' => '198.51.100.7']);
        $other = ZlicProbe::answer($license->license_key, '203.0.113.9');
        $this->assertSame(['Invalid', 'IP Address Invalid', ['IP Address Invalid']], [$other['status'], $other['message'], $other['would_log']]);

        $after = $license->fresh()->toArray();
        $this->assertSame([$before['last_access'], '198.51.100.7'], [$after['last_access'], $after['valid_ip']], 'nothing written but the test\'s own bind');
        $this->assertSame(0, LicenseAccessLog::query()->count());
        $this->artisan('mycs:zlic', ['key' => $license->license_key, '--json' => true])->doesntExpectOutputToContain('OpsTestSecret')
            ->doesntExpectOutputToContain('md5hash')->doesntExpectOutputToContain('localkey')->assertSuccessful();
        $this->assertSame('Invalid', ZlicProbe::answer('ZCWeb-nope', '198.51.100.7')['status']);
    }

    public function test_the_log_tail_is_newest_first_by_level_and_masks_secrets(): void
    {
        $dir = storage_path('logs');
        $file = $dir . '/opstest-' . uniqid() . '.log';
        File::put($file, "[2026-10-15 10:00:00] testing.ERROR: first failure\n#0 /app/a.php(1): x()\n"
            . "[2026-10-15 10:01:00] testing.INFO: just info\n"
            . "[2026-10-15 10:02:00] testing.ERROR: the PSK OpsTestSecret leaked, GET https://x.example.test/a?password=Qwerty123 with Authorization: Bearer zzzzzzzzzzzzzzzz\n");
        try {
            $entries = LogTail::entries(10, 'error', basename($file, '.log'));
        } finally {
            File::delete($file);
        }

        $this->assertSame(['error', 'error'], array_column($entries, 'level'));
        $this->assertStringContainsString('********', $entries[0]['message']);
        foreach (['OpsTestSecret', 'Qwerty123', 'zzzzzzzzzzzzzzzz'] as $secret) {
            $this->assertStringNotContainsString($secret, $entries[0]['message']);
        }
        $this->assertSame([], LogTail::entries(10, 'error', '*'), 'a name, not a pattern');
        $this->assertSame(['#0 /app/a.php(1): x()'], $entries[1]['trace']);
    }
}
