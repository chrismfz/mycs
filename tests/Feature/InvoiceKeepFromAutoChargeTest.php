<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use App\Services\Invoice\PaymentStatus;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\KeepPay\KeepPay;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * «I'll pay another way» on an invoice the cron charges (owner 2026-11-03, docs/payments.md): the customer keeps this
 * invoice, and only it, from the charge of their saved method, so a transfer is not paid twice. One way: they never undo a
 * hold staff set, and a charge on its way is never left to land on an invoice they pay otherwise.
 */
class InvoiceKeepFromAutoChargeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private BillingAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '',
            'settings.autocharge_max_failures' => 0, 'settings.cronjob_suspend_enabled' => true]);
        if (!class_exists(KeepPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\KeepPay; class KeepPay {
                /** @var \\Closure|null */
                public static $answer = null;
                public static int $charges = 0;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { self::$charges++; return self::$answer === null ? true : (self::$answer)($invoice, $amount); } }');
        }
        KeepPay::$answer = null;
        KeepPay::$charges = 0;
        MailFacade::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'KeepPay'], ['name' => 'KeepPay', 'type' => 'gateway', 'enabled' => true]);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->client->refresh();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An open renewal invoice of an active service, due on $due: the cron charges it. */
    private function invoice(string $due = '2026-11-12', array $service = []): Invoice
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id,
            'price' => 10, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $due, ...$service]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    /** @return list<string> the invoice's audit events of auto-charge */
    private function audits(Invoice $invoice): array
    {
        return Audit::query()->where('auditable_type', $invoice->getMorphClass())->where('auditable_id', $invoice->id)
            ->whereIn('event', ['auto_charge_disabled', 'auto_charge_enabled'])->orderBy('id')->pluck('event')->all();
    }

    public function test_the_customer_keeps_this_invoice_from_the_charge_and_pays_it_another_way(): void
    {
        $invoice = $this->invoice();
        $next = $this->invoice('2026-11-20');
        $this->assertTrue(PaymentStatus::for($invoice)->charged());

        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->assertSee(__('invoices.keep_from_charge.button'))
            ->call('keepFromAutoCharge')
            ->assertDontSee(__('invoices.keep_from_charge.button'));

        $invoice = $invoice->fresh();
        $this->assertTrue($invoice->no_auto_charge);
        // Its page says when to pay it, the bank transfer shows its details and the reminders are the usual ones.
        $this->assertSame(PaymentStatus::DUE, PaymentStatus::for($invoice)->state);
        $this->assertFalse(PaymentReminders::chargedOnItsOwn($invoice));
        // Who stopped the collection, in the invoice's audit, as the customer.
        $this->assertSame(['auto_charge_disabled'], $this->audits($invoice));
        $audit = Audit::query()->where('auditable_id', $invoice->id)->where('event', 'auto_charge_disabled')->sole();
        $this->assertSame('client', $audit->new_values['by']);
        $this->assertSame($this->user->id, $audit->new_values['user_id']);

        // On its due date the cron leaves it, and charges the account's other renewal as before.
        Carbon::setTestNow('2026-11-20 09:00:00');
        $this->assertSame(1, (new AutoCharge)->run());
        $this->assertSame(0, ChargeAttempt::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame([ChargeAttempt::SOURCE_CRON], ChargeAttempt::query()->where('invoice_id', $next->id)
            ->where('status', ChargeAttempt::STATUS_SUCCEEDED)->pluck('source')->all());

        // The customer may still pay it with the saved method: that is what turning it back on would do.
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice->fresh()])
            ->set('selectedMethod', $this->agreement->ulid)->call('processPayment');
        $this->assertSame([ChargeAttempt::SOURCE_CLIENT], ChargeAttempt::query()->where('invoice_id', $invoice->id)
            ->where('status', ChargeAttempt::STATUS_SUCCEEDED)->pluck('source')->all());
    }

    public function test_a_declined_charge_may_be_kept_too(): void
    {
        $invoice = $this->invoice('2026-11-10');
        KeepPay::$answer = fn () => throw new DisplayException('Card declined');
        (new AutoCharge)->run();
        $this->assertSame(PaymentStatus::RETRY, PaymentStatus::for($invoice->fresh())->state);

        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice->fresh()])
            ->assertSee(__('invoices.keep_from_charge.button'))->call('keepFromAutoCharge');

        Carbon::setTestNow('2026-11-11 09:00:00');
        KeepPay::$charges = 0;
        (new AutoCharge)->run();
        $this->assertSame(0, KeepPay::$charges, 'not retried');
        $this->assertSame(PaymentStatus::OVERDUE, PaymentStatus::for($invoice->fresh())->state);
    }

    public function test_one_the_cron_does_not_charge_offers_nothing_and_changes_nothing(): void
    {
        // Staff made it: no service line.
        $manual = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-12']);
        $manual->items()->create(['description' => 'Setup', 'price' => '10.00', 'quantity' => 1]);
        // A gateway subscription pays it: the flag would not stop that, so it is not offered.
        $subscribed = $this->invoice('2026-11-12', ['subscription_id' => 'I-SUB1']);
        // Paid already.
        $paid = $this->invoice();
        Invoice::query()->whereKey($paid->id)->update(['status' => Invoice::STATUS_PAID]);

        foreach ([$manual->fresh(), $subscribed, $paid->fresh()] as $invoice) {
            $this->assertFalse(PaymentStatus::for($invoice)->charged());
            $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
                ->assertDontSee(__('invoices.keep_from_charge.button'))->call('keepFromAutoCharge');
            $this->assertFalse($invoice->fresh()->no_auto_charge, "invoice #{$invoice->id}");
            $this->assertSame([], $this->audits($invoice));
        }
    }

    public function test_a_hold_staff_set_is_never_undone_from_the_customer_side(): void
    {
        $invoice = $this->invoice();
        $invoice->forceFill(['no_auto_charge' => true])->save();

        $page = $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice->fresh()])
            ->assertDontSee(__('invoices.keep_from_charge.button'))->call('keepFromAutoCharge');
        $this->assertTrue($invoice->fresh()->no_auto_charge);
        $this->assertSame([], $this->audits($invoice));
        // The component has no way to turn it back on.
        $this->assertFalse(method_exists($page->instance(), 'allowAutoCharge'));
    }

    public function test_never_while_a_charge_of_it_may_still_land(): void
    {
        $invoice = $this->invoice('2026-11-10');
        ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id, 'client_id' => $this->client->id,
            'billing_agreement_id' => $this->agreement->id, 'amount' => '10.00', 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => ChargeAttempt::STATUS_STARTED, 'started_at' => now(), 'sent_at' => now()]);

        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->assertDontSee(__('invoices.keep_from_charge.button'))->call('keepFromAutoCharge');
        $this->assertFalse($invoice->fresh()->no_auto_charge, 'a transfer now could pay it twice: the charge may still land');
    }

    public function test_the_crons_charge_reads_the_flag_again_under_the_lock(): void
    {
        // The run read it before the customer kept it: its charge, under the lock the flag is set under, sends nothing.
        $invoice = $this->invoice('2026-11-10');
        $agreement = AutoCharge::agreementFor($invoice);
        $this->assertNotNull($agreement);
        Invoice::query()->whereKey($invoice->id)->update(['no_auto_charge' => true]);

        try {
            (new SavedMethodCharge)->charge($invoice, $agreement, ChargeAttempt::SOURCE_CRON);
            $this->fail('the cron charged an invoice kept from it');
        } catch (DisplayException) {
        }
        $this->assertSame(0, KeepPay::$charges);
        $this->assertSame(0, ChargeAttempt::query()->where('invoice_id', $invoice->id)->count());

        // The customer paying it with that method is their own choice: charged.
        $attempt = (new SavedMethodCharge)->charge($invoice, $agreement, ChargeAttempt::SOURCE_CLIENT);
        $this->assertSame(ChargeAttempt::STATUS_SUCCEEDED, $attempt->status);
    }
}
