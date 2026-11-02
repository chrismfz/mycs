<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Http\Middleware\EnsureExtensionBoots;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\PaymentGatewayEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use MyCS\Extensions\Gateways\Cardlink\Cardlink;
use MyCS\Extensions\Gateways\Mollie\Mollie;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use PDO;
use Tests\TestCase;

/**
 * Adversarial review of #579 (the extensions' routes in the route cache). Each test names its finding (ADV-579-n).
 *
 * The caches are `artisan route:cache`'s own, built in a subprocess as deploy/update.sh does (`artisan optimize`), from a
 * database that is either empty or, as on the site, already holds the extensions' records.
 */
class Adv579RouteCacheTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> cache key => routes cache file */
    private static array $caches = [];

    public static function tearDownAfterClass(): void
    {
        foreach (self::$caches as $file) {
            array_map('unlink', glob(dirname($file) . '/*') ?: []);
            @rmdir(dirname($file));
        }
        self::$caches = [];
        parent::tearDownAfterClass();
    }

    /**
     * ADV-579-1 (low). A gateway without a record used to have no route at all: a stray or stale post to it (a scanner, or
     * PayPal still sending the webhooks of a deleted PayPal gateway) found no route, and the gateway log wrote nothing,
     * since AppServiceProvider::gatewayOfPath() logs an unmatched path only for a gateway that has a record. The route now
     * always exists and only EnsureExtensionBoots answers 404, after RouteMatched has opened a row: the staff's gateway
     * log fills with rows of a gateway the site does not have.
     */
    public function test_adv_579_1_a_post_to_a_gateway_without_a_record_leaves_no_gateway_log_row(): void
    {
        $this->mock(PayPal::class, fn (MockInterface $mock) => $mock->shouldNotReceive('webhook'));

        $this->post('/extensions/paypal/webhook')->assertNotFound();

        $this->assertSame(0, PaymentGatewayEvent::query()->count(), 'a 404 of a gateway the site does not have is logged as that gateway\'s');
    }

    /**
     * ADV-579-1 (low), same cause. The 404 of a gateway without a record now runs under the route's name, so it counts as
     * a refused «asking back» callback for the caller's address (AppServiceProvider::countRefusedGatewayCallback), a bucket
     * shared by PayPal, PayPal IPN and Mollie: 60 stray posts to a PayPal gateway the site does not have hold back the
     * genuine Mollie webhook from that address (503). Before #579 there was no route, so nothing was counted.
     */
    public function test_adv_579_1_stray_posts_to_a_gateway_without_a_record_do_not_hold_back_another_gateways_webhook(): void
    {
        $this->gateway('Mollie');
        $this->mock(Mollie::class, fn (MockInterface $mock) => $mock->shouldReceive('webhook')->andReturn(response('reached Mollie@webhook')));

        for ($i = 0; $i < 60; $i++) {
            $this->post('/extensions/paypal/webhook')->assertNotFound();
        }

        $response = $this->post('/extensions/gateways/mollie/webhook');
        $this->assertNotSame(503, $response->getStatusCode(), 'Mollie\'s webhook throttled by the 404s of a gateway the site does not have');
        $response->assertOk()->assertSee('reached Mollie@webhook');
    }

    /**
     * ADV-579-2 (medium, not money). The same bug, left for the «other» extensions: Announcements and Affiliates still add
     * their routes (routes/web.php, not routes.php) from boot(), so the cache keeps those of the deploy. Announcements
     * enabled in the admin after a deploy: /announcements is a 404 until the next deploy.
     */
    public function test_adv_579_2_an_other_extension_enabled_after_the_deploy_has_its_routes(): void
    {
        $this->extension('other', 'Announcements', enabled: true);
        $this->bootLikeTheSite(self::cache('empty', []));

        $this->assertNotNull(app('router')->getRoutes()->getByName('announcements.index'), 'announcements.index is not in the route cache');
        $this->assertNotSame(404, $this->get('/announcements')->getStatusCode());
    }

    /**
     * ADV-579-2, the other way: Announcements enabled at the deploy, disabled in the admin afterwards. Its routes stay in
     * the cache with no check, so the disabled extension keeps answering (its views and Livewire components, registered
     * by boot(), are then missing). Affiliates' admin API resources (/api/v1/admin/affiliates) the same.
     */
    public function test_adv_579_2_an_other_extension_disabled_after_the_deploy_no_longer_answers(): void
    {
        $this->extension('other', 'Announcements', enabled: false);
        $this->bootLikeTheSite(self::cache('deployed', [['other', 'Announcements', 1]]));

        $this->get('/announcements')->assertNotFound();
    }

    /**
     * Guard (passes on #579's head; keep it). #579's own tests build the cache from an empty database only; the deploy
     * builds it from the site's, where the gateways boot during `route:cache`. That cache must hold the same routes, each
     * behind EnsureExtensionBoots (a boot() that still required its routes.php would cache a second, unchecked copy), and
     * a gateway deleted after the deploy must then answer 404, its controller untouched.
     */
    public function test_guard_the_cache_built_with_the_gateways_booted_keeps_the_check_on_every_gateway_route(): void
    {
        $cache = self::cache('deployed', [['other', 'Announcements', 1]]);
        $this->gateway('Stripe')->delete();
        $this->bootLikeTheSite($cache);

        $names = ['stripe.webhook', 'stripe.setup-agreement', 'paypal.webhook', 'paypal.capture', 'paypal.setup-agreement',
            'paypal_ipn.notify', 'cardlink.return', 'mollie.webhook'];
        foreach ($names as $name) {
            $route = app('router')->getRoutes()->getByName('extensions.gateways.' . $name);
            $this->assertNotNull($route, $name);
            $this->assertSame(EnsureExtensionBoots::class, Str::before((string) ($route->gatherMiddleware()[0] ?? ''), ':'), $name);
        }

        $this->mock(Stripe::class, fn (MockInterface $mock) => $mock->shouldNotReceive('webhook'));
        $this->mock(Cardlink::class, fn (MockInterface $mock) => $mock->shouldNotReceive('callback'));
        $this->post('/extensions/stripe/webhook')->assertNotFound();
        $this->post('/extensions/cardlink/return')->assertNotFound();
    }

    private function gateway(string $name, bool $enabled = true): Gateway
    {
        return Gateway::query()->create(['name' => $name, 'extension' => $name, 'type' => 'gateway', 'enabled' => $enabled]);
    }

    private function extension(string $type, string $name, bool $enabled): Extension
    {
        return Extension::query()->create(['name' => $name, 'extension' => $name, 'type' => $type, 'enabled' => $enabled]);
    }

    /** A request on the site: the extensions boot (AppServiceProvider), then the cached routes replace the collection. */
    private function bootLikeTheSite(string $cache): void
    {
        foreach (ExtensionHelper::bootable() as $extension) {
            ExtensionHelper::call($extension, 'boot', mayFail: true);
        }
        require $cache;

        $this->assertInstanceOf(CompiledRouteCollection::class, app('router')->getRoutes());
    }

    /**
     * `artisan route:cache` in a subprocess. With no records, from a database with no tables (a fresh install); with
     * records, from a migrated database that holds every gateway's record and the given extensions', as on the site.
     *
     * @param  list<array{string, string, int}>  $records  [type, extension, enabled]
     */
    private static function cache(string $key, array $records): string
    {
        if (isset(self::$caches[$key])) {
            return self::$caches[$key];
        }
        $dir = sys_get_temp_dir() . '/mycs-adv579-' . Str::random(12);
        mkdir($dir);
        touch($database = $dir . '/site.sqlite');
        $env = ['APP_ROUTES_CACHE' => $dir . '/routes.php', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database];
        if ($records !== []) {
            $migrated = Process::path(base_path())->env($env)->timeout(300)->run([PHP_BINARY, 'artisan', 'migrate', '--force']);
            self::assertTrue($migrated->successful(), $migrated->output() . $migrated->errorOutput());
            $pdo = new PDO('sqlite:' . $database);
            $insert = $pdo->prepare('insert into extensions (name, extension, type, enabled, created_at, updated_at) values (?, ?, ?, ?, ?, ?)');
            $now = date('Y-m-d H:i:s');
            foreach (['Stripe', 'PayPal', 'PayPal_IPN', 'Cardlink', 'Mollie'] as $gateway) {
                $insert->execute([$gateway, $gateway, 'gateway', 1, $now, $now]);
            }
            foreach ($records as [$type, $extension, $enabled]) {
                $insert->execute([$extension, $extension, $type, $enabled, $now, $now]);
            }
            $pdo = null;
        }
        $result = Process::path(base_path())->env($env)->timeout(300)->run([PHP_BINARY, 'artisan', 'route:cache']);
        self::assertTrue($result->successful(), $result->output() . $result->errorOutput());

        return self::$caches[$key] = $dir . '/routes.php';
    }
}
