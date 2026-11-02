<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\PaymentGatewayEvent;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use MyCS\Extensions\Gateways\Cardlink\Cardlink;
use MyCS\Extensions\Gateways\Mollie\Mollie;
use MyCS\Extensions\Gateways\PayPal\PayPal;
use MyCS\Extensions\Gateways\PayPal_IPN\PayPal_IPN;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use Tests\TestCase;

/**
 * The extensions' routes with the routes cached, as on the site after `deploy/update.sh` (`artisan optimize`). They were
 * added by each extension's boot(), so the cache held those of the gateways there were when it was built: a gateway
 * created after the deploy had none, and Stripe's events found a 404 (2026-11-02). They are now the app's own routes
 * (routes/extensions.php), cached whatever the database holds, and answer while their extension has a record.
 */
class ExtensionRoutesCacheTest extends TestCase
{
    use RefreshDatabase;

    /** [extension class, method, URI, controller method]: each gateway's webhook, return or callback. */
    private const ENDPOINTS = [
        [Stripe::class, 'POST', '/extensions/stripe/webhook', 'webhook'],
        [PayPal::class, 'POST', '/extensions/paypal/webhook', 'webhook'],
        [PayPal_IPN::class, 'POST', '/extensions/paypal_ipn/notify', 'notify'],
        [Cardlink::class, 'POST', '/extensions/cardlink/return', 'callback'],
        [Cardlink::class, 'GET', '/extensions/cardlink/return', 'callback'],
        [Mollie::class, 'POST', '/extensions/gateways/mollie/webhook', 'webhook'],
    ];

    /** The cache file, built once for the class (a whole `artisan route:cache` each time is slow). */
    private static ?string $cache = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$cache !== null) {
            array_map('unlink', glob(dirname(self::$cache) . '/*') ?: []);
            @rmdir(dirname(self::$cache));
            self::$cache = null;
        }
        parent::tearDownAfterClass();
    }

    public function test_each_gateway_route_reaches_its_controller_from_the_route_cache(): void
    {
        foreach (array_unique(array_column(self::ENDPOINTS, 0)) as $class) {
            $this->gateway($class);
        }
        $this->bootLikeTheSite();

        foreach (self::ENDPOINTS as [$class, $method, $uri, $action]) {
            $this->reached($class, $action);
            $this->call($method, $uri)->assertOk()->assertSee('reached ' . class_basename($class) . '@' . $action);
        }
        // By name too: Stripe gives its webhook's URL to Stripe, PayPal its return URL to PayPal.
        $this->assertSame(url('/extensions/stripe/webhook'), route('extensions.gateways.stripe.webhook'));
        $this->assertSame(url('/extensions/paypal/setup-agreement'), route('extensions.gateways.paypal.setup-agreement'));
    }

    public function test_a_disabled_gateway_still_answers_its_provider(): void
    {
        // A payment taken while it was enabled is still refunded or disputed: its events must reach it.
        $this->gateway(Stripe::class, enabled: false);
        $this->bootLikeTheSite();
        $this->reached(Stripe::class, 'webhook');

        $this->post('/extensions/stripe/webhook')->assertOk()->assertSee('reached Stripe@webhook');
    }

    public function test_a_gateway_without_a_record_answers_404(): void
    {
        // As before, when only the gateways there were booted and added their routes: nothing to read settings from.
        $this->gateway(Stripe::class);
        $this->bootLikeTheSite();
        $this->mock(PayPal::class, fn (MockInterface $mock) => $mock->shouldNotReceive('webhook'));
        $this->mock(Cardlink::class, fn (MockInterface $mock) => $mock->shouldNotReceive('callback'));

        $this->post('/extensions/paypal/webhook')->assertNotFound();
        $this->post('/extensions/cardlink/return')->assertNotFound();
        $this->get('/extensions/cardlink/return')->assertNotFound();
    }

    public function test_a_deleted_gateway_answers_404_though_its_route_was_cached(): void
    {
        $this->gateway(Stripe::class)->delete();
        $this->bootLikeTheSite();
        $this->mock(Stripe::class, fn (MockInterface $mock) => $mock->shouldNotReceive('webhook'));

        $this->post('/extensions/stripe/webhook')->assertNotFound();
    }

    public function test_a_gateway_without_a_record_is_neither_logged_nor_counted_as_a_refused_callback(): void
    {
        // Review of #579: its route now matches, so the gateway log opened a row and the refused-callback throttle
        // counted it. As a path that matches no route: nothing, and the installed gateways' callbacks are not held back.
        $this->gateway(Mollie::class);
        $this->bootLikeTheSite();
        $this->reached(Mollie::class, 'webhook');

        for ($i = 0; $i <= AppServiceProvider::GATEWAY_REFUSED_CALLBACKS_PER_MINUTE; $i++) {
            $this->post('/extensions/paypal/webhook')->assertNotFound();
        }
        $this->assertSame(0, PaymentGatewayEvent::query()->count());
        $this->post('/extensions/gateways/mollie/webhook')->assertOk()->assertSee('reached Mollie@webhook');
    }

    public function test_the_cached_routes_keep_their_middleware(): void
    {
        $this->gateway(Stripe::class);
        $this->gateway(PayPal::class);
        $this->bootLikeTheSite();

        // Saving a payment method is the logged-in client's (web, auth, client.can:invoices): a guest goes to log in.
        $this->get('/extensions/stripe/setup-agreement')->assertRedirect(route('login'));
        $this->get('/extensions/paypal/setup-agreement')->assertRedirect(route('login'));
        // The providers post without a session or a CSRF token.
        foreach (['extensions.gateways.stripe.webhook', 'extensions.gateways.mollie.webhook', 'extensions.gateways.cardlink.return'] as $name) {
            $this->assertNotContains('web', app('router')->getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    /** A gateway's record, as the admin's Gateways page makes it. */
    private function gateway(string $class, bool $enabled = true): Gateway
    {
        $name = class_basename($class);

        return Gateway::query()->create(['name' => $name, 'extension' => $name, 'type' => 'gateway', 'enabled' => $enabled]);
    }

    /**
     * A request on the site: the extensions boot (AppServiceProvider), then the cached routes replace the collection
     * (RouteServiceProvider::loadCachedRoutes, on `booted`). The cache is `artisan route:cache`'s own, built with no
     * gateway in its database, as at a deploy before the gateways were created.
     */
    private function bootLikeTheSite(): void
    {
        foreach (ExtensionHelper::bootable() as $extension) {
            ExtensionHelper::call($extension, 'boot', mayFail: true);
        }
        require self::cache();

        $this->assertInstanceOf(CompiledRouteCollection::class, app('router')->getRoutes());
    }

    private static function cache(): string
    {
        if (self::$cache === null) {
            $dir = sys_get_temp_dir() . '/mycs-route-cache-' . Str::random(12);
            mkdir($dir);
            touch($database = $dir . '/empty.sqlite');
            $result = Process::path(base_path())->env([
                'APP_ROUTES_CACHE' => $dir . '/routes.php',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $database, // no tables: no extension boots, as a fresh install
            ])->run([PHP_BINARY, 'artisan', 'route:cache']);
            self::assertTrue($result->successful(), $result->output() . $result->errorOutput());
            self::$cache = $dir . '/routes.php';
        }

        return self::$cache;
    }

    /** The extension's controller, answering only to say it was reached. */
    private function reached(string $class, string $action): void
    {
        $this->mock($class, fn (MockInterface $mock) => $mock->shouldReceive($action)
            ->andReturn(response('reached ' . class_basename($class) . '@' . $action)));
    }
}
