<?php

namespace Tests\Feature;

use App\Livewire\Invoices\Show;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\CronStat;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use App\Services\Invoice\PaymentStatus;
use App\Services\Payment\AutoCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\AdvKeepPay\AdvKeepPay;
use Tests\TestCase;

/**
 * Adversarial review of #623 («I'll pay another way» keeps one invoice from the cron's charge). Each test fails on the PR
 * head for a real defect.
 */
class Adv623Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private BillingAgreement $agreement;

    private Gateway $bank;

    private Product $product;

    private Plan $monthly;

    private Plan $yearly;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '',
            'settings.autocharge_max_failures' => 0, 'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_order_suspend' => 8]);
        if (!class_exists(AdvKeepPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AdvKeepPay; class AdvKeepPay {
                public static array $charges = [];
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { self::$charges[] = [(int) $invoice->id, number_format((float) $amount, 2, ".", "")]; return true; } }');
        }
        AdvKeepPay::$charges = [];
        MailFacade::fake();
        Queue::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AdvKeepPay'], ['name' => 'AdvKeepPay', 'type' => 'gateway', 'enabled' => true]);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->forceFill(['auto_charge' => true, 'default_billing_agreement_id' => $this->agreement->id])->save();
        $this->client->refresh();
        $this->bank = Gateway::query()->create(['extension' => 'BankTransfer', 'name' => 'Bank transfer', 'type' => 'gateway', 'enabled' => true]);
        $this->bank->settings()->create(['key' => 'accounts', 'value' => "Example Bank\nIBAN: GR00 0000 0000 0000 0000 0000 000"]);

        $this->product = Product::factory()->create(['name' => 'Hosting']);
        $this->monthly = $this->plan('Monthly', 'month', 10.00);
        $this->yearly = $this->plan('Yearly', 'year', 100.00);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(string $name, string $unit, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class, 'name' => $name,
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    /** A monthly service paid to $due, and its open renewal invoice from $due: the cron charges it. */
    private function renewal(string $due = '2026-11-12', array $service = []): Invoice
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $this->product->id, 'plan_id' => $this->monthly->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $due, ...$service]);
        $start = Carbon::parse($due);
        $paid = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => $start->copy()->subMonth()]);
        $paid->items()->create(['description' => 'Paid period', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id, 'period_start' => $start->copy()->subMonth(), 'period_end' => $start]);
        DB::table('invoices')->where('id', $paid->id)->update(['status' => Invoice::STATUS_PAID]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id, 'period_start' => $start, 'period_end' => $start->copy()->addMonth()]);

        return $invoice->fresh();
    }

    /**
     * ADV-623-1 (cycle change drops the customer's hold). The customer wants to pay this renewal by transfer and to move to
     * a yearly cycle: they press «I'll pay another way», then change the service's cycle «from the next renewal» (their own
     * page). ChangeCycle cancels the kept renewal of the old cycle and the cron bills the same period again on a new
     * invoice without the flag, then charges it to the saved card on the due date. The period the customer said they pay
     * by transfer is charged to the card: the transfer they make pays it twice.
     */
    public function test_adv_623_1_a_cycle_change_does_not_bring_the_charge_back_on_a_kept_period(): void
    {
        config(['settings.cronjob_invoice' => 7]);
        $kept = $this->renewal('2026-11-12');
        $service = Service::query()->findOrFail($kept->items()->value('reference_id'));
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $kept])->call('keepFromAutoCharge');
        $this->assertTrue($kept->fresh()->no_auto_charge, 'precondition: kept');

        // Fixed by refusing the change while the kept invoice is open (ChangeCycle::refusal): the kept invoice stays, the
        // period is never billed again on an invoice the cron charges (author, review of #623).
        $this->livewireAs($this->user)->test(ServiceShow::class, ['service' => $service])->set('cyclePlan', $this->yearly->id)->call('changeCycle');
        $this->assertNull($service->fresh()->next_plan_id, 'the change is refused while the kept invoice is open');
        $this->assertSame(Invoice::STATUS_UNPAID, $kept->fresh()->status, 'the kept renewal stays');

        Carbon::setTestNow('2026-11-12 09:00:00');
        $this->artisan('app:cron-job');

        $this->assertSame([], AdvKeepPay::$charges,
            'the card is charged for the period the customer chose to pay another way (a transfer would pay it twice)');
    }

    /**
     * ADV-623-2 (the transfer window points to a button that is not there). The bank transfer's «charged» text now says
     * «close this window and choose "I'll pay another way" at the top of the invoice». It is shown for every invoice
     * PaymentReminders::chargedOnItsOwn says is paid on its own, which includes a gateway subscription's: there the button
     * is never offered (and the server refuses it). The customer of a subscription's invoice, who wants to pay by
     * transfer, is sent to a button that does not exist and gets no bank details (before the PR: «contact us first»).
     */
    public function test_adv_623_2_the_transfer_window_of_a_subscription_invoice_points_to_no_button(): void
    {
        $invoice = $this->renewal('2026-11-12', ['subscription_id' => 'I-SUB1']);
        $this->assertTrue(PaymentReminders::chargedOnItsOwn($invoice), 'precondition: paid on its own (the subscription)');
        $this->assertFalse(PaymentStatus::for($invoice)->charged(), 'precondition: no «I\'ll pay another way» on it');

        $html = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $this->bank->id)->call('processPayment')->html();

        $pointer = e(__('invoices.bank_transfer_charged', ['date' => '12/11/2026']));
        if (str_contains($html, $pointer)) {
            $this->assertStringContainsString('wire:click="keepFromAutoCharge"', $html,
                'the transfer window sends the customer to «I\'ll pay another way», which this invoice does not offer');
        }
        $this->assertTrue(true);
    }

    /**
     * ADV-623-3 (the reverse: willCharge yes, button no). On the last day of its window, once today's run is over, the page
     * no longer says «will be paid automatically» (nextAttempt: tomorrow is past the window) and shows no button; but
     * willCharge, which the bank transfer and the server's keepFromAutoCharge read, still says the cron charges it. The
     * transfer window refuses the bank details («will be paid automatically on 02/11/2026», a past date) and sends the
     * customer to a button the page does not show, for an invoice the cron will never charge again.
     */
    public function test_adv_623_3_on_the_last_day_after_the_run_the_transfer_window_points_to_no_button(): void
    {
        $invoice = $this->renewal('2026-11-02'); // day 8 today: the last day before the suspension
        Setting::query()->create(['key' => 'last_cron_run', 'settingable_type' => CronStat::class, 'value' => '2026-11-10 08:00:00']);
        $this->assertFalse(PaymentStatus::for($invoice)->charged(), 'precondition: the page offers no «I\'ll pay another way»');
        $this->assertNull(AutoCharge::nextAttempt($invoice), 'precondition: the cron charges it on no day to come');
        $this->assertTrue(AutoCharge::willCharge($invoice), 'precondition: yet the transfer and the server say it does');

        $html = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $this->bank->id)->call('processPayment')->html();

        $pointer = e(__('invoices.bank_transfer_charged', ['date' => '02/11/2026']));
        if (str_contains($html, $pointer)) {
            $this->assertStringContainsString('wire:click="keepFromAutoCharge"', $html,
                'the transfer window sends the customer to «I\'ll pay another way», which the page does not show');
        }
        $this->assertTrue(true);
    }
}
