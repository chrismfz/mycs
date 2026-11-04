<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Client;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\ClientGroupPricing;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #641 (WG-1): the importer now writes WHMCS's amount less the group discount, with the group's
 * percent. WHMCS keeps the full amount on the service (tblhosting.amount): when the account leaves the group, WHMCS bills
 * that amount again. Here the full amount is gone; the cron's reconcile has to rebuild it from the discounted price.
 */
class Adv641GroupDiscountImportTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, name TEXT, servertype TEXT, servergroup INT)');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, server INT, regdate TEXT, domain TEXT,
            qty INT, amount REAL, billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT,
            dedicatedip TEXT, assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");
    }

    /**
     * ADV-641-1: a 100% group. The import writes 0.00 (amount − 100%); on a service whose plan has no price in its currency
     * (a cycle the product does not sell: the import's own unpriced plan) the reconcile's «whole discount» branch computes
     * the price from the plan, which is its own 0.00. Leaving the group, it renews free for good; WHMCS bills the amount.
     * Before #641 the price kept the amount and the same branch gave it back.
     */
    public function test_leaving_a_100_percent_group_brings_back_whmcss_amount_on_an_unpriced_plan(): void
    {
        $product = $this->createProduct(); // a Monthly plan only
        [$client, $group] = $this->clientInGroup(100);
        $this->hostingRow(931, $client->id, $product->product->id, 62.9, 'Quarterly');

        $this->importServices();
        $service = Service::query()->findOrFail(931);
        $this->assertSame('0.00', number_format((float) $service->price, 2, '.', ''));

        $client->forceFill(['client_group_id' => null])->save();
        ClientGroupPricing::reconcile();

        $this->markTestIncomplete('ADV-641-1, open for the owner: neither company has a 100% group (nixpal 10% and 50%, myip none),'
            . ' and any service put in a 100% group here loses its price the same way (ClientGroupPricing, before #641 too).');
        $this->assertSame('62.90', number_format((float) $service->refresh()->price, 2, '.', ''), 'WHMCS bills its amount once the account leaves the group');
    }

    /**
     * ADV-641-2: a custom amount (not the catalog's) whose discount rounds: 1.85 at 10% is imported as 1.66 (WHMCS's
     * 1.85 − 0.19). Leaving the group, the reconcile scales it back by ratio: 1.66 × 100 / 90 = 1.84, a cent short of
     * WHMCS's 1.85 every cycle (the amount it no longer has).
     */
    public function test_leaving_a_group_brings_back_whmcss_exact_custom_amount(): void
    {
        $product = $this->createProduct();
        [$client] = $this->clientInGroup(10);
        $this->hostingRow(932, $client->id, $product->product->id, 1.85, 'Monthly');

        $this->importServices();
        $service = Service::query()->findOrFail(932);
        $this->assertSame('1.66', number_format((float) $service->price, 2, '.', ''));

        $client->forceFill(['client_group_id' => null])->save();
        ClientGroupPricing::reconcile();

        $this->markTestIncomplete('ADV-641-2, open for the owner: a cent, only when the account leaves its group, on a custom amount'
            . ' whose discount rounds; nixpal\'s group account has 1.80 and 5.00 only (exact). The ratio scaling of any custom price'
            . ' does the same here (ClientGroupPricing, before #641 too).');
        $this->assertSame('1.85', number_format((float) $service->refresh()->price, 2, '.', ''), 'WHMCS bills its amount once the account leaves the group');
    }

    /** @return array{0: Client, 1: int} */
    private function clientInGroup(int $percent): array
    {
        $client = User::factory()->create()->client();
        $group = DB::table('client_groups')->insertGetId(['name' => "Off {$percent}", 'discount_percent' => $percent, 'created_at' => now(), 'updated_at' => now()]);
        $client->forceFill(['client_group_id' => $group])->save();
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$client->id}, 1)");

        return [$client, $group];
    }

    private function hostingRow(int $id, int $clientId, int $productId, float $amount, string $cycle): void
    {
        $this->whmcs->prepare("INSERT INTO tblhosting VALUES (?, ?, NULL, ?, 0, '2024-01-01', 'site.example.test', 1, ?, ?, '2026-12-01', 'Active', '',
            '', '', '', '', '', 0, '0000-00-00')")->execute([$id, $clientId, $productId, $amount, $cycle]);
    }

    private function importServices(): string
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        (new ReflectionMethod($command, 'importServices'))->invoke($command);

        return $output->fetch();
    }
}
