<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Helpers\ExtensionHelper;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Payment\AutoCharge;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\SplitInvoice;
use App\Services\Payment\CardExpiryNotices;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail as MailFacade;
use MyCS\Extensions\Gateways\AcPay\AcPay;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * PR H2b: an invoice staff keep from the cron's charge (WHMCS «Do not apply auto-charge»), and the monthly expiry notice
 * of saved payment methods (WHMCS CCDaySendExpiryNotices = 25).
 */
class AutoChargeExtrasTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private BillingAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 8, 'settings.cc_expiry_notice_day' => 25]);
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
        AcPay::$answer = function ($invoice, $amount) {
            ExtensionHelper::addPayment($invoice->id, 'AcPay', $amount, null, 'ch_' . count(AcPay::$charges), currency: 'USD');

            return true;
        };
        MailFacade::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway']);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card', 'expiry' => '2026-11-30']);
        $this->client->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $due): Invoice
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $due]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    private function expiryMails(): int
    {
        return count(MailFacade::queued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'card_expiring'));
    }

    public function test_an_invoice_staff_keep_from_auto_charge_is_not_charged(): void
    {
        $kept = $this->invoice('2026-11-10');
        $other = $this->invoice('2026-11-10');
        Invoice::query()->whereKey($kept->id)->update(['no_auto_charge' => true]);

        $this->assertSame(1, (new AutoCharge)->run());

        $this->assertSame([$other->id], array_column(AcPay::$charges, 'invoice'));
        $this->assertSame([Invoice::STATUS_UNPAID, Invoice::STATUS_PAID], [$kept->fresh()->status, $other->fresh()->status]);
        $this->assertNull(AutoCharge::agreementFor($kept->fresh()), 'the reminder does not say it will be charged');
    }

    public function test_staff_toggle_it_from_the_invoice_and_it_is_charged_again(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create();
        $invoice = $this->invoice('2026-11-10');

        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoice->getRouteKey()])->callAction('toggleAutoCharge');
        $this->assertTrue($invoice->fresh()->no_auto_charge);
        $this->assertSame(0, (new AutoCharge)->run());

        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoice->getRouteKey()])->callAction('toggleAutoCharge');
        $this->assertFalse($invoice->fresh()->no_auto_charge);
        // Who stopped, and allowed, the collection is in the invoice's audit (code review CR-4).
        $this->assertSame(['auto_charge_disabled', 'auto_charge_enabled'], Audit::query()->where('auditable_type', $invoice->getMorphClass())
            ->where('auditable_id', $invoice->id)->whereIn('event', ['auto_charge_disabled', 'auto_charge_enabled'])->orderBy('id')->pluck('event')->all());
        $this->assertSame(1, (new AutoCharge)->run());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_expiry_notice_from_day_25_once_a_month(): void
    {
        $this->assertSame(0, (new CardExpiryNotices)->run(), 'not before the day set');

        Carbon::setTestNow('2026-11-25 09:00:00');
        $this->assertSame(1, (new CardExpiryNotices)->run());
        $this->assertSame(0, (new CardExpiryNotices)->run(), 'once');
        Carbon::setTestNow('2026-11-28 09:00:00');
        $this->assertSame(0, (new CardExpiryNotices)->run(), 'once a month');
        $this->assertSame(1, $this->expiryMails());
        $this->assertNotNull($this->agreement->fresh()->expiry_notified_at);
    }

    public function test_a_missed_day_is_sent_the_next_and_only_this_months_expiry(): void
    {
        $later = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->agreement->gateway_id,
            'name' => 'Mastercard 4444', 'external_reference' => 'pm_2', 'type' => 'card', 'expiry' => '2026-12-31']);
        BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->agreement->gateway_id,
            'name' => 'PayPal', 'external_reference' => 'ba_1', 'type' => 'paypal', 'expiry' => null]);

        Carbon::setTestNow('2026-11-27 09:00:00'); // the cron did not run on the 25th
        $this->assertSame(1, (new CardExpiryNotices)->run());

        Carbon::setTestNow('2026-12-25 09:00:00');
        $this->assertSame(1, (new CardExpiryNotices)->run(), 'the next month: the one expiring then');
        $this->assertNotNull($later->fresh()->expiry_notified_at);
        $this->assertSame(2, $this->expiryMails());
    }

    public function test_a_removed_method_or_the_notice_off_sends_nothing(): void
    {
        Carbon::setTestNow('2026-11-25 09:00:00');
        config(['settings.cc_expiry_notice_day' => 0]);
        $this->assertSame(0, (new CardExpiryNotices)->run());

        config(['settings.cc_expiry_notice_day' => 25]);
        $this->agreement->delete();
        $this->assertSame(0, (new CardExpiryNotices)->run());
        $this->assertSame(0, $this->expiryMails());
    }

    public function test_the_cron_sends_the_expiry_notices(): void
    {
        Carbon::setTestNow('2026-11-25 09:00:00');

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(1, $this->expiryMails());
    }

    public function test_a_split_keeps_the_flag_on_both_parts(): void
    {
        // Code review CR-2.
        $invoice = $this->invoice('2026-11-10');
        $line = $invoice->items()->create(['description' => 'Extra', 'price' => '5.00', 'quantity' => 1]);
        Invoice::query()->whereKey($invoice->id)->update(['no_auto_charge' => true]);

        $new = (new SplitInvoice)->handle($invoice->id, [$line->id]);

        $this->assertTrue($new->fresh()->no_auto_charge);
        $this->assertSame(0, (new AutoCharge)->run());
        $this->assertSame([], AcPay::$charges);
    }

    public function test_a_merge_with_a_kept_invoice_keeps_the_merged_one(): void
    {
        // Code review CR-2.
        $target = $this->invoice('2026-11-10');
        $kept = $this->invoice('2026-11-10');
        Invoice::query()->whereKey($kept->id)->update(['no_auto_charge' => true]);

        (new MergeInvoices)->handle($target->id, [$kept->id]);

        $this->assertTrue($target->fresh()->no_auto_charge);
        $this->assertSame(0, (new AutoCharge)->run());
        $this->assertSame([], AcPay::$charges);
    }
}
