<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Http\Controllers\ZlicController;
use App\Jobs\Server\UpgradeJob;
use App\Models\License;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Licensing\LicenseActivity;
use App\Services\Licensing\MycsLicenseRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nixpal\Licensing\Zlic;
use Nixpal\Licensing\ZlicResponse;
use Tests\TestCase;

/** Review of 97e409e (licensing core): failing tests for the findings. */
class LicensingReviewTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['key_prefix' => ['ZCAgent-', false], 'key_length' => ['16', false], 'secret_key' => ['TestSecret', true], 'allow_reissue' => ['1', false]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => $key === 'allow_reissue' ? 'boolean' : 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => $product->product->getMorphClass()])->save();
        }
        $user = User::factory()->create();
        $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
        ExtensionHelper::createServer($this->service);
        $this->key = License::query()->sole()->license_key;
    }

    /**
     * R1. nginx `location ~ \.php$ { try_files $uri =404; ... }` (the config on this host) and Apache run public/zlic.php
     * directly: SCRIPT_NAME=/zlic.php made «/zlic.php» the base URL and the path «/», the home page. Fixed in the file;
     * checked through PHP's own server, which runs the file the same way (the test client always goes through index.php).
     */
    public function test_zlic_php_run_directly_by_the_web_server_reaches_the_zlic_route(): void
    {
        $db = tempnam(sys_get_temp_dir(), 'zlic') . '.sqlite';
        touch($db);
        $env = ['APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db, 'APP_ENV' => 'local'] + getenv();
        $migrate = proc_open([PHP_BINARY, base_path('artisan'), 'migrate', '--force', '--seed', '-q'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, base_path(), $env);
        proc_close($migrate);
        $port = 19000 + getmypid() % 1000;
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', public_path()], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, base_path(), $env);
        try {
            for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
                usleep(100000);
            }
            $context = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => 'license=ZCAgent-unknown&license_PSK=x', 'ignore_errors' => true]]);
            $post = file_get_contents("http://127.0.0.1:{$port}/zlic.php", false, $context);
            $get = file_get_contents("http://127.0.0.1:{$port}/zlic.php?license=ZCAgent-unknown&license_PSK=x", false, stream_context_create(['http' => ['ignore_errors' => true]]));
        } finally {
            proc_terminate($server);
            @unlink($db);
        }

        $this->assertSame('{"status":"Invalid","remotecheck":true}', $post);
        $this->assertSame('{"status":"Invalid","remotecheck":true}', $get);
    }

    /** R2. `artisan down` answers zlic with a 503 HTML page: the endpoint is not exempt from maintenance mode. */
    public function test_zlic_keeps_answering_json_in_maintenance_mode(): void
    {
        $this->app->maintenanceMode()->activate(['except' => [], 'status' => 503]);
        try {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->post('/zlic.php', ['license' => $this->key, 'license_PSK' => 'TestSecret']);
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Active', json_decode((string) $response->getContent(), true)['status'] ?? null);
    }

    /**
     * R3. Global TrimStrings/ConvertEmptyStringsToNull rewrite the input before Zlic sees it: a PSK with a space is
     * accepted here and refused by WHMCS's zlic.php / the package's endpoint ($_POST + $_GET untouched).
     */
    public function test_the_input_reaches_zlic_as_sent(): void
    {
        $params = ['license' => $this->key, 'license_PSK' => 'TestSecret '];
        $raw = (new Zlic(new MycsLicenseRepository, fn () => '', null, null, ZlicController::DIRECTORY))->handle($params, '198.51.100.7');
        License::query()->update(['valid_ip' => null, 'status' => 'Active']); // undo the bind the direct call may have made

        $http = json_decode((string) $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->post('/zlic.php', $params)->getContent(), true);

        $this->assertSame('Invalid', $raw->body['status']);
        $this->assertSame($raw->body['status'], $http['status'], 'the HTTP endpoint trimmed the PSK');
    }

    /**
     * R4. An array parameter turned into an ErrorException («Array to string conversion»): «Remote Check Failed» + a
     * report per request. Fixed: a parameter that is not a string is a missing one (400), nothing reported.
     */
    public function test_an_array_parameter_is_answered_as_an_unknown_key(): void
    {
        $response = $this->post('/zlic.php', ['license' => ['x'], 'license_PSK' => 'TestSecret']);

        $this->assertSame('{"status":"Invalid","description":"Missing license or license_PSK"}', $response->getContent());
    }

    /**
     * R5. With «trusted proxies: *» (a setting in the admin), ProxyMiddleware trusts the caller itself, so anyone sets
     * X-Forwarded-For to the bound IP and passes the IP binding. WHMCS's zlic.php (and the package's) use REMOTE_ADDR.
     */
    public function test_x_forwarded_for_does_not_pass_the_ip_binding(): void
    {
        License::query()->update(['valid_ip' => '198.51.100.7']);
        config(['settings.trusted_proxies' => ['*']]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withHeader('X-Forwarded-For', '198.51.100.7')
            ->post('/zlic.php', ['license' => $this->key, 'license_PSK' => 'TestSecret']);

        $this->assertSame('IP Address Invalid', json_decode((string) $response->getContent(), true)['message'] ?? null);
    }

    /**
     * R6. Two checks of a new day at once: both increment() find no row, both insertOrIgnore(), one is ignored and its
     * check is lost. Simulated by inserting the other request's row right after our increment ran.
     */
    public function test_two_first_checks_of_a_day_are_both_counted(): void
    {
        $license = License::query()->sole();
        $inserted = false;
        DB::listen(function ($query) use (&$inserted, $license) {
            if (!$inserted && str_starts_with(strtolower($query->sql), 'update "license_check_days"')) {
                $inserted = true;
                DB::table('license_check_days')->insert(['license_id' => $license->id, 'day' => now()->toDateString(), 'ok' => 1, 'failed' => 0]);
            }
        });

        LicenseActivity::record($this->key, new ZlicResponse(200, ['status' => 'Active']), '198.51.100.7', 'POST test');

        $this->assertSame(2, (int) DB::table('license_check_days')->where('license_id', $license->id)->value('ok'));
    }

    /** R7. A package/cycle change of a license service queues UpgradeJob; NixpalLicensing has no upgradeServer: the job fails. */
    public function test_an_upgrade_of_a_license_service_does_not_fail_its_job(): void
    {
        (new UpgradeJob($this->service->fresh()))->handle();

        $this->assertSame(License::STATUS_ACTIVE, License::query()->sole()->status);
    }
}
