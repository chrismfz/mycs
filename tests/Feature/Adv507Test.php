<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\BillingAgreement;
use App\Models\Category;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Payment\AutoCharge;
use App\Services\Reports\RevenueForecast;
use Carbon\CarbonImmutable;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Adversarial review of #507 (the importer writes WHMCS Setup / PromoHosting / Addon / Upgrade lines as Service lines
 * with `kind = charge`). Every code path that asks «does this invoice bill service X?» through reference_type now also
 * sees those charges; the ones that mean «a renewal of X» get them wrong.
 */
class Adv507Test extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    private int $clientId;

    private Product $product;

    private int $item = 5000;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        $this->clientId = User::factory()->create()->client()->id;
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $this->whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $this->whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $this->whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INT, addonid INT, name TEXT)');
        $this->whmcs->exec('CREATE TABLE tblupgrades (id INTEGER PRIMARY KEY, relid INT, type TEXT)');
        $this->whmcs->exec('INSERT INTO tblcurrencies VALUES (1, \'USD\')');
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientId}, 1)");

        $this->product = Product::factory()->create(['name' => 'Business 10',
            'category_id' => Category::create(['name' => 'Shared Hosting', 'slug' => 'shared-hosting'])->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A monthly service at 10.00 with this (WHMCS) id. */
    private function service(int $id, string $expires = '2026-10-25'): Service
    {
        $plan = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class, 'name' => 'Monthly',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return Service::factory()->create(['id' => $id, 'client_id' => $this->clientId, 'product_id' => $this->product->id, 'plan_id' => $plan->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => $expires]);
    }

    private function invoice(int $id, string $status, float $total, string $due = '2026-10-25'): void
    {
        $this->whmcs->exec("INSERT INTO tblinvoices VALUES ({$id}, {$this->clientId}, '', '2026-10-15', '{$due}', '', '{$status}', {$total}, 0, 0)");
    }

    private function item(int $invoice, string $type, int $relid, float $amount, string $description = 'Line'): int
    {
        $id = ++$this->item;
        $this->whmcs->exec("INSERT INTO tblinvoiceitems VALUES ({$id}, {$invoice}, {$this->clientId}, '{$type}', {$relid}, '{$description}', {$amount}, 0)");

        return $id;
    }

    private function import(): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        foreach (['importInvoices', 'importInvoiceItems', 'importPayments'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
    }

    /**
     * ADV-507-1 (high): an imported unpaid invoice that bills only an addon (or an upgrade, or a setup fee) of a service —
     * WHMCS bills addons on their own invoices and cycles — is now one of the service's invoices
     * (Service::invoices() through reference_type), and RenewalBilling::billRenewal skips any service with an open invoice.
     * → The service's renewal is never billed while that addon invoice is open; the cron then suspends it (no renewal to
     * pay: paying the addon renews nothing) and terminates it. Before #507 the addon line had no type and the renewal
     * was billed. Expected: the renewal billed (a charge is not the service's renewal).
     */
    public function test_an_open_imported_addon_invoice_does_not_stop_the_renewal_being_billed(): void
    {
        $service = $this->service(101);
        $this->whmcs->exec("INSERT INTO tblhostingaddons VALUES (31, 101, 4, 'Dedicated IP')");
        $this->invoice(700, 'Unpaid', 3);
        $this->item(700, 'Addon', 31, 3, 'Dedicated IP (20/10/2026 - 19/11/2026)');
        $this->import();
        $this->assertSame([Service::class, 101, InvoiceItem::KIND_CHARGE],
            [InvoiceItem::query()->value('reference_type'), (int) InvoiceItem::query()->value('reference_id'), InvoiceItem::query()->value('kind')]);

        $billed = DB::transaction(fn () => (new RenewalBilling)->billRenewal($service->id, now()->addDays(7)));

        $this->assertSame(1, $billed, 'the renewal from 2026-10-25 is billed: the open addon invoice is not its renewal');
        $this->assertTrue(InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', 101)
            ->whereDate('period_start', '2026-10-25')->exists());
    }

    /**
     * ADV-507-2 (low, latent while the importer leaves due_at null): RevenueForecast «this month» subtracts the renewals already on open invoices
     * (openRenewalNet: every Service line) from the month's forecast. An imported setup fee (a charge) on the open
     * renewal invoice is subtracted too, as if it were a renewal. → «Expected» this month is short by the setup fee
     * (a promotion, negative, would inflate it instead). Expected: 15 invoiced + 10 still to invoice (service 102) = 25.
     */
    public function test_this_months_forecast_does_not_take_a_charge_for_a_renewal_already_invoiced(): void
    {
        $this->service(101);
        $this->service(102, '2026-10-28');
        $this->invoice(700, 'Unpaid', 15);
        $this->item(700, 'Hosting', 101, 10);
        $this->item(700, 'Setup', 101, 5);
        $this->import();
        // The importer does not carry tblinvoices.duedate (due_at null on every imported invoice): its due date as WHMCS has it.
        Invoice::query()->whereKey(700)->update(['due_at' => '2026-10-25']);

        $current = RevenueForecast::for('USD', CarbonImmutable::parse('2026-10-01'), 1)['current'];

        $this->assertSame('15.00', $current['invoiced']);
        $this->assertSame('25.00', $current['expected'], '15 on the open invoice + 10 for service 102, not yet invoiced');
    }

    /**
     * ADV-507-3 (medium): CancelOpenInvoices still treats an untyped imported line whose relid equals a hosting's id
     * on the invoice as that hosting's («a setup fee or a promotion of a hosting»). Since #507 the importer types every
     * hosting line, so the only untyped lines left with a relid are domains' (and late fees): a WHMCS Domain line with
     * relid 101 (tbldomains.id 101) is a different object from hosting 101. → Terminating service 101 deletes the
     * domain's renewal line with it, on an invoice that also bills service 102: the domain's renewal is silently
     * dropped. Expected: the domain line stays (or the invoice is left for staff, as for any unknown imported line).
     */
    public function test_ending_a_service_does_not_take_a_domain_line_whose_whmcs_id_matches(): void
    {
        $this->service(101);
        $this->service(102);
        $this->invoice(700, 'Unpaid', 32);
        $this->item(700, 'Hosting', 101, 10);
        $this->item(700, 'Hosting', 102, 10);
        $domainLine = $this->item(700, 'Domain', 101, 12, 'Domain Renewal - example.gr - 1 Year/s (25/10/2026 - 24/10/2027)');
        $this->import();
        $service = Service::query()->findOrFail(101);
        $service->update(['status' => Service::STATUS_TERMINATED]);

        $left = CancelOpenInvoices::handle([Invoice::query()->findOrFail(700)], 'Terminated.', $service);

        $this->assertTrue($left !== [] || InvoiceItem::query()->whereKey($domainLine)->exists(),
            'the domain renewal (WHMCS domain #101) is not hosting #101\'s: kept, or the invoice left for staff');
    }

    /**
     * ADV-507-4 (medium, latent while the importer leaves due_at null): an imported unpaid WHMCS «Upgrade» invoice is now a Service line, so AutoCharge::due() takes
     * it for «a renewal of a service» and charges the customer's saved card for it. Paying it applies nothing (a charge:
     * ProcessPaidInvoiceService returns, there is no ServiceUpgrade behind it). Before #507 the line was untyped and the
     * invoice was never auto-charged. → Card money taken for an upgrade MyCS never applies. Expected: not auto-charged
     * (left to the customer or staff).
     */
    public function test_an_imported_upgrade_only_invoice_is_not_auto_charged(): void
    {
        $this->service(101);
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway']);
        BillingAgreement::query()->create(['client_id' => $this->clientId, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        Client::query()->whereKey($this->clientId)->update(['auto_charge' => true]);
        $this->assertNotNull(Client::query()->findOrFail($this->clientId)->default_billing_agreement_id, 'precondition: a saved method');
        $this->whmcs->exec("INSERT INTO tblupgrades VALUES (41, 101, 'package')");
        $this->invoice(700, 'Unpaid', 4, '2026-10-20');
        $this->item(700, 'Upgrade', 41, 4, 'Upgrade: Business 10 => Business 20');
        $this->import();
        // Its due date as WHMCS has it (the importer leaves due_at null today, which alone keeps it from AutoCharge).
        Invoice::query()->whereKey(700)->update(['due_at' => '2026-10-20']);

        $due = (new ReflectionMethod(AutoCharge::class, 'due'))->invoke(app(AutoCharge::class), CarbonImmutable::today());

        $this->assertNotContains(700, $due->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'an imported upgrade is applied by nobody here: its card is not charged for it');
    }

    /**
     * ADV-507-5 (held, regression guard): paying an imported invoice that bills only charges of a service (an addon and
     * a promotion) renews nothing and gives no line a period. Expected and actual: expiry unchanged.
     */
    public function test_paying_a_charges_only_invoice_renews_nothing(): void
    {
        $service = $this->service(101);
        $this->whmcs->exec("INSERT INTO tblhostingaddons VALUES (31, 101, 4, 'Dedicated IP')");
        $this->invoice(700, 'Unpaid', 2);
        $this->item(700, 'Addon', 31, 3);
        $this->item(700, 'PromoHosting', 101, -1);
        $this->import();

        (new RecordPaymentService)->handle(Invoice::query()->findOrFail(700), null, 2.0, transactionId: 'wire-700');

        $this->assertSame(Invoice::STATUS_PAID, Invoice::query()->findOrFail(700)->status);
        $this->assertSame('2026-10-25', $service->fresh()->expires_at->toDateString());
        $this->assertSame(0, InvoiceItem::query()->whereNotNull('period_start')->count());
    }

    /**
     * ADV-507-6 (held, regression guard): cancelling an imported charges-only invoice gives up no period of the
     * service (InvoiceObserver::givePeriodsUp skips charges), so the cron still bills its renewal once it is gone.
     */
    public function test_cancelling_a_charges_only_invoice_gives_up_no_period(): void
    {
        $service = $this->service(101);
        $this->invoice(700, 'Unpaid', 5);
        $this->item(700, 'Setup', 101, 5);
        $this->import();

        Invoice::query()->findOrFail(700)->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->assertSame(0, InvoiceItem::query()->whereNotNull('forgone_at')->count());
        $this->assertSame(1, DB::transaction(fn () => (new RenewalBilling)->billRenewal($service->id, now()->addDays(7))));
    }

    /**
     * ADV-507-7 (held, regression guard): odd WHMCS data. A Setup / PromoHosting with relid 0, an Addon whose
     * addon row with hostingid 0 and an Upgrade of an unknown type stay untyped; a negative promotion keeps its sign.
     */
    public function test_odd_relids_stay_untyped(): void
    {
        $this->service(101);
        $this->whmcs->exec("INSERT INTO tblhostingaddons VALUES (31, 0, 4, 'no service')");
        $this->whmcs->exec("INSERT INTO tblupgrades VALUES (41, 101, 'service')");
        $this->invoice(700, 'Paid', 0);
        $lines = [
            $this->item(700, 'Setup', 0, 5),
            $this->item(700, 'PromoHosting', 0, -2),
            $this->item(700, 'Addon', 31, 3),
            $this->item(700, 'Upgrade', 41, 4),
        ];
        $promo = $this->item(700, 'PromoHosting', 101, -2.5);
        $this->import();

        foreach ($lines as $line) {
            $this->assertNull(InvoiceItem::query()->findOrFail($line)->reference_type, "line {$line}");
        }
        $item = InvoiceItem::query()->findOrFail($promo);
        $this->assertSame([Service::class, '-2.50', '-2.50'], [$item->reference_type, (string) $item->price, (string) $item->line_total]);
    }
}
