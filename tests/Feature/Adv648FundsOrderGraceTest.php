<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Livewire\Client\Credits;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #648 (WHMCS «Add Funds» limits and «Order Days Grace» come with the import, WG-3/WG-4). Each
 * ADV test fails on the PR head for a defect.
 */
class Adv648FundsOrderGraceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('MyCS\\Extensions\\Gateways\\Adv648Pay\\Adv648Pay')) {
            eval('namespace MyCS\\Extensions\\Gateways\\Adv648Pay; class Adv648Pay {
                public $explicitConfig = false;
                public function __construct($config = []) {}
                public function getConfig($values = []) { return []; }
                public function config($key) { return null; }
                public function pay($invoice, $total) { return "https://adv648.test/pay"; }
            }');
        }
        Gateway::query()->create(['extension' => 'Adv648Pay', 'name' => 'Adv648Pay', 'type' => 'gateway', 'enabled' => true]);
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        config(['settings.credits_auto_use' => false]);
    }

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
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importAutomationSettings'))->invoke($command);
        $this->storeSettings(['credits_enabled' => true]); // also reloads what the import stored
    }

    private function deposit(string $amount): ?Invoice
    {
        $this->livewireAs($this->user)->test(Credits::class)->set('amount', $amount)->call('addCredit');

        return Invoice::query()->where('client_id', $this->client->id)->latest('id')->first();
    }

    /**
     * ADV-648-1. The importer checks «minimum above maximum» only when it imports both. A WHMCS minimum that comes with a
     * maximum it does not take (0.00, empty, «1.000,00») lands next to MyCS's own maximum (100): a minimum of 150 then
     * refuses every deposit, the very case the check is there for.
     */
    public function test_adv_648_1_a_minimum_is_never_above_the_maximum_it_lands_next_to(): void
    {
        $this->import(['AddFundsMinimum' => '150.00', 'AddFundsMaximum' => '0.00', 'AddFundsMaximumBalance' => '10000.00']);

        $min = (string) config('settings.credits_minimum_deposit');
        $max = (string) config('settings.credits_maximum_deposit');
        $this->assertLessThanOrEqual(0, bccomp($min, $max, 2), "minimum $min above maximum $max: no deposit can be made");
        $this->assertNotNull($this->deposit($min), 'a deposit of exactly the minimum is refused');
    }

    /**
     * ADV-648-2. A maximum deposit above the maximum balance is imported as is (here WHMCS's balance limit is not taken,
     * so MyCS's 300 stays next to a 5,000 deposit). Add Funds checks the balance limit only when the customer already has
     * a credit row in the currency: a customer without one buys 5,000 of credit past the 300 cap, one with 0.01 is refused.
     */
    public function test_adv_648_2_a_first_deposit_does_not_go_past_the_maximum_balance(): void
    {
        $this->import(['AddFundsMinimum' => '1.00', 'AddFundsMaximum' => '5000.00', 'AddFundsMaximumBalance' => 'none']);
        $this->assertSame('300', (string) config('settings.credits_maximum_credit'), 'precondition: MyCS\'s own balance limit kept');

        $invoice = $this->deposit('5000');
        $this->assertNull($invoice, 'a credit invoice of 5000 was made though the balance may not exceed 300');
    }

    /**
     * ADV-648-3. The importer takes a minimum of 0 («0», «0.00», «0.001» → 0.00). Add Funds validates `min:0.00`, so a
     * customer makes a credit invoice of 0.00, which then blocks any further deposit («unpaid invoice for credits»).
     */
    public function test_adv_648_3_a_zero_minimum_does_not_allow_a_deposit_of_nothing(): void
    {
        $this->import(['AddFundsMinimum' => '0', 'AddFundsMaximum' => '1000.00', 'AddFundsMaximumBalance' => '1000.00']);

        $invoice = $this->deposit('0');
        $this->assertNull($invoice, 'a credit invoice of 0.00 was made');
        $this->assertSame(0, Setting::query()->where('key', 'credits_minimum_deposit')->where('value', '0.00')->count(),
            'a minimum of 0 was imported');
    }
}
