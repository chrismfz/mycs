<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\AutoCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use MyCS\Extensions\Gateways\Adv897Pay\Adv897Pay;
use Tests\TestCase;

/**
 * Adversarial review of PR #897 (the invoice mails say what, until when and what if unpaid). Each test names its finding
 * (ADV-897-n), its severity and the scenario. They fail on the PR head.
 */
class Adv897InvoiceMailsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2027_03_08_000000_invoice_mails_say_more.php';

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '',
            'settings.autocharge_max_failures' => 0, 'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true,
            'settings.cronjob_suspend_requires_invoice' => false]);
        if (!class_exists(Adv897Pay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\Adv897Pay; class Adv897Pay {
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function supportsPaymentGroups() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { return true; } }');
        }
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function withSavedCard(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Adv897Pay'], ['name' => 'Adv897Pay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_897', 'type' => 'card']);
        $this->client->refresh();
    }

    private function service(string $expires, string $status = Service::STATUS_ACTIVE): Service
    {
        $product = Product::factory()->create(['name' => 'Hosting']);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => $status, 'subscription_id' => null,
            'domain' => 'shop.example.gr', 'expires_at' => $expires]);
    }

    /** An unpaid invoice due on $due with one line of $service (a period, or a charge with none). */
    private function invoice(Service $service, string $due, ?string $from = null, ?string $to = null, ?string $kind = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Line', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id, 'period_start' => $from, 'period_end' => $to, 'kind' => $kind]);

        return $invoice->fresh();
    }

    private function text(Invoice $invoice, string $key): string
    {
        config(['settings.mail_disable' => false]);
        MailFacade::fake();
        NotificationHelper::invoiceNotification($invoice->client, $invoice->fresh(), $key);
        $mail = MailFacade::queued(Mail::class)->merge(MailFacade::sent(Mail::class))->first(fn (Mail $mail) => $mail->templateKey === $key);
        $this->assertNotNull($mail, $key);

        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($mail->renderedHtml)));
    }

    /**
     * ADV-897-1 (Medium): «charged automatically on <due date>» with autocharge_days_before 3. The cron (and the
     * invoice's own page, AutoCharge::nextAttempt) charges the card on 21/11, three days before its due date 24/11; the
     * new invoice's mail tells the customer 24/11. The page and the mail disagree on the day money leaves the card.
     */
    public function test_adv897_1_the_charge_date_is_the_day_the_cron_charges_with_days_before(): void
    {
        config(['settings.autocharge_days_before' => 3]);
        $this->withSavedCard();
        $invoice = $this->invoice($this->service('2026-11-24'), '2026-11-24', '2026-11-24', '2026-12-24');
        $this->assertTrue(AutoCharge::willCharge($invoice), 'precondition: the cron charges it');
        $charged = AutoCharge::nextAttempt($invoice)?->format('d/m/Y');
        $this->assertSame('21/11/2026', $charged, 'precondition: charged three days before its due date');

        $text = $this->text($invoice, 'new_invoice_created');

        $this->assertStringContainsString("charged automatically on {$charged}", $text, 'the mail names its due date, not the charge day');
    }

    /**
     * ADV-897-2 (Low): a renewal billed late (RenewalBilling sets due_at = the period's start, here two days ago; staff's
     * «Invoice selected items», a missed run). The cron charges it today (nextAttempt 10/11), the mail says «It will be
     * charged automatically on 08/11/2026» — a day already past.
     */
    public function test_adv897_2_a_charge_date_in_the_past_is_never_promised(): void
    {
        $this->withSavedCard();
        $invoice = $this->invoice($this->service('2026-11-08'), '2026-11-08', '2026-11-08', '2026-12-08');
        $this->assertSame('10/11/2026', AutoCharge::nextAttempt($invoice)?->format('d/m/Y'), 'precondition: charged today');

        $text = $this->text($invoice, 'new_invoice_created');

        $this->assertStringNotContainsString('charged automatically on 08/11/2026', $text, 'a day already past');
    }

    /**
     * ADV-897-3 (Medium): a charge on an active service (KIND_CHARGE: an extra fee staff bill, a bulk license's usage) due
     * in 7 days; the service is paid until 2027-06-01. suspendOn() reads only the service's expiry: the new invoice says
     * «Please pay it by 17/11/2026: unpaid, its services are suspended on 10/06/2027». Paying it or not changes nothing
     * of that day (DunningRules::suspendable goes by expires_at, and with «suspend requires invoice» a charge alone is
     * not a renewal invoice). The customer is told a consequence this invoice does not have.
     */
    public function test_adv897_3_a_charge_invoice_promises_no_suspension_it_does_not_cause(): void
    {
        $invoice = $this->invoice($this->service('2027-06-01'), '2026-11-17', kind: InvoiceItem::KIND_CHARGE);

        $text = $this->text($invoice, 'new_invoice_created');

        $this->assertStringNotContainsString('suspended on', $text, 'a suspension day that is the next renewal\'s, not this invoice\'s');
    }

    /**
     * ADV-897-4 (Medium): a renewal paid for a service already cancelled (or terminated). RenewServiceService does not
     * revive it and tells staff to refund or credit the money; the «payment received» mail says «Paid until:
     * Hosting — shop.example.gr: 10/12/2026».
     */
    public function test_adv897_4_payment_received_lists_no_period_for_an_ended_service(): void
    {
        $service = $this->service('2026-11-10', Service::STATUS_CANCELLED);
        $invoice = $this->invoice($service, '2026-11-10', '2026-11-10', '2026-12-10');
        ExtensionHelper::addPayment($invoice->id, null, 10.00, null, 'tx-897-4', currency: 'USD');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status, 'precondition: paid');
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status, 'precondition: not revived');

        $text = $this->text($invoice, 'invoice_paid');

        $this->assertStringNotContainsString('Paid until', $text, 'a period the payment did not give');
    }

    /**
     * ADV-897-5 (Medium): the next period paid while the current one is still unpaid (two open renewals, the customer pays
     * the newer one). RenewServiceService: «a period paid ahead of an unpaid one waits»; expires_at stays 17/11/2026 and
     * the cron suspends the service on 26/11. The mail says «Paid until: … 17/01/2027».
     */
    public function test_adv897_5_paid_until_is_not_promised_past_an_unpaid_gap(): void
    {
        $service = $this->service('2026-11-17');
        $this->invoice($service, '2026-11-17', '2026-11-17', '2026-12-17'); // the current period, unpaid
        $ahead = $this->invoice($service, '2026-12-17', '2026-12-17', '2027-01-17');
        ExtensionHelper::addPayment($ahead->id, null, 10.00, null, 'tx-897-5', currency: 'USD');
        $this->assertSame(Invoice::STATUS_PAID, $ahead->fresh()->status, 'precondition: paid');
        $this->assertSame('2026-11-17', Carbon::parse($service->fresh()->expires_at)->toDateString(), 'precondition: its date did not move');

        $text = $this->text($ahead, 'invoice_paid');

        $this->assertStringNotContainsString('17/01/2027', $text, 'paid until a day its service does not reach: suspended on 26/11');
    }

    /**
     * ADV-897-6 (Low): the migration says «Only text still as seeded changes … a template staff rewrote keeps theirs»,
     * but it swaps each sentence wherever it is. A staff «payment received» that goes on after the seeded sentence
     * («This email confirms your payment. Keep it for your records.») gets the «Paid until» block spliced into the
     * middle of its paragraph: «Keep it for your records.» is left dangling after the list.
     */
    public function test_adv897_6_the_migration_leaves_a_staff_rewritten_paragraph_whole(): void
    {
        $own = "Dear {{ user.first_name }},\n\nThis email confirms your payment. Keep it for your records.\n";
        DB::table('notification_templates')->where('key', 'invoice_paid')->update(['body' => $own]);
        $migration = require database_path(self::MIGRATION);

        $migration->up();

        $this->assertSame($own, DB::table('notification_templates')->where('key', 'invoice_paid')->value('body'), 'staff\'s paragraph split');
    }
}
