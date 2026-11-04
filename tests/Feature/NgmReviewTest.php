<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\Ngm\Ngm;
use MyCS\Extensions\Servers\Ngm\NgmApi;
use Tests\TestCase;

/** Independent review of the ngm extension: failing tests for what it found. */
class NgmReviewTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://ngm.test:9601/api/v1';

    private Service $service;

    private Server $server;

    /** @var array<string, array<string, mixed>> */
    private array $accounts = [];

    /** @var list<array{0: string, 1: string}> */
    private array $calls = [];

    private bool $lostBeforeNgm = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $this->server = Server::query()->create(['name' => 'ngm', 'extension' => 'Ngm', 'type' => 'server', 'enabled' => true]);
        $this->server->settings()->create(['key' => 'host', 'value' => 'ngm.test']);
        $this->server->settings()->create(['key' => 'token', 'value' => 'tok', 'encrypted' => true]);
        $owner = User::factory()->create();
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'basic']);
        $this->service = Service::factory()->create(['client_id' => $owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $this->server->id, 'status' => 'pending', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addYear(), 'domain' => 'example-shop.gr']);
        Http::fake(function (Request $request) {
            $path = substr($request->url(), strlen(self::API) + 1);
            $this->calls[] = [$request->method(), $path];
            $body = json_decode($request->body(), true) ?? [];
            if ($request->method() === 'POST' && $path === 'accounts') {
                if ($this->lostBeforeNgm) {
                    $this->lostBeforeNgm = false;
                    throw new ConnectionException('cURL error 7: connection refused'); // never reached ngm: nothing made
                }
                if (isset($this->accounts[$body['username']])) {
                    return Http::response(['error' => 'account already exists'], 409);
                }
                $this->accounts[$body['username']] = ['username' => $body['username'], 'created_at' => now()->toIso8601String()];

                return Http::response(['ok' => true], 201);
            }
            $user = preg_match('#^accounts/([^/]+)#', $path, $m) ? rawurldecode($m[1]) : null;
            if ($user === null || !isset($this->accounts[$user])) {
                return Http::response(['error' => 'account not found'], 404);
            }
            if ($request->method() === 'DELETE') {
                unset($this->accounts[$user]);

                return Http::response(['terminated' => true, 'backups_kept' => true]);
            }

            return Http::response($this->accounts[$user]);
        });
    }

    /**
     * ngm serves /ui on the same listener as /api/v1 (api.listen, :9601), never on :443 where nginx serves the customers'
     * sites (ngm's own WHMCS module: ngm_panel_url, Api::origin). The «Panel» line shown to the customer must carry it.
     */
    public function test_the_panel_address_is_on_the_panels_own_port(): void
    {
        $this->assertSame('https://ngm.test:9601/ui/', (new NgmApi('ngm.test', 't'))->panelUrl());
        $this->assertSame('https://ngm.test:8443/ui/', (new NgmApi('https://ngm.test:8443', 't'))->panelUrl());
    }

    /**
     * A first attempt that never reached ngm (connection refused) keeps the name; before the retry someone makes an
     * account by that name on ngm by hand (another customer's, months older than our request). The retry's 409 must not
     * adopt it: its created_at is before this service ever asked for the name.
     */
    public function test_an_account_older_than_our_request_is_never_adopted(): void
    {
        Exceptions::fake();
        $this->lostBeforeNgm = true;
        $this->assertFalse(rescue(fn () => ExtensionHelper::createServer($this->service->fresh()), false, false));
        $this->assertSame([], $this->accounts, 'nothing made on ngm');
        $pending = (string) $this->service->properties()->where('key', Ngm::PENDING)->value('value');
        $this->assertSame('exampleshop', $pending);

        // Another customer's account, made on ngm by hand long before.
        $this->accounts[$pending] = ['username' => $pending, 'created_at' => '2024-01-01T00:00:00Z'];

        rescue(fn () => ExtensionHelper::createServer($this->service->fresh()), null, false);

        // Not adopted: that name is given up for the next free one (the author's fix makes the account under it).
        $this->assertNotSame('exampleshop', $this->service->fresh()->username, 'another customer\'s account adopted as this service\'s');
        $this->assertSame('2024-01-01T00:00:00Z', $this->accounts['exampleshop']['created_at'], 'the other account left as it is');
    }

    /**
     * The create's answer was lost after ngm made the account (the name kept as pending only); the order is then
     * cancelled and terminated. The account is left live on ngm, and the terminate's failure says «Service has not been
     * created» without naming it.
     */
    public function test_terminate_after_a_lost_create_does_not_orphan_the_account(): void
    {
        Exceptions::fake();
        $this->service->properties()->create(['key' => Ngm::PENDING, 'name' => 'ngm account (being created)', 'value' => 'exampleshop']);
        $this->accounts['exampleshop'] = ['username' => 'exampleshop', 'created_at' => now()->toIso8601String()];
        $this->service->forceFill(['status' => 'cancelled'])->save();

        rescue(fn () => ExtensionHelper::terminateServer($this->service->fresh()), null, false);

        $error = (string) ModuleAction::query()->where('service_id', $this->service->id)->where('action', 'terminate')->value('last_error');
        $this->assertTrue(!isset($this->accounts['exampleshop']) || str_contains($error, 'exampleshop'),
            "the account stays on ngm and the failure does not name it: «{$error}»");
    }
}
