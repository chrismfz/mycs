<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Gateway;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #679: bank transfer's accounts read from WHMCS's «Invoice Created» template (bankAccounts).
 * Fixtures only, no real data.
 */
class Adv679BankAccountsTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private ImportFromWhmcs $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblpaymentgateways (id INTEGER PRIMARY KEY, gateway TEXT, setting TEXT, value TEXT, "order" INT)');
        $this->whmcs->exec('CREATE TABLE tblemailtemplates (id INTEGER PRIMARY KEY, name TEXT, language TEXT, message TEXT)');
        $this->whmcs->exec("INSERT INTO tblpaymentgateways (gateway, setting, value) VALUES ('banktransfer', 'name', 'Bank deposit'), ('banktransfer', 'visible', 'on')");
        $this->command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($this->command, 'pdo'))->setValue($this->command, $this->whmcs);
        $this->command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
    }

    private function accounts(string $template): ?string
    {
        $this->whmcs->prepare("INSERT INTO tblemailtemplates (name, language, message) VALUES ('Invoice Created', '', ?)")->execute([$template]);
        (new ReflectionMethod($this->command, 'importPaymentWays'))->invoke($this->command);

        return Gateway::query()->where('extension', 'BankTransfer')->sole()->settings()->where('key', 'accounts')->value('value');
    }

    /**
     * ADV-679-3. A Smarty {if} inside the bank block (a note for Greek customers, IRIS for one bank) ends the block at its
     * own {else}/{/if}: the accounts after it are lost, and customers are shown one bank of two.
     */
    public function test_a_nested_if_inside_the_block_does_not_cut_off_the_accounts_after_it(): void
    {
        $accounts = $this->accounts('{if $invoice_payment_method eq "Bank deposit"}<p>First Bank<br>IBAN GR16 0110 1250 0000 0001 2300 695</p>'
            . '{if $client_country eq "GR"}<p>Also by IRIS.</p>{else}<p>SWIFT ETHNGRAA</p>{/if}'
            . '<p>Second Bank<br>IBAN GR96 0810 0010 0000 0012 3456 789</p>{elseif $invoice_payment_method eq "Card"}<p>Pay by card</p>{/if}');

        $this->assertStringContainsString('GR16 0110 1250 0000 0001 2300 695', (string) $accounts);
        $this->assertStringContainsString('GR96 0810 0010 0000 0012 3456 789', (string) $accounts, 'the second bank, after the nested {if}');
    }

    /**
     * ADV-679-4. Accounts laid out as a table (label | value, the common way in WHMCS's editor): a cell's end is not a
     * break, so «Beneficiary» and «EXAMPLE CO.» run together («BeneficiaryEXAMPLE CO.») in what customers are shown.
     */
    public function test_table_cells_are_kept_apart(): void
    {
        $accounts = $this->accounts('{if $invoice_payment_method eq "Bank deposit"}<table><tr><td>Beneficiary</td><td>EXAMPLE CO.</td></tr>'
            . '<tr><td>IBAN:</td><td>GR16 0110 1250 0000 0001 2300 695</td></tr></table>{/if}');

        $this->assertNotNull($accounts);
        $this->assertStringNotContainsString('BeneficiaryEXAMPLE', $accounts);
        $this->assertStringNotContainsString('IBAN:GR16', $accounts);
    }
}
