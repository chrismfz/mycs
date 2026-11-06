<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\User;
use App\Services\Billing\ClientGroupPricing;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Services\ServiceUpgrade\ServiceUpgradeService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
 * Adversarial review (2) of #641 @ be3038a1: services.undiscounted_price (WHMCS's amount) is cleared only when `price` is
 * saved dirty. In a 100% group the price is 0.00 before and after any change of product, cycle or options (the computed
 * price at 100% is 0.00 again), so `price` is never dirty and the old product's / old cycle's WHMCS amount survives. When
 * the account leaves the group, ClientGroupPricing reprices from that stale amount before the plan branches.
 */
class Adv641bUndiscountedPriceTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private Product $product;

    private Plan $monthly;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-02 10:00:00');
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        foreach (['default_currency' => ['USD', 'string'], 'credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean']] as $key => [$value, $type]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $type]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, name TEXT, servertype TEXT, servergroup INT)');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, orderid INT, packageid INT, server INT, regdate TEXT, domain TEXT,
            qty INT, amount REAL, billingcycle TEXT, nextduedate TEXT, domainstatus TEXT, username TEXT, password TEXT, notes TEXT, suspendreason TEXT,
            dedicatedip TEXT, assignedips TEXT, overideautosuspend INT, overidesuspenduntil TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'USD')");

        $this->product = Product::factory()->create(['name' => 'Hosting A', 'server_id' => null, 'stock' => null]);
        $this->monthly = $this->plan($this->product, 'Monthly', 'month', 10.00);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Held: a paid upgrade to another product in a 100% group. ServiceUpgradeService saves calculatePrice() as a string,
     * which reads dirty against the stored 0.00, so the old product's amount is dropped and the new one's 30.00 comes.
     */
    public function test_holds_an_upgrade_to_another_product_in_a_100_percent_group_drops_the_old_amount(): void
    {
        $other = Product::factory()->create(['name' => 'Hosting B', 'server_id' => null, 'stock' => null]);
        $otherPlan = $this->plan($other, 'Monthly', 'month', 30.00);
        $client = $this->clientInGroup(100);
        $service = $this->imported($client, 62.90);

        $upgrade = ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $other->id, 'plan_id' => $otherPlan->id, 'status' => 'pending']);
        (new ServiceUpgradeService)->handle($upgrade);
        $service->refresh();
        $this->assertSame($other->id, (int) $service->product_id);
        $this->assertSame('0.00', number_format((float) $service->price, 2, '.', ''));
        $this->assertNull($service->undiscounted_price, 'the old product\'s WHMCS amount is no longer this service\'s');

        $client->forceFill(['client_group_id' => null])->save();
        ClientGroupPricing::reconcile();
        $this->assertSame('30.00', number_format((float) $service->refresh()->price, 2, '.', ''), 'the new product\'s price, not the old one\'s 62.90');
    }

    /**
     * ADV-641-3: a change of cycle «now» in a 100% group (staff). ChangeCycle saves `(float) 0.00` over the stored 0.00:
     * `price` is not dirty, the observer keeps the MONTHLY WHMCS amount (62.90) on a YEARLY service. Out of the group the
     * reconcile reprices from it: a year for 62.90 instead of the yearly plan's 100.00 (the same with any 100% service whose
     * plan changes and whose price stays 0.00).
     */
    public function test_a_change_of_cycle_in_a_100_percent_group_drops_the_old_cycles_amount(): void
    {
        $yearly = $this->plan($this->product, 'Yearly', 'year', 100.00);
        $client = $this->clientInGroup(100);
        $service = $this->imported($client, 62.90);

        ChangeCycle::apply($service, $yearly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        $this->assertSame($yearly->id, (int) $service->fresh()->plan_id);

        $client->forceFill(['client_group_id' => null])->save();
        ClientGroupPricing::reconcile();
        $this->assertSame('100.00', number_format((float) $service->refresh()->price, 2, '.', ''), 'a year at the yearly plan\'s price, not 62.90');
    }

    /**
     * ADV-641-4: the same change «from the next renewal», applied by the cron (RenewalBilling: plan_id = next,
     * price = (float) calculatePrice(), 0.00 again): the monthly amount stays on the yearly plan.
     */
    public function test_a_change_of_cycle_at_renewal_in_a_100_percent_group_drops_the_old_cycles_amount(): void
    {
        Setting::query()->updateOrCreate(['key' => 'cronjob_invoice', 'settingable_type' => null], ['value' => 7, 'type' => 'integer']);
        config(['settings.cronjob_invoice' => 7]);
        Cache::forget('settings.rows');
        $yearly = $this->plan($this->product, 'Yearly', 'year', 100.00);
        $client = $this->clientInGroup(100);
        $service = $this->imported($client, 62.90);

        ChangeCycle::apply($service, $yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);
        Carbon::setTestNow('2026-07-28 10:00:00');
        $this->artisan('app:cron-job');
        $this->assertSame($yearly->id, (int) $service->fresh()->plan_id, 'the cron applied the yearly plan');

        Carbon::setTestNow('2026-07-29 10:00:00');
        $client->forceFill(['client_group_id' => null])->save();
        ClientGroupPricing::reconcile();
        $this->assertSame('100.00', number_format((float) $service->refresh()->price, 2, '.', ''), 'a year at the yearly plan\'s price, not 62.90');
    }

    private function plan(object $owner, string $name, string $unit, float $price, string $type = Product::class): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $owner->id, 'priceable_type' => $type, 'name' => $name,
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan;
    }

    private function clientInGroup(int $percent): Client
    {
        $client = User::factory()->create()->client();
        $group = DB::table('client_groups')->insertGetId(['name' => "Off {$percent}", 'discount_percent' => $percent, 'created_at' => now(), 'updated_at' => now()]);
        $client->forceFill(['client_group_id' => $group])->save();
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$client->id}, 1)");

        return $client;
    }

    /** A WHMCS service of product A, monthly, at a custom $amount, imported. */
    private function imported(Client $client, float $amount): Service
    {
        $this->whmcs->prepare("INSERT INTO tblhosting VALUES (941, ?, NULL, ?, 0, '2024-01-01', 'site.example.test', 1, ?, 'Monthly', '2026-08-01', 'Active',
            '', '', '', '', '', '', 0, '0000-00-00')")->execute([$client->id, $this->product->id, $amount]);
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importServices'))->invoke($command);
        $service = Service::query()->findOrFail(941);
        $this->assertSame((int) $this->monthly->id, (int) $service->plan_id);
        $this->assertSame('0.00', number_format((float) $service->price, 2, '.', ''));
        $this->assertSame(number_format($amount, 2, '.', ''), number_format((float) $service->undiscounted_price, 2, '.', ''));

        return $service;
    }
}
