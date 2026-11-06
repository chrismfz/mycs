<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Gateway;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * WHMCS's ways of paying, each a gateway here, installed disabled before the invoices', items' and saved methods' ways
 * are set (owner 2026-11-06; myip's rehearsal: on the cut-over's empty database a bank transfer or Eurobank way was no way
 * at all, and 31 customers paying some items so and others by card stopped being charged). Bank transfer's accounts
 * from WHMCS's «Invoice Created» template. Fixtures, no real data.
 */
class ImportPaymentWaysTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private BufferedOutput $output;

    private ImportFromWhmcs $command;

    private const TEMPLATE = '<p>Invoice {$invoice_num}</p>{if $invoice_payment_method eq "Bank deposit"}<h3>BANK DEPOSIT / IRIS</h3>'
        . '<p>Deposit {$invoice_total} to one of the banks below, with {$invoice_num} as the reason.</p><p>First Bank<br>Beneficiary<br>EXAMPLE CO.<br>'
        . 'IBAN<br>GR16 0110 1250 0000 0001 2300 695</p><p>Second&nbsp;Bank<br>IBAN<br>GR96 0810 0010 0000 0012 3456 789</p>'
        . '{elseif $invoice_payment_method eq "Cards or IRIS"}<p>Pay by card</p>{/if}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblpaymentgateways (id INTEGER PRIMARY KEY, gateway TEXT, setting TEXT, value TEXT, "order" INT)');
        $this->whmcs->exec('CREATE TABLE tblemailtemplates (id INTEGER PRIMARY KEY, name TEXT, language TEXT, message TEXT)');
        foreach ([['banktransfer', 'Bank deposit', 'on'], ['eurobanklib', 'Cards or IRIS', 'on'], ['stripe', 'Card (Stripe)', 'on'],
            ['paypal', 'PayPal (Old)', ''], ['someoldgateway', 'Old way', 'on']] as [$module, $name, $visible]) {
            $this->whmcs->prepare('INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES (?, ?, ?), (?, ?, ?), (?, ?, ?)')
                ->execute([$module, 'name', $name, $module, 'visible', $visible, $module, 'instructions', 'aabbcc.encrypted']);
        }
        $this->whmcs->prepare("INSERT INTO tblemailtemplates (name, language, message) VALUES ('Invoice Created', 'english', 'no bank here'), ('Invoice Created', '', ?)")
            ->execute([self::TEMPLATE]);
        $this->command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($this->command, 'pdo'))->setValue($this->command, $this->whmcs);
        $this->output = new BufferedOutput;
        $this->command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
    }

    private function run_(): string
    {
        (new ReflectionMethod($this->command, 'importPaymentWays'))->invoke($this->command);

        return $this->output->fetch();
    }

    /** @return array<string, array{name: string, enabled: bool}> */
    private function gateways(): array
    {
        return DB::table('extensions')->where('type', 'gateway')->get()->mapWithKeys(fn ($row) => [$row->extension => ['name' => $row->name, 'enabled' => (bool) $row->enabled]])->all();
    }

    public function test_each_way_whmcs_shows_is_a_disabled_gateway_with_its_name_and_the_banks_accounts(): void
    {
        $out = $this->run_();

        $this->assertSame([
            'BankTransfer' => ['name' => 'Bank deposit', 'enabled' => false],
            'Cardlink' => ['name' => 'Cards or IRIS', 'enabled' => false],
            'Stripe' => ['name' => 'Stripe', 'enabled' => false],
        ], $this->gateways(), 'not paypal (hidden in WHMCS), none for a module MyCS has nothing for; a shared one keeps its own name');
        $accounts = Gateway::query()->where('extension', 'BankTransfer')->sole()->settings()->where('key', 'accounts')->value('value');
        $this->assertSame("BANK DEPOSIT / IRIS\n\nFirst Bank\nBeneficiary\nEXAMPLE CO.\nIBAN\nGR16 0110 1250 0000 0001 2300 695\n\nSecond Bank\nIBAN\nGR96 0810 0010 0000 0012 3456 789", $accounts,
            'its block only, without the Smarty lines, one blank line between its parts');
        $this->assertStringContainsString('Payment way «someoldgateway» («Old way»): MyCS has no gateway for it', $out);
        $this->assertStringContainsString('check them before enabling it', $out);

        // The steps after see them: the invoices', items' and saved methods' ways.
        $existing = new ReflectionMethod($this->command, 'existingGateway');
        $this->assertNotNull($existing->invoke($this->command, 'banktransfer'));
        $this->assertNotNull($existing->invoke($this->command, 'eurobanklib'));
    }

    public function test_one_set_up_here_is_kept_and_no_block_is_said(): void
    {
        $bank = (int) DB::table('extensions')->insertGetId(['name' => 'Our bank', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now()]);
        Gateway::query()->findOrFail($bank)->settings()->create(['key' => 'accounts', 'value' => 'Ours', 'type' => 'string', 'encrypted' => false]);

        $this->run_();

        $this->assertSame(['name' => 'Our bank', 'enabled' => true], $this->gateways()['BankTransfer']);
        $this->assertSame('Ours', Gateway::query()->findOrFail($bank)->settings()->where('key', 'accounts')->value('value'));

        // A WHMCS whose template has no such block: said, nothing written.
        DB::table('extensions')->where('extension', 'BankTransfer')->delete();
        $this->whmcs->exec('DELETE FROM tblemailtemplates');
        $out = $this->run_();
        $this->assertNull(Gateway::query()->where('extension', 'BankTransfer')->sole()->settings()->where('key', 'accounts')->value('value'));
        $this->assertStringContainsString('its accounts were not found', $out);
    }
}
