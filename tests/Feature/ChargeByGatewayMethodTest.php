<?php

namespace Tests\Feature;

use App\Livewire\Client\PaymentMethods;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\ItemPaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Gateways\GwCardPay\GwCardPay;
use MyCS\Extensions\Gateways\GwVaultPay\GwVaultPay;
use Tests\TestCase;

/**
 * A service or domain paid with another gateway than the default's is charged to the account's saved method of that
 * gateway, as WHMCS charges an invoice with its own gateway's method (owner 2026-11-01, replay of 1 Nov: 4 invoices the
 * card would have left unpaid). Never to a method of another gateway; not with «Auto-charge» off, a disabled gateway, an
 * invoice of two ways or one staff keep from it.
 */
class ChargeByGatewayMethodTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    private Gateway $cardGateway;

    private Gateway $vaultGateway;

    private BillingAgreement $card;

    private BillingAgreement $vault;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.credits_auto_use' => false, 'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '',
            'settings.autocharge_max_failures' => 0, 'settings.cronjob_order_suspend' => 8]);
        foreach (['GwCardPay', 'GwVaultPay'] as $name) {
            if (!class_exists("MyCS\\Extensions\\Gateways\\{$name}\\{$name}")) {
                eval("namespace MyCS\\Extensions\\Gateways\\{$name}; class {$name} {
                    /** @var list<array{invoice: int, agreement: int}> */
                    public static array \$charges = [];
                    public static bool \$decline = false;
                    public function __construct(\$config = []) {}
                    public function supportsBillingAgreements() { return true; }
                    public function cancelBillingAgreement(\$agreement): bool { return true; }
                    public function charge(\$invoice, \$amount, \$agreement) {
                        self::\$charges[] = ['invoice' => \$invoice->id, 'agreement' => \$agreement->id];
                        if (self::\$decline) { throw new \\App\\Exceptions\\DisplayException('Card declined'); }
                        return true;
                    } }");
            }
        }
        GwCardPay::$charges = GwVaultPay::$charges = [];
        GwCardPay::$decline = GwVaultPay::$decline = false;
        Mail::fake();
        Carbon::setTestNow('2026-11-01 09:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
        $this->cardGateway = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'GwCardPay', 'type' => 'gateway', 'enabled' => true]);
        $this->vaultGateway = Gateway::query()->create(['name' => 'PayPal', 'extension' => 'GwVaultPay', 'type' => 'gateway', 'enabled' => true]);
        $this->card = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->cardGateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'visa']);
        $this->vault = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->vaultGateway->id,
            'name' => 'PayPal a@example.test', 'external_reference' => 'B-1', 'type' => 'paypal']);
        $this->client->forceFill(['auto_charge' => true])->save();
        $this->assertSame($this->card->id, $this->client->fresh()->default_billing_agreement_id, 'precondition: the first method is the default');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(?Gateway $gateway): Service
    {
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);

        return Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 10,
            'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-01', 'payment_gateway_id' => $gateway?->id]);
    }

    /** An open renewal invoice due today, a line for each service (each line records its service's way as billed). */
    private function invoice(Service ...$services): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-01']);
        foreach ($services as $service) {
            $invoice->items()->create(['description' => 'Renewal', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);
        }

        return $invoice->fresh();
    }

    /** @return array<int, list<int>> invoice => the agreements the cron charged it to */
    private function cron(): array
    {
        (new AutoCharge)->run();
        $charged = [];
        foreach ([...GwCardPay::$charges, ...GwVaultPay::$charges] as $charge) {
            $charged[$charge['invoice']][] = $charge['agreement'];
        }

        return $charged;
    }

    public function test_each_invoice_is_charged_to_the_method_of_its_own_gateway(): void
    {
        $byCard = $this->invoice($this->service(null), $this->service($this->cardGateway));
        $byVault = $this->invoice($this->service($this->vaultGateway));

        $this->assertSame($this->card->id, AutoCharge::agreementFor($byCard)?->id);
        $this->assertSame($this->vault->id, AutoCharge::agreementFor($byVault)?->id, 'the PayPal vault charges what is paid with PayPal');

        $charged = $this->cron();

        $this->assertSame([$byCard->id => [$this->card->id], $byVault->id => [$this->vault->id]], $charged);
        $this->assertSame([$byCard->id], array_column(GwCardPay::$charges, 'invoice'), 'the card never charges the PayPal one');
        $this->assertSame([$byVault->id], array_column(GwVaultPay::$charges, 'invoice'));
    }

    public function test_no_method_of_its_gateway_leaves_it_to_the_customer_never_to_another_one(): void
    {
        $this->vault->delete(); // removed by the customer
        $byVault = $this->invoice($this->service($this->vaultGateway));
        $bank = Gateway::query()->create(['name' => 'Bank Transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $byBank = $this->invoice($this->service($bank));

        $this->assertNull(AutoCharge::agreementFor($byVault));
        $this->assertNull(AutoCharge::agreementFor($byBank));
        $this->assertSame([], $this->cron(), 'the card charges neither');
        $this->assertNull(ItemPaymentMethod::chargedBy($this->service($this->vaultGateway)));
    }

    public function test_auto_charge_off_stops_the_second_method_too(): void
    {
        $byVault = $this->invoice($this->service($this->vaultGateway));
        $this->client->forceFill(['auto_charge' => false])->save();

        $this->assertNull(AutoCharge::agreementFor($byVault->fresh()));
        $this->assertSame([], $this->cron());
    }

    public function test_an_invoice_of_two_ways_is_charged_by_neither(): void
    {
        $merged = $this->invoice($this->service(null), $this->service($this->vaultGateway));

        $this->assertNull(AutoCharge::agreementFor($merged), 'the account\'s line is not the vault\'s to pay, the vault\'s not the card\'s');
        $this->assertSame([], $this->cron());
    }

    public function test_a_line_of_no_service_or_domain_does_not_change_its_way(): void
    {
        $byVault = $this->invoice($this->service($this->vaultGateway));
        $byVault->items()->create(['description' => 'Late fee', 'price' => '2.00', 'quantity' => 1]);

        $this->assertSame($this->vault->id, AutoCharge::agreementFor($byVault->fresh())?->id);
    }

    public function test_staff_stop_it_by_the_gateway_or_the_invoice(): void
    {
        $byVault = $this->invoice($this->service($this->vaultGateway));
        $other = $this->invoice($this->service($this->vaultGateway));
        $other->forceFill(['no_auto_charge' => true])->save();
        $this->assertNull(AutoCharge::agreementFor($other->fresh()), 'staff keep the invoice from it');

        $this->vaultGateway->forceFill(['enabled' => false])->save();
        $this->assertNull(AutoCharge::agreementFor($byVault->fresh()), 'a gateway staff disabled');
        $this->assertNull(ItemPaymentMethod::chargedBy($this->service($this->vaultGateway)));
        $this->assertSame([], $this->cron());
    }

    /** The default removed: nothing is charged, the PayPal items' vault included, until the customer chooses one (DATA-9, ADV-568-2). */
    public function test_without_a_default_nothing_is_charged(): void
    {
        $this->card->delete();
        $this->assertNull($this->client->fresh()->default_billing_agreement_id);
        $byAccount = $this->invoice($this->service(null));
        $byVault = $this->invoice($this->service($this->vaultGateway));

        $this->assertSame([], $this->cron());
        $this->assertNull(AutoCharge::agreementFor($byAccount->fresh()));
        $this->assertNull(AutoCharge::agreementFor($byVault->fresh()));
        $this->assertNull(ItemPaymentMethod::chargedBy($this->service($this->vaultGateway)));
    }

    public function test_declines_count_on_the_method_that_was_declined(): void
    {
        config(['settings.autocharge_max_failures' => 1]);
        GwVaultPay::$decline = true;
        $byVault = $this->invoice($this->service($this->vaultGateway));

        $this->cron();

        $this->assertSame(1, (int) $this->vault->fresh()->failed_count);
        $this->assertSame(0, (int) $this->card->fresh()->failed_count, 'the card was not charged, nor counted');
        $this->assertSame(ChargeAttempt::STATUS_FAILED, ChargeAttempt::query()->where('invoice_id', $byVault->id)->value('status'));
        $this->assertNull(AutoCharge::agreementFor($byVault->fresh()), 'stopped at its own failures');
        $byCard = $this->invoice($this->service(null));
        $this->assertSame($this->card->id, AutoCharge::agreementFor($byCard)?->id, 'the card goes on');
    }

    public function test_the_first_live_method_of_its_gateway_charges_it(): void
    {
        $second = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->vaultGateway->id,
            'name' => 'PayPal b@example.test', 'external_reference' => 'B-2', 'type' => 'paypal']);
        $byVault = $this->invoice($this->service($this->vaultGateway));

        $this->assertSame($this->vault->id, AutoCharge::agreementFor($byVault)?->id, 'the first, as WHMCS\'s order');
        $this->vault->delete();
        $this->assertSame($second->id, AutoCharge::agreementFor($byVault->fresh())?->id);
    }

    /** One stopped at autocharge_max_failures gives way to the next of its gateway (ADV-568-3). */
    public function test_a_stopped_method_gives_way_to_the_next_of_its_gateway(): void
    {
        config(['settings.autocharge_max_failures' => 3]);
        $this->vault->forceFill(['failed_count' => 3])->save();
        $next = BillingAgreement::query()->create(['client_id' => $this->client->id, 'gateway_id' => $this->vaultGateway->id,
            'name' => 'PayPal b@example.test', 'external_reference' => 'B-2', 'type' => 'paypal']);

        $this->assertSame($next->id, AutoCharge::agreementFor($this->invoice($this->service($this->vaultGateway)))?->id);
    }

    /** The reminder before the due date says it will be charged (it reads AutoCharge::agreementFor). */
    public function test_the_reminder_tells_the_customer_it_is_charged(): void
    {
        $byVault = $this->invoice($this->service($this->vaultGateway));
        $this->assertTrue(PaymentReminders::chargedOnItsOwn($byVault));
        $this->vault->delete();
        $this->assertFalse(PaymentReminders::chargedOnItsOwn($byVault->fresh()));
    }

    public function test_a_domain_of_its_gateway_and_the_service_page_name_the_method(): void
    {
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'payment_gateway_id' => $this->vaultGateway->id]);
        $this->assertSame($this->vault->id, ItemPaymentMethod::chargedBy($domain)?->id);
        $this->assertSame($this->vault->id, ItemPaymentMethod::chargedBy($this->service($this->vaultGateway))?->id);
        $this->assertSame($this->card->id, ItemPaymentMethod::chargedBy($this->service(null))?->id);
    }

    public function test_the_payment_methods_page_says_what_the_second_method_pays(): void
    {
        $this->service($this->vaultGateway);

        $this->livewireAs($this->user)->test(PaymentMethods::class)
            ->assertSee(__('account.default_payment_method'))
            ->assertSee(__('account.pays_own_way', ['gateway' => 'PayPal']));

        // Nothing paid with PayPal: nothing said.
        Service::query()->where('payment_gateway_id', $this->vaultGateway->id)->update(['status' => Service::STATUS_TERMINATED]);
        $this->livewireAs($this->user)->test(PaymentMethods::class)->assertDontSee(__('account.pays_own_way', ['gateway' => 'PayPal']));
    }
}
