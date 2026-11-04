<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Import\WhmcsApi;
use App\Services\Import\WhmcsCustomFieldImport;
use App\Services\Import\WhmcsPayMethodImport;
use App\Services\Import\WhmcsRecordParity;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #647 (WHMCS's live product addons imported as services of their parents, docs/addons.md step 4).
 * One test per finding (ADV-647-n), each failing on the PR's code. Example data only: no customer's names, domains or ids.
 */
class Adv647Test extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private int $clientId;

    private Product $hosting;

    private int $item = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-04 10:00:00');
        Queue::fake();
        $this->clientId = User::factory()->create()->client()->id;
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT, groupid INT DEFAULT 0)');
        $this->whmcs->exec('CREATE TABLE tbladdons (id INTEGER PRIMARY KEY, packages TEXT, name TEXT, description TEXT, billingcycle TEXT, allowqty INT DEFAULT 0,
            tax INT DEFAULT 1, hidden INT DEFAULT 0, retired INT DEFAULT 0, autoactivate TEXT DEFAULT \'\', suspendproduct INT DEFAULT 0)');
        $this->whmcs->exec('CREATE TABLE tblpricing (id INTEGER PRIMARY KEY, type TEXT, currency INT, relid INT, msetupfee REAL DEFAULT 0, qsetupfee REAL DEFAULT 0,
            ssetupfee REAL DEFAULT 0, asetupfee REAL DEFAULT 0, bsetupfee REAL DEFAULT 0, tsetupfee REAL DEFAULT 0, monthly REAL, quarterly REAL,
            semiannually REAL, annually REAL, biennially REAL, triennially REAL)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT, paymentmethod TEXT DEFAULT \'banktransfer\', packageid INT DEFAULT 11)');
        $this->whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, orderid INT DEFAULT 0, hostingid INT, addonid INT, userid INT, name TEXT,
            qty INT DEFAULT 1, recurring REAL, billingcycle TEXT, tax TEXT, status TEXT, regdate TEXT, nextduedate TEXT, nextinvoicedate TEXT,
            paymentmethod TEXT DEFAULT \'banktransfer\', notes TEXT DEFAULT \'\', subscriptionid TEXT DEFAULT \'\')');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $this->whmcs->exec('INSERT INTO tblcurrencies VALUES (1, \'USD\'), (5, \'EUR\')');
        $this->whmcs->exec("INSERT INTO tblclients (id, currency) VALUES ({$this->clientId}, 1)");
        // A catalog Dedicated IP (a price per cycle, −1 off) and a yearly maintenance addon of one cycle (before WHMCS 7).
        $this->whmcs->exec("INSERT INTO tbladdons (id, packages, name, description, billingcycle, tax) VALUES
            (26, ',11,', 'Dedicated IP', 'An IPv4 of its own', 'recurring', 1), (30, '', 'Maintenance', '', 'Annually', 1)");
        $this->whmcs->exec("INSERT INTO tblpricing (type, currency, relid, monthly, quarterly, semiannually, annually, biennially, triennially) VALUES
            ('addon', 1, 26, 3, -1, 18, 36, 72, -1), ('addon', 1, 30, 600, 0, 0, 0, 0, 0)");

        $this->hosting = Product::factory()->create(['id' => 11, 'name' => 'Business 10',
            'category_id' => Category::create(['name' => 'Shared Hosting', 'slug' => 'shared-hosting'])->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A yearly service at 100.00 with this (WHMCS) id, and WHMCS's row of it. */
    private function service(int $id, string $status = Service::STATUS_ACTIVE, string $expires = '2027-05-18', string $method = 'banktransfer'): Service
    {
        $plan = Plan::factory()->create(['priceable_id' => $this->hosting->id, 'priceable_type' => Product::class, 'name' => 'Annually',
            'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 100, 'setup_fee' => 0, 'currency_code' => 'USD']);
        $this->whmcs->exec("INSERT INTO tblhosting (id, userid, domainstatus, paymentmethod) VALUES ({$id}, {$this->clientId}, '" . ucfirst($status) . "', '{$method}')");

        return Service::factory()->create(['id' => $id, 'client_id' => $this->clientId, 'product_id' => $this->hosting->id, 'plan_id' => $plan->id,
            'status' => $status, 'currency_code' => 'USD', 'price' => 100, 'quantity' => 1, 'expires_at' => $expires, 'domain' => "site{$id}.example.test"]);
    }

    /** @param  array<string, mixed>  $fields */
    private function addon(int $id, int $parent, array $fields = []): void
    {
        $row = $fields + ['addonid' => 26, 'userid' => $this->clientId, 'name' => '', 'recurring' => 24, 'billingcycle' => 'Annually', 'tax' => '1',
            'status' => 'Active', 'regdate' => '2019-06-05', 'nextduedate' => '2027-05-18', 'nextinvoicedate' => '2027-05-18', 'notes' => ''];
        $statement = $this->whmcs->prepare('INSERT INTO tblhostingaddons (id, hostingid, ' . implode(', ', array_keys($row)) . ') VALUES (?, ?'
            . str_repeat(', ?', count($row)) . ')');
        $statement->execute([$id, $parent, ...array_values($row)]);
    }

    private function line(int $invoice, int $relid, float $amount, string $type = 'Addon'): int
    {
        $id = ++$this->item;
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES ({$id}, {$invoice}, {$this->clientId}, '{$type}', {$relid}, 'Line', {$amount}, 0)");

        return $id;
    }

    /** @param  list<string>  $steps */
    private function import(array $steps = ['importAddons']): string
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output = new BufferedOutput));
        foreach ($steps as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }

        return $output->fetch();
    }

    private function addonService(int $whmcsId): Service
    {
        return Service::query()->where('whmcs_addon_id', $whmcsId)->sole();
    }

    /**
     * ADV-647-1: a parent imported with WHMCS's «End of Billing Period» cancellation request. Here (docs/addons.md, step 2)
     * such a request is copied to each live addon (`with_parent`): they are not renewed any more. The importer writes the
     * requests directly (importCancellations, after importAddons), so the imported addons get none, and the cron keeps
     * billing a monthly addon every month up to the day its parent (and with it the addon) is closed.
     */
    public function test_adv_647_1_an_addon_of_a_parent_cancelled_at_the_end_of_its_period_is_not_billed_again(): void
    {
        $this->service(1700, expires: '2027-05-18');
        $this->addon(201, 1700, ['recurring' => 3, 'billingcycle' => 'Monthly', 'nextduedate' => '2026-11-10', 'nextinvoicedate' => '2026-11-10']);
        $this->whmcs->exec('CREATE TABLE tblcancelrequests (id INTEGER PRIMARY KEY, date TEXT, relid INT, reason TEXT, type TEXT)');
        $this->whmcs->exec("INSERT INTO tblcancelrequests VALUES (1, '2026-10-30 12:00:00', 1700, 'Moving away', 'End of Billing Period')");

        $this->import(['importAddons', 'importCancellations']);

        $addon = $this->addonService(201);
        $this->assertSame(0, (new RenewalBilling)->billRenewal($addon->id, Carbon::parse('2026-11-30')),
            'its parent ends at the end of its period: the addon is not renewed any more (with_parent), as one cancelled here');
        $this->assertFalse(Invoice::query()->exists());
    }

    /**
     * ADV-647-2: an addon WHMCS keeps with `userid` 0 (its account is its service's: older WHMCS rows; the importer, the
     * forecast parity and WhmcsPayMethodImport::usage() all allow for it). Paid by a module WHMCS never charged («paypal»,
     * by hand) on the gateway of the account's saved PayPal method: the account's other such items get «Do not charge
     * automatically», but the addon is looked up by `userid = account` only, so it is missed while the count says all were
     * kept: the saved method charges it here.
     */
    public function test_adv_647_2_an_addon_with_no_userid_of_its_own_is_kept_from_a_saved_method_it_was_never_charged_by(): void
    {
        Http::preventStrayRequests();
        $c = $this->clientId;
        $gateways = [];
        foreach (['Stripe', 'PayPal'] as $extension) {
            $gateways[$extension] = (int) DB::table('extensions')->insertGetId(['name' => $extension, 'extension' => $extension, 'type' => 'gateway',
                'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->whmcs->exec('CREATE TABLE tbldomains (id INTEGER PRIMARY KEY, userid INT, status TEXT, paymentmethod TEXT)');
        $this->whmcs->exec('CREATE TABLE tblpaymethods (id INTEGER PRIMARY KEY, userid INT, description TEXT, contact_id INT, contact_type TEXT, payment_id INT,
            payment_type TEXT, gateway_name TEXT, order_preference INT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcreditcards (id INTEGER PRIMARY KEY, pay_method_id INT, card_type TEXT, last_four TEXT, expiry_date TEXT,
            card_data TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT)');
        $this->whmcs->exec("INSERT INTO tblpaymethods (id, userid, payment_type, gateway_name, order_preference, deleted_at) VALUES
            (11, {$c}, 'RemoteCreditCard', 'stripe', 0, NULL), (13, {$c}, 'RemoteCreditCard', 'paypal_ppcpv', 1, NULL)");
        $this->whmcs->exec("INSERT INTO tblcreditcards (pay_method_id, card_type, last_four, expiry_date, card_data) VALUES
            (11, 'Visa', '4242', '', 'x'), (13, 'PayPal', 'payer@example.test', '', 'x')");
        // Its card pays two services; one more by basic PayPal (by hand in WHMCS); the addon (no userid of its own) by it too.
        $byCard = $this->service(1801, method: 'stripe');
        $this->service(1802, method: 'stripe');
        $basic = $this->service(1803, method: 'paypal');
        $this->addon(202, 1801, ['userid' => 0, 'paymentmethod' => 'paypal']);
        $this->import();
        $addon = $this->addonService(202);
        Http::fake(['https://whmcs.example.test/includes/api.php' => fn (Request $request) => Http::response(['result' => 'success', 'clientid' => $request['clientid'],
            'paymethods' => (int) $request['clientid'] === $c ? [
                ['id' => 11, 'remote_token' => json_encode(['customer' => 'cus_Example1', 'method' => 'pm_Example1']), 'expiry_date' => '06/29', 'card_last_four' => '4242', 'card_type' => 'Visa'],
                ['id' => 13, 'remote_token' => json_encode(['vaultId' => 'VAULT0000EXAMPLE', 'customerId' => 'custExample', 'transactionIdentifier' => 'TX0EXAMPLE']),
                    'expiry_date' => '03/54', 'card_last_four' => 'payer@example.test', 'card_type' => 'PayPal'],
            ] : []])]);
        $gateway = fn (string $module) => match ($module) {
            'stripe' => $gateways['Stripe'], 'paypal', 'paypal_ppcpv' => $gateways['PayPal'], default => null,
        };

        (new WhmcsPayMethodImport($this->whmcs, new WhmcsApi('https://whmcs.example.test', 'id-1', 'secret-1'), $gateway, $gateway))->run();

        $this->assertTrue($basic->fresh()->no_auto_charge, 'the service of basic PayPal is kept (ADV-568-1)');
        $this->assertFalse($byCard->fresh()->no_auto_charge);
        $this->assertTrue((bool) $addon->fresh()->no_auto_charge || !$this->clientAutoCharges(),
            'the addon of basic PayPal, never charged in WHMCS, is not charged here by the PayPal wallet either');
    }

    private function clientAutoCharges(): bool
    {
        return (bool) DB::table('clients')->where('id', $this->clientId)->value('auto_charge');
    }

    /**
     * ADV-647-3: an addon's service takes the next id here, which can be one WHMCS gave a service it later deleted (the
     * newest ones: an order deleted, a fraud). Its invoice lines are still in WHMCS (myip: 4,487 «Hosting» lines of deleted
     * services), and a «Hosting» line is taken for service #relid whatever is there: the deleted service's unpaid renewal
     * becomes the addon's. The billing then takes the addon as billed already, and paying the other service's invoice
     * renews the addon by a year.
     */
    public function test_adv_647_3_a_deleted_services_invoice_line_is_never_taken_for_an_addons_renewal(): void
    {
        $this->service(1648, expires: '2027-05-18');
        $this->addon(104, 1648, ['nextduedate' => '2027-05-18']);
        $this->import();
        $addon = $this->addonService(104);
        // The addon's last renewal, paid.
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (899, {$this->clientId}, '', '2026-04-20', '2026-05-18', '2026-05-01', 'Paid', 24, 0, 0)");
        $this->line(899, 104, 24);
        // WHMCS's service of the id the addon got here was ordered and deleted (not in tblhosting); its invoice stayed, unpaid.
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES (950, {$this->clientId}, '', '2026-10-28', '2026-11-11', NULL, 'Unpaid', 50, 0, 0)");
        $this->line(950, $addon->id, 50, 'Hosting');

        $this->import(['importInvoices', 'importInvoiceItems', 'importPayments']);

        $this->assertSame(0, $addon->renewalInvoices()->where('invoices.id', 950)->count(), 'the deleted service\'s invoice is not the addon\'s renewal');
        (new RecordPaymentService)->handle(Invoice::query()->findOrFail(950), null, 50.0, transactionId: 'wire-950');

        $this->assertSame('2027-05-18', $addon->fresh()->expires_at->toDateString(), 'another (deleted) service\'s payment renews nothing of the addon');
    }

    /**
     * ADV-647-4: the importer keeps each addon's VAT as WHMCS billed it (its own `tax` flag, on a retired product of the same
     * flag), so that «no amount changes at the cut-over»; but neither the record parity nor the forecast parity looks at it
     * (nor at which product it is on: any addon product passes). An addon billed without VAT in WHMCS that is taxed here
     * (19-24% more on each renewal) is «no difference».
     */
    public function test_adv_647_4_record_parity_sees_an_addon_taxed_otherwise_than_whmcs_billed_it(): void
    {
        $this->service(1328);
        $this->addon(105, 1328, ['tax' => '0']);
        $this->import();
        // As if imported wrongly: on the catalog's (taxed) Dedicated IP.
        $this->addonService(105)->forceFill(['product_id' => Product::query()->where('whmcs_addon_id', 26)->value('id')])->saveQuietly();

        $records = new WhmcsRecordParity($this->whmcs);
        (new ReflectionMethod($records, 'addons'))->invoke($records);
        $checks = (new ReflectionProperty($records, 'checks'))->getValue($records);

        $this->assertNotSame([], $checks['Addons']['examples'], 'billed without VAT in WHMCS, with it here: a difference');
    }

    /**
     * ADV-647-5: by hand, «any number of GB» is taken for extra disk space (`\d+\s*gb\b`): «Extra RAM 8GB», «Backup 100GB»
     * land on the «Extra disk space» product, whose name is the invoice line's («Extra disk space (site…)»), the Product
     * Addons page's and the reports'. docs/addons.md names «+10gb …», «10Gb addon …», «20GB Extra Space» only.
     */
    public function test_adv_647_5_a_hand_made_addon_of_gb_that_is_not_disk_space_is_not_extra_disk_space(): void
    {
        $this->service(1763);
        $this->addon(301, 1763, ['addonid' => 0, 'name' => 'Extra RAM 8GB', 'recurring' => 10, 'billingcycle' => 'Monthly']);
        $this->addon(302, 1763, ['addonid' => 0, 'name' => 'Offsite backup 100GB', 'recurring' => 5, 'billingcycle' => 'Monthly']);

        $this->import();

        $this->assertNotSame('Extra disk space', $this->addonService(301)->product->name);
        $this->assertNotSame('Extra disk space', $this->addonService(302)->product->name);
    }

    /**
     * ADV-647-6: WHMCS's «Suspend Parent Product» on a catalog addon (`tbladdons.suspendproduct`, set on myip's yearly
     * maintenance addon, 600.00): unpaid, WHMCS suspends the parent service with it. Here an addon's own unpaid suspends only
     * the addon (docs/addons.md), and the importer drops the flag without a word: after the cut-over the server runs on
     * with its maintenance unpaid, and the forecast parity (which compares only the addon's own days) says «same». At least
     * the import must tell staff which addons WHMCS made suspend their parent.
     */
    public function test_adv_647_6_an_addon_that_suspends_its_parent_in_whmcs_is_told(): void
    {
        $this->whmcs->exec('UPDATE tbladdons SET suspendproduct = 1 WHERE id = 30');
        $this->service(1900);
        $this->addon(401, 1900, ['addonid' => 30, 'recurring' => 600]);

        $output = $this->import();

        $this->assertMatchesRegularExpression('/#30\b[^\n]*suspend[^\n]*parent|parent[^\n]*suspend[^\n]*#30\b/i', $output,
            'staff are told that WHMCS suspended the parent with this addon, and it will not here');
    }

    /**
     * ADV-647-7: an imported addon's own custom fields (WHMCS `tblcustomfields.type = addon`: the Dedicated IP addon's
     * «Dedicated IP») still go to its parent (WhmcsCustomFieldImport was not changed with the notes and the payment method):
     * the addon's address becomes the parent's `dedicated_ip`, or a line in the parent's notes, and the addon (its own
     * service now, the one that ends when the IP is given back) has none.
     */
    public function test_adv_647_7_an_imported_addons_custom_fields_are_its_own_not_its_parents(): void
    {
        $parent = $this->service(1648);
        $this->addon(104, 1648);
        $this->whmcs->exec('CREATE TABLE tblcustomfields (id INTEGER PRIMARY KEY, type TEXT, relid INT, fieldname TEXT, fieldtype TEXT)');
        $this->whmcs->exec('CREATE TABLE tblcustomfieldsvalues (id INTEGER PRIMARY KEY, fieldid INT, relid INT, value TEXT)');
        $this->whmcs->exec("INSERT INTO tblcustomfields VALUES (7, 'addon', 26, 'Dedicated IP', 'text'), (8, 'addon', 26, 'Reverse DNS', 'text')");
        $this->whmcs->exec("INSERT INTO tblcustomfieldsvalues (fieldid, relid, value) VALUES (7, 104, '192.0.2.10'), (8, 104, 'mail.example.test')");
        $this->whmcs->exec('CREATE TABLE tblproducts (id INTEGER PRIMARY KEY, servertype TEXT)');
        $this->import();

        (new WhmcsCustomFieldImport($this->whmcs))->run();

        $this->assertNull($parent->fresh()->dedicated_ip, 'the addon\'s address is not its parent\'s');
        $this->assertStringNotContainsString('mail.example.test', (string) $parent->fresh()->notes);
        $addon = $this->addonService(104);
        $this->assertSame('192.0.2.10', (string) $addon->dedicated_ip);
        $this->assertStringContainsString('mail.example.test', (string) $addon->notes);
    }
}
