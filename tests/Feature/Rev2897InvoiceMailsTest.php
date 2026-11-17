<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Domain;
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
use MyCS\Extensions\Gateways\Rev2897Pay\Rev2897Pay;
use Tests\TestCase;

/**
 * Second independent review of PR #897 (head b3a5845c, after the ADV-897-1..6 fixes). Each test names its finding
 * (REV2-897-n), its severity and the scenario. They fail on b3a5845c.
 */
class Rev2897InvoiceMailsTest extends TestCase
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
        if (!class_exists(Rev2897Pay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\Rev2897Pay; class Rev2897Pay {
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
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Rev2897Pay'], ['name' => 'Rev2897Pay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_rev2_897', 'type' => 'card']);
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
     * REV2-897-1 (Medium): ADV-897-3's fix is incomplete for a mixed invoice. It renews service A (expires 24/11, its
     * suspension 03/12) and carries an extra charge on service B, which expires 12/11 (B's own renewal is on another
     * invoice). The guard only asks that the invoice renews *some* service; PaymentReminders::suspendOn() then takes the
     * minimum over *every* service of the invoice, B's charge line included, and the mail says «unpaid, its services are
     * suspended on 21/11/2026» — B's day, which paying this invoice does not move (NotificationHelper.php:34-36,
     * PaymentReminders.php:249-268, services() at :306). The customer pays it and B is still suspended on 21/11.
     */
    public function test_rev2_897_1_a_mixed_invoice_names_only_the_suspension_of_what_it_renews(): void
    {
        $renewed = $this->service('2026-11-24');
        $charged = $this->service('2026-11-12');
        $invoice = $this->invoice($renewed, '2026-11-24', '2026-11-24', '2026-12-24');
        $invoice->items()->create(['description' => 'Extra IP', 'price' => '5.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $charged->id, 'kind' => InvoiceItem::KIND_CHARGE]);

        $text = $this->text($invoice->fresh(), 'new_invoice_created');

        $this->assertStringNotContainsString('21/11/2026', $text, 'the suspension day of a service this invoice only charges');
        $this->assertStringContainsString('suspended on 03/12/2026', $text, 'the day the renewed service is suspended unpaid');
    }

    /**
     * REV2-897-2 (Low): ADV-897-1 fixed the charge day in «new invoice», not in the «upcoming charge» reminder
     * (card_payment_due, which this PR edits and the migration rewrites). With autocharge_days_before 3 the cron charges on
     * 21/11 (AutoCharge::nextAttempt, now also `charge_on` in its data); the reminder still says «will be charged
     * automatically on 24/11/2026» (invoice.due_at, EmailTemplateSeeder.php:794 en, :1957 el, in_app_body :814). The
     * same customer now gets two mails naming two days for the same charge, and the reminder's «update your card before
     * that date» comes after the charge was tried.
     */
    public function test_rev2_897_2_the_upcoming_charge_reminder_names_the_day_the_new_invoice_mail_names(): void
    {
        config(['settings.autocharge_days_before' => 3]);
        $this->withSavedCard();
        $invoice = $this->invoice($this->service('2026-11-24'), '2026-11-24', '2026-11-24', '2026-12-24');
        $this->assertSame('21/11/2026', AutoCharge::nextAttempt($invoice)?->format('d/m/Y'), 'precondition: charged three days before');
        $this->assertStringContainsString('charged automatically on 21/11/2026', $this->text($invoice, 'new_invoice_created'), 'precondition: the new invoice says 21/11');

        $text = $this->text($invoice, 'card_payment_due');

        $this->assertStringNotContainsString('charged automatically on 24/11/2026', $text, 'the reminder names another day than the new invoice mail');
    }

    /**
     * REV2-897-3 (Low): a domain renewed at its registrar is left out of «Paid until» when the registrar shows an expiry a
     * day or two short of the period paid. DomainRenewalService::covers() accepts the renewal within TOLERANCE_DAYS (3,
     * DomainRenewalService.php:45, :516-521) and the sync writes the registrar's expiry to the domain
     * (DomainSyncService::plan/applyResult, :208-210); paidUntil() asks expires_at >= period_end exactly
     * (NotificationHelper.php:61-62). The domain was renewed (its «renewed» mail goes out), the payment mail does not list it.
     */
    public function test_rev2_897_3_a_domain_renewed_within_the_registrars_tolerance_is_listed(): void
    {
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'sld' => 'shop', 'tld' => 'gr', 'fqdn' => 'shop.gr',
            'expires_at' => '2027-11-23']); // as the registrar shows it after the renewal: one day short, within tolerance
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-24']);
        $invoice->items()->create(['description' => 'Domain renewal shop.gr', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Domain::class,
            'reference_id' => $domain->id, 'period_start' => '2026-11-24', 'period_end' => '2027-11-24']);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_PAID]);
        DB::table('invoice_items')->where('invoice_id', $invoice->id)->update(['applied_at' => now()]); // paid and applied (as after RenewDomainJob)

        $lines = NotificationHelper::paidUntil($invoice->fresh());

        $this->assertSame(['shop.gr'], array_column($lines, 'name'), 'a domain renewed at its registrar, left out of «Paid until»');
    }
}
