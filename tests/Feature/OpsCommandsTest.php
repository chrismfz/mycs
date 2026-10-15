<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\License;
use App\Models\LicenseAccessLog;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ops\Health;
use App\Services\Ops\LogTail;
use App\Services\Ops\ZlicProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        Setting::query()->updateOrCreate(['key' => 'last_scheduler_run', 'settingable_type' => null, 'settingable_id' => null], ['value' => now()->toDateTimeString()]);
        EmailLog::query()->create(['subject' => 's', 'to' => 'a@example.test', 'body' => 'b', 'status' => EmailLog::STATUS_SENDING, 'sending_at' => now()->subHour()]);

        $report = Health::report();

        $this->assertSame(['build', 'database', 'scheduler', 'cron', 'queue', 'mail', 'module_actions', 'licenses'], array_keys($report['checks']));
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
            ->expectsOutputToContain(License::query()->sole()->license_key)
            ->doesntExpectOutputToContain('Pa55word')
            ->doesntExpectOutputToContain('OpsTestSecret')
            ->assertSuccessful();
        $this->artisan('mycs:service', ['id' => License::query()->sole()->license_key])->assertSuccessful(); // by its domain (= the key)
        $this->artisan('mycs:service', ['id' => 999999])->assertFailed();
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
        $this->artisan('mycs:zlic', ['key' => $license->license_key, '--json' => true])->doesntExpectOutputToContain('OpsTestSecret')->assertSuccessful();
        $this->assertSame('Invalid', ZlicProbe::answer('ZCWeb-nope', '198.51.100.7')['status']);
    }

    public function test_the_log_tail_is_newest_first_by_level_and_masks_secrets(): void
    {
        $dir = storage_path('logs');
        $file = $dir . '/opstest-' . uniqid() . '.log';
        File::put($file, "[2026-10-15 10:00:00] testing.ERROR: first failure\n#0 /app/a.php(1): x()\n"
            . "[2026-10-15 10:01:00] testing.INFO: just info\n"
            . "[2026-10-15 10:02:00] testing.ERROR: the PSK OpsTestSecret leaked\n");
        try {
            $entries = LogTail::entries(10, 'error', basename($file, '.log'));
        } finally {
            File::delete($file);
        }

        $this->assertSame(['error', 'error'], array_column($entries, 'level'));
        $this->assertStringContainsString('********', $entries[0]['message']);
        $this->assertStringNotContainsString('OpsTestSecret', $entries[0]['message']);
        $this->assertSame(['#0 /app/a.php(1): x()'], $entries[1]['trace']);
    }
}
