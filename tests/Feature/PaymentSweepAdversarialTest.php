<?php

namespace Tests\Feature;

use App\Admin\Resources\GatewayResource\Pages\EditGateway;
use App\Livewire\Client\PaymentMethods;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use App\Services\Money\BankTransferDetails;
use App\Services\Payment\AutoCharge;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\AdvSweepCardPay\AdvSweepCardPay;
use Tests\TestCase;

/**
 * Adversarial review of the payment-methods sweep (#580). Each test fails on the PR head.
 */
class PaymentSweepAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $cardGateway;

    private Gateway $bank;

    private BillingAgreement $card;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '',
            'settings.autocharge_max_failures' => 0, 'settings.cronjob_order_suspend' => 8]);
        if (!class_exists(AdvSweepCardPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AdvSweepCardPay; class AdvSweepCardPay {
                public static array $charges = [];
                public static array $cancelled = [];
                public static bool $decline = true;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { self::$cancelled[] = $agreement->id; return true; }
                public function charge($invoice, $amount, $agreement) { self::$charges[] = $invoice->id; return !self::$decline; } }');
        }
        AdvSweepCardPay::$charges = [];
        AdvSweepCardPay::$cancelled = [];
        AdvSweepCardPay::$decline = true;
        Mail::fake();
        Carbon::setTestNow('2026-11-01 09:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->cardGateway = Gateway::query()->create(['name' => 'Card', 'extension' => 'AdvSweepCardPay', 'type' => 'gateway', 'enabled' => true]);
        $this->bank = Gateway::query()->create(['extension' => 'BankTransfer', 'name' => 'Bank transfer', 'type' => 'gateway', 'enabled' => true]);
        $this->bank->settings()->create(['key' => 'accounts', 'value' => "Example Bank\nIBAN: GR00 0000 0000 0000 0000 0000 000"]);
        $this->card = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->cardGateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'visa']);
        $this->client->forceFill(['auto_charge' => true])->save();
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
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 10,
            'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $due]);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => $due]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id]);

        return $invoice->fresh();
    }

    /** The cron run on each day given, its card declining. @return int the charges sent */
    private function runOn(array $days): int
    {
        $before = count(AdvSweepCardPay::$charges);
        foreach ($days as $day) {
            Carbon::setTestNow($day . ' 09:00:00');
            (new AutoCharge)->run();
        }

        return count(AdvSweepCardPay::$charges) - $before;
    }

    /**
     * ADV-SWEEP-1: retry days set (1,3), Suspend Days 8. The card declined on the due date and on days 1 and 3: the cron
     * tries no more (no retry day is left before suspension), yet willCharge() still says «charged on its own» on days
     * 4–8: the invoice page refuses the bank transfer, the PDF and mails leave out the bank details, until it is suspended.
     */
    public function test_adv_sweep_1_retry_days_exhausted_is_still_said_to_be_charged(): void
    {
        config(['settings.autocharge_retry_days' => '1,3']);
        $invoice = $this->invoice('2026-10-27');
        $this->assertSame(3, $this->runOn(['2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30']), 'precondition: tried on days 0, 1, 3');
        $this->assertSame(0, $this->runOn(['2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04']), 'precondition: never tried again up to suspension');

        Carbon::setTestNow('2026-10-31 09:00:00'); // day 4: nothing will charge it any more
        $invoice = $invoice->fresh();
        $this->assertFalse(PaymentReminders::chargedOnItsOwn($invoice), 'the cron will not charge it again: the customer must pay it');
        $this->assertNotNull(BankTransferDetails::for($invoice), 'the bank details on its PDF and mails');
    }

    /**
     * ADV-SWEEP-2: no retry days, Suspend Days 8, due 8 days ago: today's charge (the last try, offset 8) declined. The
     * cron never tries it again (offset 9 > window), but willCharge() says it will: the «payment failed» mail sent right
     * now («pay the invoice from your client area») goes without the bank details, and the bank transfer is refused.
     */
    public function test_adv_sweep_2_last_try_declined_is_still_said_to_be_charged(): void
    {
        $invoice = $this->invoice('2026-10-24');
        $this->assertSame(1, $this->runOn(['2026-11-01']), 'precondition: the last try, declined');
        $this->assertSame(0, $this->runOn(['2026-11-02', '2026-11-03']), 'precondition: never tried again');

        Carbon::setTestNow('2026-11-01 10:00:00');
        $invoice = $invoice->fresh();
        $this->assertSame(ChargeAttempt::STATUS_FAILED, ChargeAttempt::query()->where('invoice_id', $invoice->id)->value('status'));
        $this->assertFalse(PaymentReminders::chargedOnItsOwn($invoice), 'its last try failed: the customer must pay it');
        $this->assertNotNull(BankTransferDetails::for($invoice), 'the bank details on the «payment failed» mail and PDF');
    }

    /**
     * ADV-SWEEP-3 (SW-1): keys changed from sandbox to live, and Stripe refuses the webhook (a restricted key without
     * Webhook Endpoints write, say). The save succeeds, the sandbox account's secret stays as if it were the live one's,
     * and staff are not told: the very failure SW-1 fixes (no live payment recorded, nobody alerted), silent again.
     */
    public function test_adv_sweep_3_a_webhook_stripe_refused_is_silent_and_keeps_the_other_accounts_secret(): void
    {
        Http::fake(['api.stripe.com/v1/webhook_endpoints*' => function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['data' => []]);
            }

            return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'The provided key does not have the required permissions']], 403);
        }, 'api.stripe.com/*' => Http::response(['object' => 'balance', 'available' => [], 'pending' => []])]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);
        $stripe = Gateway::query()->create(['extension' => 'Stripe', 'name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $stripe->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test_sandbox', 'encrypted' => true]);
        $stripe->settings()->create(['key' => 'stripe_publishable_key', 'value' => 'pk_test_sandbox']);
        $stripe->settings()->create(['key' => 'stripe_webhook_secret', 'value' => 'whsec_sandbox', 'encrypted' => true]);
        $stripe->settings()->create(['key' => 'stripe_webhook_for', 'value' => substr(hash('sha256', 'sk_test_sandbox'), 0, 16)]);

        $this->livewireAs($staff)->test(EditGateway::class, ['record' => $stripe->getRouteKey()])
            ->set('data.settings.stripe_secret_key', 'sk_live_new')->set('data.settings.stripe_publishable_key', 'pk_live_new')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('sk_live_new', $stripe->fresh()->settings()->where('key', 'stripe_secret_key')->first()?->value, 'precondition: the live key saved');

        app(NotificationMailbox::class)->flush();
        $notifications = new Notifications;
        $notifications->mount();
        $notifications->pullNotificationsFromSession();
        $warned = collect($notifications->notifications)->contains(fn (Notification $n) => in_array($n->getStatus(), ['danger', 'warning'], true));
        $secret = $stripe->fresh()->settings()->where('key', 'stripe_webhook_secret')->first()?->value;
        $this->assertTrue($warned || $secret !== 'whsec_sandbox',
            'the live key has no webhook: staff must be told, not left with the sandbox account\'s secret as if it were live');
    }

    /**
     * ADV-SWEEP-4 (SW-3): a gateway staff deleted is soft-deleted, its settings kept. Removing a method of it now skips
     * the gateway's cancel: the billing agreement stays live at the provider (PayPal's «automatic payments») while the
     * customer is told it was removed. «Nothing left to cancel there» holds only for an extension that is gone.
     */
    public function test_adv_sweep_4_a_method_of_a_soft_deleted_gateway_is_not_cancelled_there(): void
    {
        $this->cardGateway->delete();
        $this->assertNotNull(Gateway::withTrashed()->find($this->cardGateway->id), 'precondition: soft-deleted, settings kept');

        $this->livewireAs($this->user)->test(PaymentMethods::class)->call('removePaymentMethod', $this->card->ulid);

        $this->assertSoftDeleted($this->card);
        $this->assertSame([$this->card->id], AdvSweepCardPay::$cancelled, 'cancelled at the provider, with the deleted gateway\'s settings');
    }
}
