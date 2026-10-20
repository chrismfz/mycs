<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Coupon;
use App\Models\Extension;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * The WHMCS importer brings the gateway of each payment (tblaccounts.gateway: «Payment Method» on the order) and the
 * promotions (tblpromotions as coupons, tblhosting.promoid on the services). Parity, and no amount moves: a service's
 * price stays WHMCS's recurring amount, its coupon used up; a promotion MyCS would apply otherwise is closed for new
 * orders and reported.
 */
class ImportGatewaysPromotionsTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private BufferedOutput $output;

    private int $clientId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->clientId = User::factory()->create()->client()->id;
        $this->productId = $this->createProduct()->product->id;
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $this->whmcs->exec('CREATE TABLE tblpromotions (id INTEGER PRIMARY KEY, code TEXT, type TEXT, recurring INT, value REAL, cycles TEXT, appliesto TEXT,
            requires TEXT, requiresexisting INT, startdate TEXT, expirationdate TEXT, maxuses INT, uses INT, lifetimepromo INT, applyonce INT,
            newsignups INT, existingclient INT, onceperclient INT, recurfor INT, upgrades INT, notes TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, regdate TEXT, domain TEXT, qty INT, amount REAL,
            billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT, dedicatedip TEXT,
            assignedips TEXT, promoid INT)');
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
        $row = array_merge(['id' => $id, 'code' => $code, 'type' => 'Percentage', 'recurring' => 0, 'value' => 10, 'cycles' => '', 'appliesto' => (string) $this->productId,
            'requires' => '', 'requiresexisting' => 0, 'startdate' => '0000-00-00', 'expirationdate' => '0000-00-00', 'maxuses' => 0, 'uses' => 0, 'lifetimepromo' => 0,
            'applyonce' => 0, 'newsignups' => 0, 'existingclient' => 0, 'onceperclient' => 0, 'recurfor' => 0, 'upgrades' => 0, 'notes' => ''], $columns);
        $statement = $this->whmcs->prepare('INSERT INTO tblpromotions (' . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')');
        $statement->execute(array_values($row));
    }

    private function service(int $id, int $promo, float $amount = 7.50, string $status = 'Active'): void
    {
        $this->whmcs->exec("INSERT INTO tblhosting VALUES ({$id}, {$this->clientId}, NULL, {$this->productId}, '2025-01-15', 'shop.example', 1, {$amount},
            'Monthly', '2026-11-15', '{$status}', '', '', '', '', '', '', {$promo})");
    }

    private function payment(int $id, int $invoice, string $gateway): void
    {
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$invoice}, {$this->clientId}, '', '2026-09-01', '2026-09-10', '2026-09-03', 'Paid', 10, 0, 0)");
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES ({$invoice}, {$invoice}, {$this->clientId}, 'Hosting', 1, 'Line', 10, 0)");
        $this->whmcs->exec("INSERT INTO tblaccounts VALUES ({$id}, {$this->clientId}, {$invoice}, '{$gateway}', '2026-09-03 10:00:00', 10, 0, 0, 'tx_{$id}')");
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

    public function test_each_payment_keeps_its_gateway_installed_disabled_when_missing(): void
    {
        $this->payment(1, 101, 'stripe');
        $this->payment(2, 102, 'paypal_ppcpv');
        $this->payment(3, 103, 'paypal');
        $this->payment(4, 104, 'banktransfer');
        $this->payment(5, 105, 'eurobank');

        $this->import('importInvoices', 'importInvoiceItems', 'importPayments');

        $gateway = fn (int $id) => InvoiceTransaction::query()->findOrFail($id)->gateway?->extension;
        $this->assertSame(['Stripe', 'PayPal', 'PayPal', null, 'Cardlink'], [$gateway(1), $gateway(2), $gateway(3), $gateway(4), $gateway(5)]);
        $this->assertSame(1, Extension::query()->where('type', 'gateway')->where('extension', 'PayPal')->count(), 'one gateway for both PayPal modules');
        $this->assertFalse((bool) Extension::query()->where('extension', 'Stripe')->value('enabled'), 'installed, staff enable it');
        $this->assertStringContainsString('«banktransfer» imported without a gateway', $this->output->fetch());
    }

    public function test_an_already_installed_gateway_is_used(): void
    {
        $stripe = Extension::create(['name' => 'Stripe', 'type' => 'gateway', 'extension' => 'Stripe', 'enabled' => true]);
        $this->payment(1, 101, 'stripe');

        $this->import('importInvoices', 'importInvoiceItems', 'importPayments');

        $this->assertSame($stripe->id, InvoiceTransaction::query()->findOrFail(1)->gateway_id);
        $this->assertSame(1, Extension::query()->where('extension', 'Stripe')->count());
    }

    public function test_a_promotion_mycs_applies_as_whmcs_is_imported_open(): void
    {
        $this->promotion(7, 'SAVE10', ['type' => 'Percentage', 'value' => 10, 'recurring' => 1, 'recurfor' => 0, 'maxuses' => 50, 'onceperclient' => 1,
            'expirationdate' => '2027-01-31']);
        $this->promotion(8, 'NOSETUP', ['type' => 'Free Setup', 'value' => 0]);

        $this->import('importPromotions');

        $save = Coupon::query()->findOrFail(7);
        $this->assertSame(['SAVE10', 'percentage', 10.0, 'all', 0, 50, 1, '2027-01-31'],
            [$save->code, $save->type, $save->value, $save->applies_to, $save->recurring, $save->max_uses, $save->max_uses_per_user, $save->expires_at?->toDateString()]);
        $this->assertSame([$this->productId], $save->products->pluck('id')->all());
        $setup = Coupon::query()->findOrFail(8);
        $this->assertSame(['percentage', 100.0, 'setup_fee', null, null], [$setup->type, $setup->value, $setup->applies_to, $setup->recurring, $setup->expires_at]);
        $this->assertStringNotContainsString('closed', $this->output->fetch());
    }

    public function test_a_promotion_mycs_would_apply_otherwise_is_closed_and_reported(): void
    {
        $this->promotion(1, 'FREETRIAL', ['type' => 'Price Override', 'value' => 0, 'recurring' => 1, 'recurfor' => 2, 'newsignups' => 1, 'onceperclient' => 1]);
        $this->promotion(3, 'MOBIAN10', ['cycles' => 'Annually,Biennially', 'maxuses' => 1]);
        $this->promotion(4, 'MOBIAN25', ['value' => 25, 'recurring' => 1, 'recurfor' => 1, 'applyonce' => 1]);
        $this->promotion(5, 'OLD', ['expirationdate' => '2020-01-01', 'cycles' => 'Monthly']);

        $this->import('importPromotions');

        foreach ([1, 3, 4] as $id) {
            $this->assertSame('2026-10-20 09:59:59', Coupon::query()->findOrFail($id)->expires_at?->toDateTimeString(), "#{$id} closed for new orders");
        }
        $this->assertSame('2020-01-01', Coupon::query()->findOrFail(5)->expires_at?->toDateString(), 'expired already: its own date kept');
        $out = $this->output->fetch();
        $this->assertStringContainsString('Promotion #1 «FREETRIAL»: imported closed for new orders (type «Price Override»; recurring for 2 times; new clients only)', $out);
        $this->assertStringContainsString('some billing cycles only (Annually, Biennially)', $out);
        $this->assertStringContainsString('once per order', $out);
    }

    public function test_a_service_keeps_its_whmcs_price_and_its_promotion_used_up(): void
    {
        $this->promotion(2, '25OffAgentNode', ['type' => 'Price Override', 'value' => 1.5, 'recurring' => 1, 'recurfor' => 0]);
        $this->service(1599, 2, amount: 1.50);
        $this->service(1600, 0, amount: 10.00);

        $this->import('importPromotions', 'importServices');

        $service = Service::query()->findOrFail(1599);
        $this->assertSame([2, '1.50', '2025-01-15'], [$service->coupon_id, number_format((float) $service->price, 2), $service->coupon_started_at?->toDateString()]);
        $this->assertNotNull($service->coupon_expired_at, 'used up: its discount is in the price already');
        $this->assertSame((string) $service->calculatePrice(applyCoupon: false), (string) $service->calculatePrice(), 'never applied again on a recalculation');
        $this->assertNull(Service::query()->findOrFail(1600)->coupon_id);
    }

    public function test_a_service_whose_promotion_may_still_run_is_reported(): void
    {
        $this->promotion(1, 'FREETRIAL', ['type' => 'Price Override', 'value' => 0, 'recurring' => 1, 'recurfor' => 2]);
        $this->service(1700, 1, amount: 0.00);
        $this->service(1701, 1, amount: 0.00, status: 'Terminated');

        $this->import('importPromotions', 'importServices');

        $out = $this->output->fetch();
        $note = 'WHMCS promotion «FREETRIAL» still running at import (up to 2 more discounted renewals): the price 0 was kept; set the full price when it ends.';
        $this->assertStringContainsString("Service #1700: {$note}", $out);
        $this->assertStringContainsString($note, (string) Service::query()->findOrFail(1700)->notes, 'on the service, where staff see it');
        $this->assertStringNotContainsString('Service #1701', $out, 'ended: nothing to watch');
    }

    public function test_a_promotion_that_already_recurred_all_its_times_is_not_flagged(): void
    {
        $this->promotion(1, 'FREETRIAL', ['type' => 'Price Override', 'value' => 0, 'recurring' => 1, 'recurfor' => 2]);
        $this->service(1702, 1, amount: 20.00);
        $this->service(1703, 1, amount: 0.00);
        $this->whmcs->exec('ALTER TABLE tblhosting ADD COLUMN promocount INT'); // WHMCS counts the renewals it discounted
        $this->whmcs->exec('UPDATE tblhosting SET promocount = 2 WHERE id = 1702');
        $this->whmcs->exec('UPDATE tblhosting SET promocount = 1 WHERE id = 1703');

        $this->import('importPromotions', 'importServices');

        $out = $this->output->fetch();
        $this->assertStringNotContainsString('Service #1702', $out, 'ended in WHMCS: its full price is the one imported');
        $this->assertNull(Service::query()->findOrFail(1702)->notes);
        $this->assertStringContainsString('Service #1703: WHMCS promotion «FREETRIAL» still running at import (1 more discounted renewals)', $out);
    }

    public function test_a_promoid_of_a_promotion_not_imported_is_none(): void
    {
        $this->service(1800, 99);

        $this->import('importPromotions', 'importServices');

        $this->assertNull(Service::query()->findOrFail(1800)->coupon_id);
    }
}
