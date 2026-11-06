<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #650 (WHMCS promotions imported with their rules instead of closed). Each test imports a
 * tblpromotions row through ImportFromWhmcs::importPromotions and then orders, upgrades or checks the coupon through
 * the real services. They fail on the branch as reviewed. Fake data only.
 */
class Adv650PromotionsTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private BufferedOutput $output;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->client = User::factory()->create()->client();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblpromotions (id INTEGER PRIMARY KEY, code TEXT, type TEXT, recurring INT, value REAL, cycles TEXT, appliesto TEXT,
            requires TEXT, requiresexisting INT, startdate TEXT, expirationdate TEXT, maxuses INT, uses INT, lifetimepromo INT, applyonce INT,
            newsignups INT, existingclient INT, onceperclient INT, recurfor INT, upgrades INT, notes TEXT)');
        $this->whmcs->exec('CREATE TABLE tblorders (id INTEGER PRIMARY KEY, userid INT, date TEXT, notes TEXT, status TEXT, invoiceid INT, promocode TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, regdate TEXT, domain TEXT, qty INT, amount REAL,
            billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT, dedicatedip TEXT,
            assignedips TEXT, promoid INT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->client->id}, 1)");
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(float $price, string $name = 'Hosting', float $setupFee = 0): Product
    {
        $product = Product::factory()->create(['name' => $name]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => $setupFee, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    /** @param  array<string, mixed>  $columns */
    private function promotion(int $id, string $code, array $columns): void
    {
        $row = array_merge(['id' => $id, 'code' => $code, 'type' => 'Percentage', 'recurring' => 0, 'value' => 10, 'cycles' => '', 'appliesto' => '',
            'requires' => '', 'requiresexisting' => 0, 'startdate' => '0000-00-00', 'expirationdate' => '0000-00-00', 'maxuses' => 0, 'uses' => 0, 'lifetimepromo' => 0,
            'applyonce' => 0, 'newsignups' => 0, 'existingclient' => 0, 'onceperclient' => 0, 'recurfor' => 0, 'upgrades' => 0, 'notes' => ''], $columns);
        $statement = $this->whmcs->prepare('INSERT INTO tblpromotions (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')');
        $statement->execute(array_values($row));
    }

    private function import(string ...$steps): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
        foreach ($steps as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    private function order(Product $product, Coupon $coupon)
    {
        return (new PlaceOrderService)->place($this->client, 'USD', [new OrderLine($product, $product->plans()->firstOrFail()->load('prices'), 1, [])], $coupon);
    }

    private function money($amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * ADV-650-1 (high): «Lifetime Promotion» is inverted. In WHMCS a promotion WITHOUT «lifetimepromo» is dropped when the
     * service is upgraded or downgraded (the new product renews at its full price); only a lifetime one carries over.
     * MyCS keeps a coupon on an upgrade to any product it covers (ServiceUpgrade::newRecurringPrice), i.e. it behaves as
     * lifetime. The importer closes the lifetime ones ("kept on upgrades") and opens the others, which then carry over.
     * myip's zombee20 (20% forever, Biennially, 174-177, lifetimepromo 0) is open and 174 → 175/176/177 are real
     * upgrade paths; nixpal's FREETRIAL (override 0.00, 4,5,6) would follow an upgrade to the 10.00 Controller too.
     * Scenario: 20% forever on A (10.00) and B (30.00), not lifetime; order A, upgrade to B: WHMCS renews B at 30.00,
     * MyCS at 24.00 for ever.
     * Fix: import «not lifetime» as something MyCS honours (a coupon flag that ends it on a product change, ServiceUpgrade
     * expiring it), or close those with upgrade paths between the products they cover and report them.
     */
    public function test_adv_650_1_a_promotion_without_lifetime_is_dropped_on_an_upgrade(): void
    {
        $small = $this->product(10.00, 'Small');
        $big = $this->product(30.00, 'Big');
        $this->promotion(36, 'zombee20', ['value' => 20, 'recurring' => 1, 'recurfor' => 0, 'appliesto' => "{$small->id},{$big->id}", 'lifetimepromo' => 0]);
        $this->import('importPromotions');
        $coupon = Coupon::query()->findOrFail(36);
        $this->assertTrue($coupon->expires_at === null, 'precondition: imported open');

        $service = $this->order($small, $coupon)->order->services()->sole();
        $service->forceFill(['status' => Service::STATUS_ACTIVE, 'expires_at' => now()->addMonth()])->save();
        $this->assertSame('8.00', $this->money($service->price), 'precondition: 20% off on the ordered product');

        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $big->id, 'plan_id' => $big->plans()->firstOrFail()->id]);

        $this->assertSame('30.00', $this->money($upgrade->fresh()->newRecurringPrice()),
            'WHMCS drops a non-lifetime promotion on an upgrade: the new product renews at its full price');
    }

    /**
     * ADV-650-2 (medium, latent): «Once Per Client» forgets who used it. MyCS's once_per_client looks only at services
     * with coupon_id (Coupon::refusalFor); WHMCS counts the orders with the code (tblorders.promocode), and sets a
     * service's promoid back to 0 when its recurring promotion ends. nixpal: 184 clients ordered with FREETRIAL, only 16
     * still have a service with promoid 1 (the 76 Active services from FREETRIAL orders all have promoid 0). The order is
     * imported without its promocode, so the other 168 could use a once-per-client promotion again. FREETRIAL itself is
     * saved by «new signups»; one that is once per client only is not.
     * Scenario: ONCE (50%, once per client) used by the client in WHMCS, its service's promoid reset to 0; after the
     * import MyCS lets the client use it again.
     * Fix: bring tblorders.promocode (orders.coupon_id) and have refusalFor count orders too, or set coupon_id (expired)
     * on the services of those orders.
     */
    public function test_adv_650_2_once_per_client_remembers_whmcs_orders_with_the_code(): void
    {
        $product = $this->product(10.00);
        $this->promotion(60, 'ONCE', ['value' => 50, 'onceperclient' => 1, 'appliesto' => (string) $product->id]);
        $this->whmcs->exec("INSERT INTO tblorders VALUES (900, {$this->client->id}, '2025-01-15 10:00:00', '', 'Active', 0, 'ONCE')");
        // The service of that order, its promotion ended in WHMCS: promoid back to 0.
        $this->whmcs->exec("INSERT INTO tblhosting VALUES (901, {$this->client->id}, 900, {$product->id}, '2025-01-15', 'shop.example', 1, 10.00,
            'Monthly', '2026-11-15', 'Active', '', '', '', '', '', '', 0)");

        $this->import('importPromotions', 'importOrders', 'importServices');

        $coupon = Coupon::query()->findOrFail(60);
        $this->assertTrue($coupon->once_per_client && $coupon->expires_at === null, 'precondition: imported open, once per client');
        $this->assertNotNull($coupon->refusalFor($this->client->fresh(), [$product->id]),
            'the client used ONCE in WHMCS: once per client must refuse it again');
    }

    /**
     * ADV-650-3 (medium): a promotion with a negative value is imported open, and every order with it fails. The
     * importer takes WHMCS's value as is (the coupon form has minValue(0)); MyCS\Pricing\Discount refuses a negative
     * value with an InvalidArgumentException, so the cart and the checkout throw (a 500) for anyone who enters the code.
     * WHMCS has negative values (myip's Gr-Domain-Discount-FIXED-4.5 is −4.50, closed here only because it is for
     * domains): in WHMCS a negative Fixed Amount is a surcharge, a negative override a price below zero. Scenario: Price
     * Override −5.00 on a 10.00 product, imported open; placing the order throws.
     * Fix: close (and report) a promotion with a negative value, as the form refuses one.
     */
    public function test_adv_650_3_a_negative_value_is_closed_not_a_broken_open_coupon(): void
    {
        $product = $this->product(10.00);
        $this->promotion(70, 'MINUS', ['type' => 'Price Override', 'value' => -5, 'recurring' => 1, 'appliesto' => (string) $product->id]);
        $this->import('importPromotions');
        $coupon = Coupon::query()->findOrFail(70);

        if ($coupon->expires_at !== null && $coupon->expires_at->isPast()) {
            $this->addToAssertionCount(1); // closed and reported: fine

            return;
        }
        try {
            $placed = $this->order($product, $coupon);
        } catch (\InvalidArgumentException $e) {
            $this->fail("an open imported coupon must not break the order: {$e->getMessage()}");
        }
        $this->assertGreaterThanOrEqual(0, (float) $placed->order->services()->sole()->price, 'a coupon never makes a price negative');
    }

    /**
     * ADV-650-4 (low): a product listed twice in «Requires» closes a promotion MyCS can express. appliesto is
     * de-duplicated (count(array_unique)), requires is not: «5,5» gives one id against two entries and the promotion
     * is closed as "requires addons, domains or products not imported". Not money given away (the opposite), but a
     * valid promotion is lost and the reason said is wrong.
     * Fix: compare against count(array_unique($requires)), as for appliesto.
     */
    public function test_adv_650_4_a_product_required_twice_is_still_one_product(): void
    {
        $product = $this->product(10.00);
        $required = $this->product(5.00, 'Agent');
        $this->promotion(80, 'TWICE', ['appliesto' => (string) $product->id, 'requires' => "{$required->id},{$required->id}"]);

        $this->import('importPromotions');

        $coupon = Coupon::query()->findOrFail(80);
        $this->assertNull($coupon->expires_at, 'every required product is imported: open');
        $this->assertSame([$required->id], $coupon->requiredProducts->pluck('id')->all());
    }
}
