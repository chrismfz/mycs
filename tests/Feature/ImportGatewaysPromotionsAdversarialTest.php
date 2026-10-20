<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Exceptions\CouponUnusableException;
use App\Helpers\ExtensionHelper;
use App\Models\Coupon;
use App\Models\InvoiceTransaction;
use App\Models\Product;
use App\Models\User;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of the importer's gateways and promotions (e4d3dac): where money can move because of them.
 * Each test fails on e4d3dac and names the finding (ADV-n) it shows.
 */
class ImportGatewaysPromotionsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private BufferedOutput $output;

    private User $user;

    private int $clientId;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->user = User::factory()->create();
        $this->clientId = $this->user->client()->id;
        $this->product = $this->createProduct()->product;
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        // As WHMCS: a refund is its own row (amountout, refundid = the payment it gives back).
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT, refundid INT)');
        // Every WHMCS has it: the import counts the services of a promotion.
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, promoid INT, qty INT)');
        $this->whmcs->exec('CREATE TABLE tblpromotions (id INTEGER PRIMARY KEY, code TEXT, type TEXT, recurring INT, value REAL, cycles TEXT, appliesto TEXT,
            requires TEXT, requiresexisting INT, startdate TEXT, expirationdate TEXT, maxuses INT, uses INT, lifetimepromo INT, applyonce INT,
            newsignups INT, existingclient INT, onceperclient INT, recurfor INT, upgrades INT, notes TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $columns */
    private function promotion(int $id, string $code, array $columns = []): void
    {
        $row = array_merge(['id' => $id, 'code' => $code, 'type' => 'Percentage', 'recurring' => 0, 'value' => 10, 'cycles' => '', 'appliesto' => (string) $this->product->id,
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

    private function order(Coupon $coupon): void
    {
        $product = $this->product->fresh();
        (new PlaceOrderService)->place($this->user->client(), 'USD', [new OrderLine($product, $product->plans()->first())], $coupon);
    }

    /**
     * ADV-1. WHMCS gave a Stripe payment back to the client's credit balance (or «record only», a bank refund): a
     * tblaccounts row with amountout and refundid. The importer brings only amountin, so in MyCS the payment is still
     * wholly refundable, and since e4d3dac it carries the Stripe gateway: «Refund» through the gateway (Cancel & Refund's
     * default) sends Stripe a real refund of money the client already got back. Before, gateway null refused it.
     */
    public function test_a_payment_whmcs_refunded_already_is_not_refunded_again_through_the_gateway(): void
    {
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (101, {$this->clientId}, '', '2026-09-01', '2026-09-10', '2026-09-03', 'Refunded', 10, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES (101, 101, {$this->clientId}, 'Hosting', 1, 'Line', 10, 0)");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (1, {$this->clientId}, 101, 'stripe', '2026-09-03 10:00:00', 10, 0, 0.59, 'pi_whmcs_1', 0)");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES (2, {$this->clientId}, 101, 'stripe', '2026-09-05 10:00:00', 0, 10, 0, '', 1)");

        $this->import('importInvoices', 'importInvoiceItems', 'importPayments');

        $payment = InvoiceTransaction::query()->findOrFail(1);
        $this->assertSame(0.0, (float) $payment->refundable_amount, 'WHMCS refunded it: nothing left to give back');

        Http::fake(['api.stripe.com/*' => Http::response(['id' => 're_again'])]);
        try {
            ExtensionHelper::refund($payment, 10.00, notify: false);
        } catch (\Throwable) {
            // refused: what should happen
        }
        Http::assertNothingSent();
    }

    /**
     * ADV-2. tblpromotions.uses is not read: a promotion WHMCS used up (uses = maxuses, on orders whose services were
     * deleted, or on domains/addons) is counted in MyCS only from the imported services that carry it, so it comes back
     * with all its uses free and discounts new orders WHMCS would refuse.
     */
    public function test_a_promotion_whmcs_used_up_is_not_usable_again(): void
    {
        $this->promotion(9, 'LAUNCH', ['maxuses' => 3, 'uses' => 3]);

        $this->import('importPromotions');

        $this->expectException(CouponUnusableException::class);
        $this->order(Coupon::query()->findOrFail(9));
    }

    /**
     * ADV-3. An empty «Applies To» discounts nothing in WHMCS (it applies to the products listed), but no coupon_products
     * in MyCS means every product (Coupon::covers): the promotion is imported open and discounts the whole catalog.
     */
    public function test_a_promotion_that_applies_to_nothing_does_not_discount_every_product(): void
    {
        $this->promotion(10, 'NOTHING', ['appliesto' => '', 'value' => 50, 'recurring' => 1]);

        $this->import('importPromotions');

        $coupon = Coupon::query()->findOrFail(10);
        $this->assertTrue($coupon->expires_at?->isPast() || !$coupon->covers($this->product->id),
            'WHMCS discounts no product with it: closed, or covering none');
    }

    /**
     * ADV-4. WHMCS's expiration date is the last day the code works (expired only when the date is before today);
     * imported as that day at 00:00, the coupon stops working a day early (customer-unfavourable parity break).
     */
    public function test_a_promotion_works_through_its_expiration_day(): void
    {
        $this->promotion(11, 'UNTIL25', ['expirationdate' => '2026-10-25']);

        $this->import('importPromotions');

        Carbon::setTestNow('2026-10-25 15:00:00');
        $this->assertFalse((bool) Coupon::query()->findOrFail(11)->expires_at?->isPast(), 'still valid on its last day, as in WHMCS');
    }
}
