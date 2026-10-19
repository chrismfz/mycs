<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Models\License;
use App\Models\LicenseAccessLog;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Licensing\Licenses;
use App\Services\Licensing\MycsLicenseRepository;
use Filament\Facades\Filament;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Nixpal\Licensing\License as PackageLicense;
use Tests\TestCase;

/**
 * Adversarial review of 97e409e (nixpal licensing, zlic.php). Findings are test_* that FAIL on the commit;
 * test_holds_* are attacks tried that the code withstands.
 */
class LicensingAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    private const IP = '198.51.100.7';

    protected function setUp(): void
    {
        parent::setUp();
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['key_prefix' => ['ZCAgent-', false], 'key_length' => ['16', false], 'secret_key' => ['TestSecretValue', true], 'allow_reissue' => ['1', false]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => $key === 'allow_reissue' ? 'boolean' : 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => $product->product->getMorphClass()])->save();
        }
        $user = User::factory()->create();
        $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
    }

    private function zlic(array $params, array $server = ['REMOTE_ADDR' => self::IP]): TestResponse
    {
        return $this->withServerVariables($server)->post('/zlic.php', $params);
    }

    private function check(string $key, string $psk = 'TestSecretValue', string $ip = self::IP, array $extraServer = []): array
    {
        return (array) json_decode($this->zlic(['license' => $key, 'license_PSK' => $psk], ['REMOTE_ADDR' => $ip] + $extraServer)->getContent(), true);
    }

    private function issued(): License
    {
        ExtensionHelper::createServer($this->service);

        return License::query()->where('service_id', $this->service->id)->sole();
    }

    // ---------------------------------------------------------------- findings (fail on 97e409e)

    /**
     * ADV-L1. The answer follows only licenses.status, never the service's. A service ended without the extension's
     * terminate running (staff set «Cancelled» in the service form; the cron/cancellation path whose TerminateJob failed
     * or was swallowed) keeps answering Active, with a fresh localkey, indefinitely.
     */
    public function test_a_cancelled_service_does_not_answer_active(): void
    {
        $key = $this->issued()->license_key;
        $this->assertSame('Active', $this->check($key)['status']);

        $this->service->update(['status' => Service::STATUS_CANCELLED]);

        $answer = $this->check($key);
        $this->assertNotSame('Active', $answer['status'], 'a cancelled service still answers Active');
        $this->assertArrayNotHasKey('localkey', $answer);
    }

    /**
     * ADV-L1b. TerminateJob swallows «No server assigned to this product»: a product taken off its server (or moved to
     * another one) and the cancellation that follows leave the license Active for good.
     */
    public function test_a_service_terminated_after_its_product_left_the_server_does_not_answer_active(): void
    {
        $key = $this->issued()->license_key;
        $this->service->product->update(['server_id' => null]);

        (new TerminateJob($this->service->fresh(), false))->handle();
        $this->service->update(['status' => Service::STATUS_CANCELLED]);

        $this->assertNotSame('Active', $this->check($key)['status']);
    }

    /**
     * ADV-L2. «Unsuspend server» on a cancelled/terminated service: EditService says «a pending or cancelled service is
     * not revived», and it is not, but Licenses::setStatus(Active) turns the Expired license Active again.
     */
    public function test_unsuspend_on_a_terminated_service_does_not_revive_its_license(): void
    {
        $key = $this->issued()->license_key;
        ExtensionHelper::terminateServer($this->service);
        $this->service->update(['status' => Service::STATUS_CANCELLED]);
        $this->assertSame('Expired', $this->check($key)['status']);

        ExtensionHelper::unsuspendServer($this->service->fresh());

        $this->assertSame(Service::STATUS_CANCELLED, $this->service->fresh()->status);
        $this->assertSame('Expired', $this->check($key)['status'], 'the license of a cancelled service is Active again');
    }

    /** ADV-L2b. Same with «Create server» on a cancelled service: Licenses::issue() makes an Expired license Active. */
    public function test_create_on_a_cancelled_service_does_not_revive_its_license(): void
    {
        $key = $this->issued()->license_key;
        ExtensionHelper::terminateServer($this->service);
        $this->service->update(['status' => Service::STATUS_CANCELLED]);

        ExtensionHelper::createServer($this->service->fresh());

        $this->assertSame('Expired', $this->check($key)['status']);
    }

    /**
     * ADV-L3. With trusted_proxies «*» (a setting MyCS offers; ProxyMiddleware then trusts REMOTE_ADDR, i.e. every
     * caller) X-Forwarded-For decides the address zlic binds and checks. Anyone holding a key (and the product PSK,
     * which ships inside every client) answers Active from anywhere, defeating the IP binding: the only per-customer
     * control of the licence. zlic.php should use the connection address unless the proxy is a configured one.
     */
    public function test_a_forged_forwarded_for_does_not_pass_the_ip_binding(): void
    {
        config(['settings.trusted_proxies' => ['*']]);
        $key = $this->issued()->license_key;
        $this->assertSame('Active', $this->check($key, ip: self::IP)['status']);

        $answer = $this->check($key, ip: '203.0.113.50', extraServer: ['HTTP_X_FORWARDED_FOR' => self::IP]);

        $this->assertSame('IP Address Invalid', $answer['message'] ?? null, 'a forged X-Forwarded-For passed the IP binding');
    }

    /**
     * ADV-L4. During a deploy (artisan down) zlic.php answers 503 HTML from PreventRequestsDuringMaintenance; the
     * controller's own contract is that an outage answers «Remote Check Failed» (the only answer on which clients fall
     * back to their stored due date). Every client checking in the window gets an unparseable answer.
     */
    public function test_maintenance_mode_answers_remote_check_failed(): void
    {
        $key = $this->issued()->license_key;
        // Down in this process only: the file driver's storage/framework/down would answer 503 to every parallel process.
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->maintenanceMode()->activate(['time' => time(), 'retry' => null, 'status' => 503, 'secret' => null, 'template' => null, 'redirect' => null, 'refresh' => null, 'except' => []]);
        try {
            $response = $this->zlic(['license' => $key, 'license_PSK' => 'TestSecretValue']);
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }

        // Fixed by answering as usual (zlic.php is exempt from maintenance mode): better than «Remote Check Failed».
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Active', json_decode($response->getContent(), true)['status'] ?? null);
    }

    /**
     * ADV-L5. license[]=… / license_PSK[]=…: the (string) cast of an array throws (Array to string conversion), the
     * catch answers «Remote Check Failed» and report()s an exception per request: an unauthenticated log flood (and a
     * DebugLog row each with settings.debug). It should be the 400 «Missing license or license_PSK».
     */
    public function test_array_params_are_a_missing_param_not_an_exception(): void
    {
        $key = $this->issued()->license_key;
        $reported = 0;
        $this->app->make(ExceptionHandler::class);
        $this->app['events']->listen(MessageLogged::class, function () use (&$reported) {
            $reported++;
        });

        $a = $this->zlic(['license' => [$key], 'license_PSK' => 'TestSecretValue']);
        $b = $this->zlic(['license' => $key, 'license_PSK' => ['TestSecretValue']]);

        $this->assertSame(400, $a->getStatusCode(), $a->getContent());
        $this->assertSame(400, $b->getStatusCode(), $b->getContent());
        $this->assertSame(0, $reported, 'an exception was reported for a malformed request');
    }

    /**
     * ADV-L6. Every unknown key writes a license_access_logs row («Invalid Key - …»), unauthenticated and unthrottled:
     * a flood grows the table without bound for 90 days (and no rate limit at all on /zlic.php). Should be capped
     * (per IP and minute, or aggregated as license_check_days is).
     */
    public function test_a_flood_of_unknown_keys_does_not_grow_the_log_without_bound(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $this->zlic(['license' => 'ZCAgent-' . str_pad((string) $i, 16, '0', STR_PAD_LEFT), 'license_PSK' => 'x'], ['REMOTE_ADDR' => '203.0.113.66']);
        }

        $this->assertLessThan(300, LicenseAccessLog::query()->count(), 'one row per unauthenticated request');
    }

    /**
     * ADV-L7. TrimStrings/ConvertEmptyStringsToNull run on /zlic.php: a PSK (or key) with surrounding whitespace is
     * trimmed and answered Active, where WHMCS's zlic.php answers «MD5 Checksum Verification Failed». The contract is
     * the same input → the same output.
     */
    public function test_a_psk_with_surrounding_whitespace_is_answered_as_whmcs_does(): void
    {
        $key = $this->issued()->license_key;
        $this->check($key); // bind

        $answer = $this->check($key, psk: ' TestSecretValue ');

        $this->assertSame('Invalid', $answer['status']);
        $this->assertSame('MD5 Checksum Verification Failed', $answer['description'] ?? null);
    }

    /**
     * ADV-L8. The PSK in the audit log: Setting audits go through RightRedactor, which keeps the first tenth of the
     * value in clear (rounded up). Any staff with audit-log access reads the first characters of every PSK change.
     */
    public function test_the_audit_log_shows_no_part_of_the_psk(): void
    {
        config(['audit.console' => true]); // as on the web (tests run in the console, where the audit is off)
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $product = $this->service->product;

        Livewire::actingAs($staff, 'staff')->test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->set('data.settings.secret_key', 'Zq9NewSecretPSK1')->call('save')->assertHasNoErrors();

        $audits = DB::table('audits')->where('auditable_type', (new Setting)->getMorphClass())->get(['old_values', 'new_values']);
        $this->assertNotEmpty($audits);
        foreach ($audits as $audit) {
            $this->assertStringNotContainsString('Zq', (string) $audit->new_values, 'the audit keeps the start of the new PSK');
            $this->assertStringNotContainsString('Te', (string) $audit->old_values, 'the audit keeps the start of the old PSK');
        }
    }

    // ---------------------------------------------------------------- attacks that hold

    public function test_holds_a_wrong_psk_never_binds_an_unbound_license(): void
    {
        $license = $this->issued();

        $answer = $this->check($license->license_key, psk: 'guess', ip: '203.0.113.9');

        $this->assertSame('Invalid', $answer['status']);
        $this->assertArrayNotHasKey('localkey', $answer);
        $this->assertNull($license->fresh()->valid_ip);
        $this->assertSame('Active', $this->check($license->license_key)['status'], 'the owner still binds it');
    }

    public function test_holds_a_wrong_psk_from_the_bound_ip_gets_no_localkey(): void
    {
        $key = $this->issued()->license_key;
        $this->check($key);

        $answer = $this->check($key, psk: 'TestSecretValuX');

        $this->assertSame('Invalid', $answer['status']);
        $this->assertArrayNotHasKey('localkey', $answer);
        $this->assertArrayNotHasKey('remotecheck', $answer);
    }

    public function test_holds_a_reissued_license_claimed_needs_the_psk(): void
    {
        $license = $this->issued();
        $this->check($license->license_key);
        Licenses::reissue($license->fresh(), '192.0.2.1', byStaff: true);

        $this->check($license->license_key, psk: 'nope', ip: '203.0.113.9');

        $this->assertSame(License::STATUS_REISSUED, $license->fresh()->status);
        $this->assertNull($license->fresh()->valid_ip);
    }

    public function test_holds_the_forwarded_for_header_is_ignored_without_trusted_proxies(): void
    {
        $key = $this->issued()->license_key;
        $this->check($key, ip: self::IP);

        $answer = $this->check($key, ip: '203.0.113.50', extraServer: ['HTTP_X_FORWARDED_FOR' => self::IP]);

        $this->assertSame('IP Address Invalid', $answer['message'] ?? null);
    }

    public function test_holds_a_bind_loses_to_a_suspend_and_to_another_bind(): void
    {
        $license = $this->issued();
        $repository = new MycsLicenseRepository;

        // Read, then another caller binds first: ours is refused.
        $mine = $repository->findByKey($license->license_key);
        $theirs = $repository->findByKey($license->license_key);
        $theirs->validIp = '203.0.113.9';
        $theirs->status = PackageLicense::ACTIVE;
        $this->assertTrue($repository->bind($theirs, time()));
        $mine->validIp = self::IP;
        $mine->status = PackageLicense::ACTIVE;
        $this->assertFalse($repository->bind($mine, time()));
        $this->assertSame('203.0.113.9', $license->fresh()->valid_ip);

        // Reissued, read, staff suspends: the bind does not undo the suspend.
        Licenses::reissue($license->fresh(), '192.0.2.1', byStaff: true);
        $read = $repository->findByKey($license->license_key);
        Licenses::setStatus($this->service, License::STATUS_SUSPENDED);
        $read->validIp = self::IP;
        $read->status = PackageLicense::ACTIVE;
        $this->assertFalse($repository->bind($read, time()));
        $this->assertSame(License::STATUS_SUSPENDED, $license->fresh()->status);
    }

    public function test_holds_wildcards_and_huge_keys_match_nothing(): void
    {
        $this->issued();

        foreach (['%', 'ZCAgent-%', 'ZCAgent-________________', "' OR 1=1 -- ", str_repeat('A', 200000)] as $key) {
            $response = $this->zlic(['license' => $key, 'license_PSK' => 'TestSecretValue']);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['status' => 'Invalid', 'remotecheck' => true], json_decode($response->getContent(), true));
        }
        $this->assertLessThanOrEqual(64 + strlen('Invalid Key - '), max(LicenseAccessLog::query()->pluck('message')->map(fn ($m) => strlen($m))->all()));
    }

    public function test_holds_the_endpoint_sets_no_cookie_and_needs_no_csrf(): void
    {
        $key = $this->issued()->license_key;

        $response = $this->zlic(['license' => $key, 'license_PSK' => 'TestSecretValue']);

        $response->assertOk();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertSame('Active', json_decode($response->getContent(), true)['status']);
    }

    public function test_holds_a_deleted_service_answers_invalid(): void
    {
        $key = $this->issued()->license_key;
        $this->service->delete();

        $this->assertSame(['status' => 'Invalid', 'remotecheck' => true], $this->check($key));
    }

    public function test_holds_the_customer_cannot_reissue_a_suspended_license_or_past_the_limit(): void
    {
        $license = $this->issued();
        Licenses::setStatus($this->service, License::STATUS_SUSPENDED);
        try {
            Licenses::reissue($license->fresh()->load('service.product.settings'), '192.0.2.1');
            $this->fail('a suspended license was reissued by the customer');
        } catch (DisplayException) {
        }

        Licenses::setStatus($this->service, License::STATUS_ACTIVE);
        $license->update(['reissues' => Licenses::MAX_REISSUES]);
        try {
            Licenses::reissue($license->fresh()->load('service.product.settings'), '192.0.2.1');
            $this->fail('reissued past the limit');
        } catch (DisplayException) {
        }

        try {
            Licenses::reissue($license->fresh()->load('service.product.settings'), '192.0.2.1', byStaff: true);
        } catch (DisplayException) {
        }
        Licenses::setStatus($this->service, License::STATUS_EXPIRED);
        $this->expectException(DisplayException::class);
        Licenses::reissue($license->fresh()->load('service.product.settings'), '192.0.2.1', byStaff: true);
    }

    public function test_holds_the_psk_never_reaches_the_product_page_and_survives_duplicate_encrypted(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        $product = $this->service->product;

        $page = Livewire::actingAs($staff, 'staff')->test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $this->assertStringNotContainsString('TestSecretValue', $page->html());
        $this->assertStringNotContainsString('TestSecretValue', json_encode($page->snapshot));

        $page->callAction('duplicate');
        $copy = DB::table('settings')->where('key', 'secret_key')->where('settingable_id', '!=', $product->id)->sole();
        $this->assertTrue((bool) $copy->encrypted);
        $this->assertNotSame('TestSecretValue', $copy->value);
    }

    public function test_holds_the_psk_is_not_in_the_answer_or_the_log(): void
    {
        $key = $this->issued()->license_key;
        $this->check($key);
        $bodies = [
            json_encode($this->check($key)),
            json_encode($this->check($key, psk: 'wrong')),
            json_encode($this->check($key, ip: '203.0.113.9')),
        ];
        foreach ($bodies as $body) {
            $this->assertStringNotContainsString('TestSecretValue', $body);
        }
        $this->assertFalse(LicenseAccessLog::query()->get()->contains(fn ($row) => str_contains(json_encode($row->toArray()), 'TestSecretValue')));
    }
}
