<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Domain;
use App\Models\EkdosiDocument;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\ItemPaymentMethod;
use App\Services\Payment\SavedMethodCharge;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use MyCS\Extensions\Gateways\AcPay\AcPay;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Code review 2 of #695 (ADV-A2-1: no card charge after a cancellation request; ADV-A2-2: staff told when an upgrade
 * leaves a renewal at its old price), findings C1-C9. Fake data only.
 */
class Review695CronTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private BillingAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_invoice' => 7]);
        if (!class_exists(AcPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AcPay; class AcPay {
                /** @var list<array{invoice: int, amount: float, key: ?string}> */
                public static array $charges = [];
                /** @var \\Closure|null */
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "amount" => (float) $amount, "key" => $invoice->chargeKey];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        AcPay::$charges = [];
        AcPay::$answer = null;
        MailFacade::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway', 'enabled' => true]);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function product(float $price): Product
    {
        $product = Product::factory()->create(['server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $product->fresh();
    }

    private function serviceOf(Product $product, string $expires = '2026-11-17', float $price = 10): Service
    {
        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $product->plans()->first()->id,
            'price' => $price, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $expires]);
    }

    /** [small 10.00, large 20.00], the small one upgradable to the large one and back. */
    private function products(): array
    {
        [$small, $large] = [$this->product(10), $this->product(20)];
        DB::table('product_upgrades')->insert([['product_id' => $small->id, 'upgrade_id' => $large->id], ['product_id' => $large->id, 'upgrade_id' => $small->id]]);

        return [$small, $large];
    }

    private function cronAt(string $at): void
    {
        Carbon::setTestNow($at);
        $this->artisan('app:cron-job');
    }

    private function renewalOf(Service $service): Invoice
    {
        return Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)->where('reference_id', $service->id))
            ->where('status', Invoice::STATUS_UNPAID)->orderBy('id')->firstOrFail();
    }

    /** Placed on 11-14 and, if invoiced, paid by a bank transfer: applied. */
    private function upgrade(Service $service, Product $to): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');
        $placed = PlaceUpgrade::handle($service->fresh(), $to, [], ServiceUpgrade::SOURCE_CLIENT);
        if ($placed->outcome === PlaceUpgrade::INVOICED) {
            ExtensionHelper::addPayment($placed->invoice->id, null, $placed->invoice->fresh()->remaining, null, 'bank-up', currency: 'USD');
        } else {
            $this->assertSame(PlaceUpgrade::APPLIED, $placed->outcome, 'precondition: placed');
        }
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, ServiceUpgrade::query()->latest('id')->value('status'), 'precondition: applied');
    }

    private function notice(): ?EmailLog
    {
        return EmailLog::query()->where('to', 'staff@example.test')->where('subject', 'like', '%kept its old price%')->first();
    }

    /** The cron bills the small service's renewal (10.00, 17/11 - 17/12) on 11-10. */
    private function billedSmall(): array
    {
        [$small, $large] = $this->products();
        $service = $this->serviceOf($small);
        $this->cronAt('2026-11-10 09:00:00');

        return [$service, $this->renewalOf($service), $small, $large];
    }

    /**
     * C1. A payment that lands on the renewal while the upgrade is applied (after the old duplicate decision read it, before
     * the reprice): ChangeOpenLine keeps the line, and staff are told from what it kept.
     */
    public function test_c1_a_payment_landing_while_the_upgrade_applies_is_told(): void
    {
        [$service, $renewal, , $large] = $this->billedSmall();
        $paid = false;
        ServiceUpgrade::saved(function (ServiceUpgrade $upgrade) use ($renewal, &$paid) {
            if (!$paid && $upgrade->status === ServiceUpgrade::STATUS_COMPLETED) {
                $paid = true;
                ExtensionHelper::addPayment($renewal->id, null, '4.00', null, 'bank-mid', currency: 'USD');
            }
        });

        $this->upgrade($service, $large);

        $this->assertTrue($paid, 'precondition: the payment landed');
        $this->assertSame('10.00', number_format((float) $renewal->items()->sole()->price, 2), 'precondition: kept at its price');
        $this->assertStringContainsString("invoice #{$renewal->id}, period 17/11/2026 - 17/12/2026: 10.00 USD", (string) $this->notice()?->body);
    }

    /** C1. An Ekdosi document on the renewal keeps its line too: told. */
    public function test_c1_a_line_kept_by_an_ekdosi_document_is_told(): void
    {
        [$service, $renewal, , $large] = $this->billedSmall();
        EkdosiDocument::query()->create(['invoice_id' => $renewal->id, 'mark' => '400000000001', 'code' => 'A-1', 'state' => EkdosiDocument::ACTIVE]);

        $this->upgrade($service, $large);

        $this->assertStringContainsString("invoice #{$renewal->id}, period 17/11/2026 - 17/12/2026: 10.00 USD", (string) $this->notice()?->body);
    }

    /** C2. A line without a period (an imported WHMCS renewal) kept by its payment is told too. */
    public function test_c2_a_kept_line_without_a_period_is_told(): void
    {
        [$small, $large] = $this->products();
        $service = $this->serviceOf($small);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-17']);
        $line = $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal (WHMCS)', 'quantity' => 1, 'price' => 10]);
        ExtensionHelper::addPayment($invoice->id, null, '4.00', null, 'bank-1', currency: 'USD');

        $this->upgrade($service, $large);

        $this->assertSame('10.00', number_format((float) $line->fresh()->price, 2), 'precondition: kept (R9)');
        $this->assertStringContainsString("invoice #{$invoice->id}, period no period (an imported line): 10.00 USD", (string) $this->notice()?->body);
    }

    /** C3. The difference is to the line's actual price (staff changed it to 8.00), not to the old service price. */
    public function test_c3_the_difference_is_to_the_lines_billed_price(): void
    {
        [$service, $renewal, , $large] = $this->billedSmall();
        $renewal->items()->sole()->update(['price' => 8]);
        ExtensionHelper::addPayment($renewal->id, null, '4.00', null, 'bank-1', currency: 'USD');

        $this->upgrade($service, $large);

        $this->assertStringContainsString('period 17/11/2026 - 17/12/2026: 12.00 USD', (string) $this->notice()?->body, '20.00 - 8.00');
    }

    /** C9. A downgrade: the difference is negative (staff credit it). */
    public function test_c9_a_downgrade_tells_a_negative_difference(): void
    {
        [$small, $large] = $this->products();
        $service = $this->serviceOf($large, price: 20);
        $this->cronAt('2026-11-10 09:00:00');
        $renewal = $this->renewalOf($service);
        ExtensionHelper::addPayment($renewal->id, null, '4.00', null, 'bank-1', currency: 'USD');

        $this->upgrade($service, $small);

        $this->assertSame('10.00', number_format((float) $service->fresh()->price, 2), 'precondition: downgraded');
        $this->assertStringContainsString("invoice #{$renewal->id}, period 17/11/2026 - 17/12/2026: -10.00 USD", (string) $this->notice()?->body);
    }

    /** C9. Quantity 2: twice the difference. */
    public function test_c9_quantity_multiplies_the_difference(): void
    {
        [$service, $renewal, , $large] = $this->billedSmall();
        $renewal->items()->sole()->update(['quantity' => 2]);
        ExtensionHelper::addPayment($renewal->id, null, '4.00', null, 'bank-1', currency: 'USD');

        $this->upgrade($service, $large);

        $this->assertStringContainsString('period 17/11/2026 - 17/12/2026: 20.00 USD', (string) $this->notice()?->body, '(20.00 - 10.00) × 2');
    }

    public static function settled(): array
    {
        return ['paid' => [Invoice::STATUS_PAID], 'cancelled' => [Invoice::STATUS_CANCELLED]];
    }

    /** C9. A kept line whose invoice is paid or cancelled later in the same transaction, before commit: not told. */
    #[DataProvider('settled')]
    public function test_c9_a_kept_line_settled_before_commit_is_not_told(string $status): void
    {
        [$service, $renewal, , $large] = $this->billedSmall();
        ExtensionHelper::addPayment($renewal->id, null, '4.00', null, 'bank-1', currency: 'USD');
        // A legacy line on another open invoice, repriced after the renewal was kept: its save settles the renewal.
        $other = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-17']);
        $legacy = $other->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Legacy', 'quantity' => 1, 'price' => 10]);
        $settled = false;
        InvoiceItem::saved(function (InvoiceItem $item) use ($legacy, $renewal, $status, &$settled) {
            if (!$settled && $item->id === $legacy->id) {
                $settled = true;
                DB::table('invoices')->where('id', $renewal->id)->update(['status' => $status]);
            }
        });

        $this->upgrade($service, $large);

        $this->assertTrue($settled, 'precondition: the legacy line was repriced');
        $this->assertNull($this->notice(), 'a renewal paid or cancelled before the commit has nothing left to settle');
    }

    /** C4. The staff mail failing stops neither the upgrade nor the customer's notice (EVT-2). */
    public function test_c4_a_failing_staff_mail_does_not_skip_the_customer_notice(): void
    {
        [$service, $renewal, , $large] = $this->billedSmall();
        ExtensionHelper::addPayment($renewal->id, null, '4.00', null, 'bank-1', currency: 'USD');
        EmailLog::creating(function (EmailLog $log) {
            if (str_contains((string) $log->subject, 'kept its old price')) {
                throw new RuntimeException('SMTP down');
            }
        });

        $this->upgrade($service, $large); // never throws

        $this->assertSame('20.00', number_format((float) $service->fresh()->price, 2), 'applied');
        $this->assertTrue(EmailLog::query()->where('template_key', 'service_upgraded')->exists(), 'the customer was still told of the upgrade');
    }

    /** A service with a cancellation request, on a renewal part paid by a transfer (left open, ADV-A2-1). */
    private function cancelledWithOpenRenewal(): array
    {
        $service = $this->serviceOf($this->product(10));
        $this->cronAt('2026-11-10 09:00:00');
        $invoice = $this->renewalOf($service);
        ExtensionHelper::addPayment($invoice->id, null, '4.00', null, 'bank-1', currency: 'USD');

        return [$service, $invoice->fresh()];
    }

    /** C9. willCharge/nextAttempt and the reminder's kind follow the cancellation request. */
    public function test_c9_will_charge_next_attempt_and_the_reminder_follow_a_cancellation(): void
    {
        [$service, $invoice] = $this->cancelledWithOpenRenewal();
        $this->assertTrue(AutoCharge::willCharge($invoice), 'precondition: charged on its own');
        $this->assertNotNull(AutoCharge::nextAttempt($invoice));
        $this->assertTrue(PaymentReminders::chargedOnItsOwn($invoice), 'precondition: card_payment_due');

        ServiceCancellation::create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'moving away']);
        $invoice = $invoice->fresh();

        $this->assertFalse(AutoCharge::willCharge($invoice));
        $this->assertNull(AutoCharge::nextAttempt($invoice));
        $this->assertFalse(PaymentReminders::chargedOnItsOwn($invoice), 'the reminder asks the customer to pay, not «it will be charged»');
    }

    /** C9. A cancellation created after the run picked the invoice: the re-check under the charge's lock sends nothing. */
    public function test_c9_a_cancellation_after_the_run_picked_it_is_seen_under_the_lock(): void
    {
        [$service, $invoice] = $this->cancelledWithOpenRenewal();
        Carbon::setTestNow('2026-11-17 09:00:00');
        $agreement = AutoCharge::agreementFor($invoice);
        $this->assertNotNull($agreement, 'precondition: the run picked it with this method');

        ServiceCancellation::create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'moving away']);

        try {
            (new SavedMethodCharge)->charge($invoice, $agreement, ChargeAttempt::SOURCE_CRON);
            $this->fail('charged after a cancellation request');
        } catch (DisplayException) {
        }
        $this->assertSame([], AcPay::$charges);
        $this->assertSame(0, ChargeAttempt::query()->count(), 'nothing sent');
    }

    /** C6. The service's page says it is not charged, and why; not «Yes, with Visa». */
    public function test_c6_the_status_says_a_cancelled_service_is_not_charged(): void
    {
        [$service] = $this->cancelledWithOpenRenewal();
        $this->assertSame(__('services.auto_charge_status.charged', ['method' => 'Visa 4242']), ItemPaymentMethod::autoChargeStatus($service->fresh()));

        ServiceCancellation::create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'moving away']);

        $this->assertSame(__('services.auto_charge_status.cancellation'), ItemPaymentMethod::autoChargeStatus($service->fresh()));
        app()->setLocale('el');
        $this->assertSame('Όχι: έχει ζητηθεί ακύρωση', ItemPaymentMethod::autoChargeStatus($service->fresh()));
    }

    /** A shared renewal of two services and a domain, part paid by a transfer, then one service asked to be cancelled. */
    private function sharedHeld(): array
    {
        $product = $this->product(10);
        [$cancelled, $other] = [$this->serviceOf($product), $this->serviceOf($product)];
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'auto_renew' => true, 'currency_code' => 'USD']);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-17']);
        foreach ([$cancelled, $other] as $service) {
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10,
                'period_start' => '2026-11-17', 'period_end' => '2026-12-17']);
        }
        $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'description' => 'Domain renewal', 'quantity' => 1, 'price' => 12]);
        ExtensionHelper::addPayment($invoice->id, null, '4.00', null, 'bank-1', currency: 'USD');
        ServiceCancellation::create(['service_id' => $cancelled->id, 'type' => 'end_of_period', 'reason' => 'moving away']);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: left open (transfer money on it)');

        return [$cancelled, $other, $domain, $invoice];
    }

    /** C7. Staff are told once of a shared invoice the cancellation keeps from the charge; who is charged stays as is. */
    public function test_c7_staff_are_told_once_of_a_shared_invoice_held_by_a_cancellation(): void
    {
        [$cancelled, , , $invoice] = $this->sharedHeld();
        $held = fn () => EmailLog::query()->where('to', 'staff@example.test')->where('subject', 'like', '%held by a cancellation request%');

        $this->cronAt('2026-11-17 09:00:00');

        $this->assertSame([], AcPay::$charges, 'not charged (as before)');
        $this->assertSame(1, $held()->count());
        $this->assertStringContainsString("Invoice #{$invoice->id}, customer #{$this->client->id}: cancellation requested for service #{$cancelled->id}", (string) $held()->value('body'));

        $this->cronAt('2026-11-18 09:00:00');
        $this->assertSame(1, $held()->count(), 'once');
    }

    /** C6/C7. The other items on it say their open invoice is held by the cancellation, not «open_manual». */
    public function test_c6_the_other_items_say_their_invoice_is_held_by_a_cancellation(): void
    {
        [, $other, $domain, $invoice] = $this->sharedHeld();
        $expected = __('services.auto_charge_status.held_cancellation', ['invoice' => $invoice->id]);

        $this->assertSame($expected, ItemPaymentMethod::autoChargeStatus($other->fresh()));
        $this->assertSame($expected, ItemPaymentMethod::autoChargeStatus($domain->fresh()));
    }

    /** C8. The cancellation is read in the same query as «Do not charge automatically»: no extra query per call. */
    public function test_c8_agreement_for_reads_the_items_once(): void
    {
        [, $invoice] = $this->cancelledWithOpenRenewal();
        $invoice = Invoice::query()->with('client')->find($invoice->id);
        $count = function () use ($invoice): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            AutoCharge::agreementFor($invoice);
            $log = collect(DB::getQueryLog())->pluck('query');
            $queries = [$log->filter(fn (string $query) => str_contains($query, 'service_cancellations'))->count(),
                $log->filter(fn (string $query) => str_contains($query, 'service_cancellations') && str_contains($query, 'no_auto_charge'))->count()];
            DB::disableQueryLog();

            return $queries;
        };

        $this->assertSame([1, 1], $count(), 'one query reads the items, their «Do not charge automatically» and their cancellation');
    }
}
