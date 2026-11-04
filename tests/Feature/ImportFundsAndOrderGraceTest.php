<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Setting;
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
 * WG-3 and WG-4: the «Add Funds» limits and the «Order Days Grace» come with the import. At MyCS's defaults (5 / 100 / 300,
 * 7 days) myip's customers could not deposit what they did in WHMCS (13 of 23 deposits last year above 100), and a new
 * order's invoice was due in 7 days where WHMCS gave 8 (myip) or the same day (nixpal).
 */
class ImportFundsAndOrderGraceTest extends TestCase
{
    use RefreshDatabase;

    private BufferedOutput $output;

    /** @param array<string, string> $config */
    private function import(array $config): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblconfiguration (setting TEXT PRIMARY KEY, value TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, credit REAL DEFAULT 0)');
        $insert = $whmcs->prepare('INSERT INTO tblconfiguration VALUES (?, ?)');
        foreach ($config as $setting => $value) {
            $insert->execute([$setting, $value]);
        }
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
        (new ReflectionMethod($command, 'importAutomationSettings'))->invoke($command);
    }

    /** @return array<string, ?string> */
    private function settings(): array
    {
        $keys = ['credits_minimum_deposit', 'credits_maximum_deposit', 'credits_maximum_credit', 'order_days_grace'];
        $stored = Setting::query()->whereNull('settingable_type')->whereIn('key', $keys)->get()->mapWithKeys(fn (Setting $s) => [$s->key => $s->getRawOriginal('value')]);

        return array_combine($keys, array_map(fn (string $key) => $stored[$key] ?? null, $keys));
    }

    public function test_myips_and_nixpals_limits_and_grace_come(): void
    {
        $this->import(['AddFundsMinimum' => '1.00', 'AddFundsMaximum' => '5000.00', 'AddFundsMaximumBalance' => '10000.00', 'OrderDaysGrace' => '8']);
        $this->assertSame(['credits_minimum_deposit' => '1.00', 'credits_maximum_deposit' => '5000.00', 'credits_maximum_credit' => '10000.00',
            'order_days_grace' => '8'], $this->settings());

        $this->import(['AddFundsMinimum' => '2.00', 'AddFundsMaximum' => '1000.00', 'AddFundsMaximumBalance' => '1000.00', 'OrderDaysGrace' => '0']);
        $this->assertSame(['credits_minimum_deposit' => '2.00', 'credits_maximum_deposit' => '1000.00', 'credits_maximum_credit' => '1000.00',
            'order_days_grace' => '0'], $this->settings(), 'nixpal: due the same day');
    }

    public function test_odd_values_keep_mycs_own(): void
    {
        $before = $this->settings();
        // Not a number, a maximum of 0 (it would refuse every deposit), a negative grace.
        $this->import(['AddFundsMinimum' => 'five', 'AddFundsMaximum' => '0.00', 'AddFundsMaximumBalance' => '-1', 'OrderDaysGrace' => '-3']);
        $this->assertSame($before, $this->settings());

        // A minimum above the maximum: neither, and said.
        $this->import(['AddFundsMinimum' => '50', 'AddFundsMaximum' => '20', 'AddFundsMaximumBalance' => '300']);
        $this->assertSame(['credits_minimum_deposit' => $before['credits_minimum_deposit'], 'credits_maximum_deposit' => $before['credits_maximum_deposit'],
            'credits_maximum_credit' => '300.00', 'order_days_grace' => $before['order_days_grace']], $this->settings());
        $this->assertStringContainsString('minimum deposit 50.00 would be above the maximum 20.00', $this->output->fetch());

        // Review of #648: WHMCS's minimum beside MyCS's own maximum (WHMCS's 0.00 is none) is the pair that counts.
        $this->storeSettings(['credits_minimum_deposit' => '5', 'credits_maximum_deposit' => '100']);
        $this->import(['AddFundsMinimum' => '200.00', 'AddFundsMaximum' => '0.00']);
        $this->assertSame(['5', '100'], [$this->settings()['credits_minimum_deposit'], $this->settings()['credits_maximum_deposit']]);
    }

    public function test_a_new_orders_invoice_is_due_after_the_grace(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-11-04 15:00:00');
        $client = User::factory()->create()->client();
        $product = $this->createProduct()->product;
        $place = fn () => (new PlaceOrderService)->place($client, 'USD', [new OrderLine($product, $product->plans()->first())])->invoice;

        $this->assertSame('2026-11-11', $place()->due_at->toDateString(), 'MyCS\'s default: 7 days');

        $this->storeSettings(['order_days_grace' => '8']);
        $this->assertSame('2026-11-12', $place()->due_at->toDateString(), 'myip: 8');

        $this->storeSettings(['order_days_grace' => '0']);
        $this->assertSame('2026-11-04', $place()->due_at->toDateString(), 'nixpal: the same day');

        // Review of #648: never past ten years, which a date column holds.
        $this->storeSettings(['order_days_grace' => '99999999']);
        $this->assertSame('2036-11-01', $place()->due_at->toDateString());
    }
}
