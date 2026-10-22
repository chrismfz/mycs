<?php

namespace Tests\Feature;

use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Servers\CPanel\CPanel;
use Tests\TestCase;

/**
 * Adversarial review of #315, round 2 (fix 3d7dbf70).
 *
 * R2-1: suspendreseller/unsuspendreseller are given 600 s («WHM may take over a reseller's accounts»), but the cron path
 * runs them in SuspendJob/UnsuspendJob, whose $timeout is 120 s. On a reseller WHM takes longer over, the worker is
 * killed at 120 s mid-call (the 600 s never applies), the attempt records nothing, and the job runs again after
 * retry_after, up to 4 times, then is given up as failed while WHM may have done it. The HTTP timeout must fit inside
 * the job that makes the call.
 */
class CPanelResellerRound2AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    /** @var array<string, float|int|null> the HTTP timeout each WHM call was made with */
    private array $timeouts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        $server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        /** @var Product $model */
        $model = $product->product;
        $model->update(['server_id' => $server->id]);
        foreach (['package' => 'Business10', 'reseller' => '1'] as $key => $value) {
            $model->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $model->id, 'server_id' => $server->id, 'status' => 'suspended', 'expires_at' => now()->addMonth(),
            'currency_code' => 'USD', 'price' => 10.00, 'domain' => 'reseller.test']);
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'resel1']);
        $this->service->properties()->create(['key' => CPanel::RESELLER, 'name' => 'WHM reseller', 'value' => '1']);

        Http::fake(function (Request $request, array $options) {
            $call = basename((string) parse_url($request->url(), PHP_URL_PATH));
            $this->timeouts[$call] = $options['timeout'] ?? null;

            return Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'],
                // listaccts: the accounts the reseller owns, read before a reseller call (protected accounts, #322).
                'data' => match ($call) {
                    'listresellers' => ['reseller' => ['resel1']],
                    'listaccts' => ['acct' => []],
                    default => [],
                }]);
        });
    }

    public function test_r2_1_suspendreseller_timeout_fits_inside_the_suspend_job(): void
    {
        $job = new SuspendJob($this->service->fresh(), false);
        $job->handle();

        $this->assertArrayHasKey('suspendreseller', $this->timeouts);
        $this->assertLessThan($job->timeout, $this->timeouts['suspendreseller'],
            'suspendreseller may wait longer than the job may run: the worker is killed mid-call and the job runs again');
    }

    public function test_r2_1_unsuspendreseller_timeout_fits_inside_the_unsuspend_job(): void
    {
        $this->service->update(['status' => Service::STATUS_ACTIVE]);
        $job = new UnsuspendJob($this->service->fresh());
        $job->handle();

        $this->assertArrayHasKey('unsuspendreseller', $this->timeouts);
        $this->assertLessThan($job->timeout, $this->timeouts['unsuspendreseller'],
            'unsuspendreseller may wait longer than the job may run: the worker is killed mid-call and the job runs again');
    }
}
