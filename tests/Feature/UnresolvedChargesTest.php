<?php

namespace Tests\Feature;

use App\Admin\Actions\ResolveCharge;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Livewire\Invoices\PayTogether;
use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\SavedMethodCharge;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Str;
use MyCS\Extensions\Gateways\AcrPay\AcrPay;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Predeploy review of main d1015018, S1–S3 (the reviewer's reproductions, kept): a charge whose outcome is not settled
 * holds its invoice for every method and every way of paying, however long, until its payment is recorded or staff
 * resolve it; an invoice with one is not deleted. Gateways are synthetic; no network money calls.
 */
#[Group('predeploy-review')]
class UnresolvedChargesTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $reviewUser;

    private BillingAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0,
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.mass_pay_enabled' => true]);
        if (!class_exists(AcrPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AcrPay; class AcrPay {
                /** @var list<array{invoice: int, amount: float, key: ?string}> */
                public static array $charges = [];
                public static int $checkouts = 0;
                public function pay($invoice, $amount) { self::$checkouts++; return "https://example.test/checkout"; }
                /** @var \\Closure|null */
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function supportsPaymentGroups(): bool { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "amount" => (float) $amount, "key" => $invoice->chargeKey];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        AcrPay::$charges = [];
        AcrPay::$checkouts = 0;
        AcrPay::$answer = null; // by default: the money is taken, its payment is recorded later (Stripe's webhook)
        MailFacade::fake();
        Carbon::setTestNow('2026-11-10 09:00:00');
        $this->reviewUser = User::factory()->create();
        $this->client = $this->reviewUser->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcrPay'], ['name' => 'AcrPay', 'type' => 'gateway', 'enabled' => true]);
        $this->agreement = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_r1', 'type' => 'card']);
        $this->client->refresh();
        $this->assertSame($this->agreement->id, $this->client->autoChargeAgreement()?->id, 'precondition: the first method is the default');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An open renewal invoice of an active service, due on $due. */
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

    public function test_unconfirmed_charge_is_not_taken_again_by_next_daily_run(): void
    {
        $invoice = $this->invoice('2026-11-10');
        $this->client->forceFill(['auto_charge' => true])->save();
        AcrPay::$answer = fn () => throw new \RuntimeException('Response lost after gateway accepted charge');
        (new AutoCharge)->run();
        $this->assertCount(1, AcrPay::$charges);
        Carbon::setTestNow('2026-11-11 09:00:00');
        (new AutoCharge)->run();
        $this->assertCount(1, array_unique(array_column(AcrPay::$charges, 'key')), 'Unresolved outcome must not create a new charge next day');
    }

    public function test_crashed_charge_holds_invoice_when_customer_switches_card_after_fifteen_minutes(): void
    {
        $invoice = $this->invoice('2026-11-10');
        ChargeAttempt::query()->create(['key' => (string) Str::uuid(), 'invoice_id' => $invoice->id,
            'client_id' => $this->client->id, 'billing_agreement_id' => $this->agreement->id,
            'amount' => 10, 'currency_code' => 'USD', 'source' => ChargeAttempt::SOURCE_CLIENT,
            'status' => ChargeAttempt::STATUS_STARTED, 'started_at' => now(), 'sent_at' => now()]);
        $other = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->agreement->gateway_id,
            'name' => 'Other card', 'external_reference' => 'pm_other', 'type' => 'card']);
        Carbon::setTestNow('2026-11-10 09:16:00');
        try {
            (new SavedMethodCharge)->charge($invoice, $other, ChargeAttempt::SOURCE_CLIENT);
        } catch (DisplayException) {
        }
        $this->assertCount(0, AcrPay::$charges, 'The first card may already have paid: do not charge another');
    }

    public function test_invoice_with_taken_charge_cannot_be_deleted_before_webhook_arrives(): void
    {
        $invoice = $this->invoice('2026-11-10');
        (new SavedMethodCharge)->charge($invoice, $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice));
        try {
            $invoice->delete();
        } catch (DisplayException) {
        }
        $this->assertNotNull(Invoice::find($invoice->id), 'A captured payment must retain its invoice until its webhook records it');
    }

    public function test_gateway_checkout_is_blocked_while_saved_card_payment_is_on_its_way(): void
    {
        $invoice = $this->invoice('2026-11-10');
        (new SavedMethodCharge)->charge($invoice, $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice));
        $this->livewireAs($this->reviewUser)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $this->agreement->gateway_id)->call('processPayment');
        $this->assertSame(0, AcrPay::$checkouts, 'Do not offer a second checkout while the first payment has already been taken');
    }

    public function test_mass_pay_checkout_is_blocked_while_group_card_payment_is_on_its_way(): void
    {
        $a = $this->invoice('2026-11-09');
        $b = $this->invoice('2026-11-10');
        $group = (new MassPay)->open($this->client, [$a->id, $b->id]);
        (new SavedMethodCharge)->charge($group->invoice, $this->agreement, ChargeAttempt::SOURCE_GROUP, $group);
        $this->assertTrue(SavedMethodCharge::onItsWay($a));
        $this->livewireAs($this->reviewUser)->test(PayTogether::class, ['paymentGroup' => $group])
            ->set('selectedMethod', 'gateway-' . $this->agreement->gateway_id)->call('processPayment');
        $this->assertSame(0, AcrPay::$checkouts, 'Do not open another checkout for a group already charged');
    }

    /** The key is resent only while the gateway keeps it; past it, no new charge: the invoice stays held for staff. */
    public function test_past_the_key_window_nothing_is_sent_again_and_staff_are_told(): void
    {
        $invoice = $this->invoice('2026-11-10');
        $this->client->forceFill(['auto_charge' => true])->save();
        AcrPay::$answer = fn () => throw new \RuntimeException('timeout');
        (new AutoCharge)->run();
        Carbon::setTestNow('2026-11-10 10:30:00'); // an hour and more later: staff told
        (new AutoCharge)->run();
        $this->assertSame(1, EmailLog::query()->where('subject', 'Automatic charges not settled')->where('body', 'like', '%unknown%')->count(), 'staff told');
        Carbon::setTestNow('2026-11-12 09:00:00');
        (new AutoCharge)->run();
        $this->assertCount(1, AcrPay::$charges, 'past the key window: nothing sent again');
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()), 'still held');
    }

    /** Staff check at the gateway: not taken. «Resolve charge» releases the invoice, audited; it can be paid again. */
    public function test_staff_resolve_an_unsettled_charge_as_not_taken(): void
    {
        $invoice = $this->invoice('2026-11-10');
        AcrPay::$answer = fn () => throw new \RuntimeException('timeout');
        $attempt = (new SavedMethodCharge)->charge($invoice, $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
            ->callAction('resolveCharge', ['attempt' => (string) $attempt->id, 'outcome' => ResolveCharge::NOT_TAKEN])->assertHasNoActionErrors();

        $this->assertSame(ChargeAttempt::STATUS_FAILED, $attempt->fresh()->status);
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()), 'released');
        $this->assertTrue($invoice->audits()->where('event', 'charge_resolved_not_taken')->exists(), 'audited');
    }

    /**
     * A group's charge of unknown outcome holds every invoice of the group; on another invoice of it staff see it and settle
     * it: taken, its payment recorded another way (code review of #581). A charge still on its way is not offered.
     */
    public function test_staff_settle_a_group_charge_from_another_invoice_of_it(): void
    {
        $a = $this->invoice('2026-11-09');
        $b = $this->invoice('2026-11-10');
        $group = (new MassPay)->open($this->client, [$a->id, $b->id]);
        AcrPay::$answer = fn () => throw new \RuntimeException('timeout');
        $attempt = (new SavedMethodCharge)->charge($group->invoice, $this->agreement, ChargeAttempt::SOURCE_GROUP, $group);
        $this->assertSame(ChargeAttempt::STATUS_UNKNOWN, $attempt->fresh()->status);
        $this->assertTrue(SavedMethodCharge::onItsWay($b->fresh()), 'the group charge holds B');
        $this->assertTrue(SavedMethodCharge::holding($b->fresh())->contains('id', $attempt->id), 'and is shown on B');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(EditInvoice::class, ['record' => $b->getRouteKey()])
            ->callAction('resolveCharge', ['attempt' => (string) $attempt->id, 'outcome' => ResolveCharge::RECORDED])->assertHasNoActionErrors();

        $this->assertSame(ChargeAttempt::STATUS_SETTLED, $attempt->fresh()->status);
        $this->assertFalse(SavedMethodCharge::onItsWay($b->fresh()), 'released');
        $this->assertTrue($b->audits()->where('event', 'charge_resolved_recorded')->exists(), 'audited');
    }

    /** A payment recorded for it (the webhook late, or staff with the gateway's id) settles an unknown outcome too. */
    public function test_a_payment_recorded_settles_an_unknown_outcome(): void
    {
        $invoice = $this->invoice('2026-11-10');
        AcrPay::$answer = fn () => throw new \RuntimeException('timeout');
        (new SavedMethodCharge)->charge($invoice, $this->agreement, ChargeAttempt::SOURCE_CLIENT);
        Carbon::setTestNow('2026-11-10 09:05:00');
        ExtensionHelper::addPayment($invoice->id, 'AcrPay', 5, null, 'late-webhook', currency: 'USD');
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()), 'a smaller payment is not it');
        ExtensionHelper::addPayment($invoice->id, 'AcrPay', 10, null, 'late-webhook-2', currency: 'USD');
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()));
    }
}
