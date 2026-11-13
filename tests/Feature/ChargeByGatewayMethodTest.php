<?php

namespace Tests\Feature;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Livewire\Client\PaymentMethods;
use App\Livewire\Domains\Show as DomainShow;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Invoice\PaymentReminders;
use App\Services\Payment\AutoCharge;
use App\Services\Payment\ItemPaymentMethod;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
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

    /** Its own «Do not charge automatically» (#568): its invoice, open one included, is left to the customer; the others are charged. */
    public function test_an_items_own_switch_keeps_its_invoice_from_the_cron(): void
    {
        config(['audit.console' => true]);
        $kept = $this->service(null);
        $byKept = $this->invoice($kept);
        $byCard = $this->invoice($this->service(null));
        ItemPaymentMethod::setAutoCharge($kept, false);

        $this->assertNull(AutoCharge::agreementFor($byKept->fresh()), 'its open invoice too');
        $this->assertNull(ItemPaymentMethod::chargedBy($kept->fresh()));
        $this->assertSame([$byCard->id => [$this->card->id]], $this->cron(), 'the account\'s others as before');

        ItemPaymentMethod::setAutoCharge($kept->fresh(), true);
        $this->assertSame($this->card->id, AutoCharge::agreementFor($byKept->fresh())?->id, 'on again: charged');
        $this->assertTrue($kept->audits()->where('event', 'updated')->exists(), 'audited');
    }

    /** A domain's own switch, and a line of it on an invoice with a service: the invoice is kept from it. */
    public function test_a_domains_own_switch_keeps_its_invoice_from_the_cron(): void
    {
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'no_auto_charge' => true]);
        $invoice = $this->invoice($this->service(null));
        $invoice->items()->create(['description' => 'Domain renewal', 'price' => '12.00', 'quantity' => 1, 'reference_type' => Domain::class, 'reference_id' => $domain->id]);

        $this->assertNull(AutoCharge::agreementFor($invoice->fresh()));
        $this->assertNull(ItemPaymentMethod::chargedBy($domain));
    }

    /** What its page says (owner 2026-11-01: no misunderstanding): charged with which method, or why not. */
    public function test_its_page_says_whether_it_is_charged_and_why_not(): void
    {
        $status = fn (string $key, array $with = []) => __("services.auto_charge_status.{$key}", $with);
        $bank = Gateway::query()->create(['name' => 'Bank Transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $mollie = Gateway::query()->create(['name' => 'Third', 'extension' => 'GwVaultPay', 'type' => 'gateway', 'enabled' => true]);

        $this->assertSame($status('charged', ['method' => 'Visa 4242']), ItemPaymentMethod::autoChargeStatus($this->service(null)));
        $this->assertSame($status('charged', ['method' => 'PayPal a@example.test']), ItemPaymentMethod::autoChargeStatus($this->service($this->vaultGateway)));
        $this->assertSame($status('manual', ['gateway' => 'Bank Transfer']), ItemPaymentMethod::autoChargeStatus($this->service($bank)));
        $this->assertSame($status('no_method', ['gateway' => 'Third']), ItemPaymentMethod::autoChargeStatus($this->service($mollie)));
        $kept = $this->service(null);
        $kept->forceFill(['no_auto_charge' => true])->save();
        $this->assertSame($status('off'), ItemPaymentMethod::autoChargeStatus($kept));
        $this->vaultGateway->forceFill(['enabled' => false])->save();
        $this->assertSame($status('gateway_off', ['gateway' => 'PayPal']), ItemPaymentMethod::autoChargeStatus($this->service($this->vaultGateway)));
        $this->client->refresh()->forceFill(['default_billing_agreement_id' => null])->save();
        $this->assertSame($status('no_default'), ItemPaymentMethod::autoChargeStatus($this->service(null)->fresh()));
        $this->client->forceFill(['auto_charge' => false])->save();
        $this->assertSame($status('account_off'), ItemPaymentMethod::autoChargeStatus($this->service(null)->fresh()));
    }

    public function test_the_customer_turns_it_off_and_on_on_the_service_and_domain_pages(): void
    {
        $service = $this->service(null);
        $page = $this->livewireAs($this->user)->test(ServiceShow::class, ['service' => $service])
            ->assertSee(__('services.auto_charge_status.charged', ['method' => 'Visa 4242']));
        $page->call('toggleAutoCharge');
        $this->assertTrue($service->fresh()->no_auto_charge);
        $page->call('toggleAutoCharge');
        $this->assertFalse($service->fresh()->no_auto_charge);

        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'USD']);
        $this->livewireAs($this->user)->test(DomainShow::class, ['domain' => $domain])->call('toggleAutoCharge');
        $this->assertTrue($domain->fresh()->no_auto_charge);
    }

    public function test_a_login_that_does_not_pay_the_accounts_invoices_may_not_turn_it(): void
    {
        $service = $this->service(null);
        $login = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $login->id, 'permissions' => ['manageproducts'], 'granted_at' => now()]);

        $this->livewireAs($login)->test(ServiceShow::class, ['service' => $service])->call('toggleAutoCharge')->assertForbidden();
        $this->assertFalse($service->fresh()->no_auto_charge);
    }

    public function test_staff_set_it_on_the_service_form(): void
    {
        $service = $this->service(null);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        $this->livewireAs($admin)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->assertSee(__('services.auto_charge_status.charged', ['method' => 'Visa 4242']))
            ->fillForm(['no_auto_charge' => true])->call('save')->assertHasNoFormErrors();

        $this->assertTrue($service->fresh()->no_auto_charge);
    }

    /** @return list<int> the open invoice each service's renewal went on, billed by the cron's RenewalBilling */
    private function bill(Service ...$services): array
    {
        $billing = new RenewalBilling;
        foreach ($services as $service) {
            $this->assertSame(1, DB::transaction(fn () => $billing->billRenewal($service->id, now()->addDays(7))), "service #{$service->id} billed");
        }
        $billing->finishRenewalInvoices();

        return array_map(fn (Service $service) => (int) $service->invoices()->where('status', Invoice::STATUS_UNPAID)->value('invoices.id'), $services);
    }

    /** Review 2 of #568: one turned off goes on an invoice apart, so the others of its day are still charged. */
    public function test_a_switched_off_service_is_billed_apart_and_the_others_are_charged(): void
    {
        $kept = $this->service(null);
        $kept->forceFill(['no_auto_charge' => true, 'expires_at' => '2026-11-05'])->save();
        $other = $this->service(null);
        $other->forceFill(['expires_at' => '2026-11-05'])->save();

        [$a, $b] = $this->bill($kept, $other);

        $this->assertNotSame($a, $b);
        $this->assertNull(AutoCharge::agreementFor(Invoice::query()->find($a)));
        $this->assertSame($this->card->id, AutoCharge::agreementFor(Invoice::query()->find($b))?->id);
    }

    /** Turned off on an open invoice it shares: its lines leave it (a split), the others' is still charged. */
    public function test_turned_off_it_leaves_a_shared_open_invoice(): void
    {
        $kept = $this->service(null);
        $other = $this->service(null);
        $shared = $this->invoice($kept, $other);

        ItemPaymentMethod::setAutoCharge($kept, false);

        $this->assertSame([$other->id], $shared->items()->pluck('reference_id')->map(fn ($id) => (int) $id)->all(), 'only the other stays');
        $apart = Invoice::query()->where('split_from_id', $shared->id)->sole();
        $this->assertSame([$kept->id], $apart->items()->pluck('reference_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([$shared->id => [$this->card->id]], $this->cron(), 'the other\'s charged, the kept one not');
    }

    /** One that cannot be split (money on it) stays whole and held: the other's page says so. */
    public function test_a_shared_open_invoice_that_cannot_be_split_is_held_and_the_other_is_told(): void
    {
        $kept = $this->service(null);
        $other = $this->service(null);
        $shared = $this->invoice($kept, $other);
        ExtensionHelper::addPayment($shared->id, null, '1.00', null, 'part-1', currency: 'USD');

        ItemPaymentMethod::setAutoCharge($kept, false);

        $this->assertSame(2, $shared->items()->count(), 'not split: a payment is on it');
        $this->assertNull(AutoCharge::agreementFor($shared->fresh()), 'held: never charged against the customer\'s choice');
        $this->assertSame(__('services.auto_charge_status.held', ['invoice' => $shared->id]), ItemPaymentMethod::autoChargeStatus($other->fresh()));
    }

    /** The line follows the cron's rules (review 2 of #568). */
    public function test_its_page_says_no_where_the_cron_would_not_charge(): void
    {
        $status = fn (string $key, array $with = []) => __("services.auto_charge_status.{$key}", $with);
        $suspended = $this->service(null);
        $suspended->forceFill(['status' => Service::STATUS_SUSPENDED])->save();
        $this->assertSame($status('not_active'), ItemPaymentMethod::autoChargeStatus($suspended));

        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'auto_renew' => false]);
        $this->assertSame($status('not_renewing'), ItemPaymentMethod::autoChargeStatus($domain));

        config(['settings.autocharge_max_failures' => 3]);
        $this->card->forceFill(['failed_count' => 3])->save();
        $byCard = $this->invoice($this->service(null));
        $this->assertNull(AutoCharge::agreementFor($byCard));
        $this->assertSame($status('stopped', ['method' => 'Visa 4242']), ItemPaymentMethod::autoChargeStatus($this->service(null)));
    }

    /** Staff in «Login as client» turn it on (money taken) only with the money permission; off, they may (SA-5). */
    public function test_staff_logged_in_as_the_client_turn_it_on_only_with_the_money_permission(): void
    {
        $service = $this->service(null);
        $service->forceFill(['no_auto_charge' => true])->save();
        session(['impersonating' => $this->user->id, CurrentClient::SESSION_KEY => $this->client->id, CurrentClient::IMPERSONATING_KEY => $this->client->id]);
        Auth::guard('staff')->setUser(Staff::factory()->withPermissions(['admin.users.impersonate', 'admin.services.view', 'admin.services.update'])->create());
        Auth::guard('web')->setUser($this->user);
        app(CurrentClient::class)->forget();

        Livewire::test(ServiceShow::class, ['service' => $service])->call('toggleAutoCharge');
        $this->assertTrue($service->fresh()->no_auto_charge, 'not turned on without the money permission');

        $service->forceFill(['no_auto_charge' => false])->save();
        Livewire::test(ServiceShow::class, ['service' => $service->fresh()])->call('toggleAutoCharge');
        $this->assertTrue($service->fresh()->no_auto_charge, 'turned off: no money taken');
    }

    public function test_staff_set_it_on_the_domains_edit(): void
    {
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'USD']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        $this->livewireAs($admin)->test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->saveBilling(['no_auto_charge' => true, 'auto_renew' => true]);

        $this->assertTrue($domain->fresh()->no_auto_charge);
    }

    /** Owner 2026-11-01: a change of its payment method moves its open invoice too; one shared with the account's is split. */
    public function test_a_change_of_its_way_moves_its_open_invoice_apart(): void
    {
        $moving = $this->service(null);
        $staying = $this->service(null);
        $shared = $this->invoice($moving, $staying);

        ItemPaymentMethod::set($moving, $this->vaultGateway->id);

        $apart = Invoice::query()->where('split_from_id', $shared->id)->sole();
        $this->assertSame([$moving->id], $apart->items()->pluck('reference_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame($this->vaultGateway->id, (int) $apart->preferred_gateway_id);
        $this->assertSame($this->vault->id, AutoCharge::agreementFor($apart)?->id, 'the PayPal vault, as the customer chose');
        $this->assertSame($this->card->id, AutoCharge::agreementFor($shared->fresh())?->id, 'the other stays the card\'s');
    }

    /** Never while a charge of it is on its way: another method is never sent a second charge of the same invoice. */
    public function test_an_open_invoice_with_a_charge_on_its_way_keeps_its_way(): void
    {
        $service = $this->service(null);
        $open = $this->invoice($service);
        ChargeAttempt::query()->forceCreate(['invoice_id' => $open->id, 'client_id' => $this->client->id, 'billing_agreement_id' => $this->card->id, 'source' => ChargeAttempt::SOURCE_CRON,
            'status' => ChargeAttempt::STATUS_UNKNOWN, 'amount' => '10.00', 'currency_code' => 'USD', 'key' => (string) Str::uuid(), 'started_at' => now(), 'sent_at' => now()]);

        ItemPaymentMethod::set($service, $this->vaultGateway->id);

        $this->assertNull($open->items()->value('payment_gateway_id'), 'still the account\'s, as the charge was sent');
        $this->assertSame($this->vaultGateway->id, (int) $service->fresh()->payment_gateway_id, 'the next renewal is PayPal\'s');
    }

    public function test_a_domains_open_invoice_follows_its_way(): void
    {
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'USD']);
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-01']);
        $invoice->items()->create(['description' => 'Domain renewal', 'price' => '12.00', 'quantity' => 1, 'reference_type' => Domain::class, 'reference_id' => $domain->id]);

        ItemPaymentMethod::set($domain, $this->vaultGateway->id);

        $this->assertSame($this->vaultGateway->id, (int) $invoice->items()->value('payment_gateway_id'));
        $this->assertSame($this->vault->id, AutoCharge::agreementFor($invoice->fresh())?->id);
    }

    /** Each way in its select says whether a saved method charges it or it is paid from the invoice (owner 2026-11-01). */
    public function test_each_payment_way_says_whether_it_is_charged_automatically(): void
    {
        $bank = Gateway::query()->create(['name' => 'Bank Transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $service = $this->service(null);
        $auto = fn (string $gateway, string $method) => __('services.payment_method_option_auto', ['gateway' => $gateway, 'method' => $method]);
        $manual = fn (string $gateway) => __('services.payment_method_option_manual', ['gateway' => $gateway]);

        $this->assertSame($auto(__('services.payment_method_account_short'), 'Visa 4242'), ItemPaymentMethod::optionLabel($service, null));
        $this->assertSame($auto('PayPal', 'PayPal a@example.test'), ItemPaymentMethod::optionLabel($service, $this->vaultGateway), 'the PayPal vault: automatic');
        $this->assertSame($manual('Bank Transfer'), ItemPaymentMethod::optionLabel($service, $bank));

        $this->vault->delete();
        $this->assertSame($manual('PayPal'), ItemPaymentMethod::optionLabel($service, $this->vaultGateway), 'no saved PayPal: paid from the invoice');
        $service->forceFill(['no_auto_charge' => true])->save();
        $this->assertSame($manual(__('services.payment_method_account_short')), ItemPaymentMethod::optionLabel($service->fresh(), null), 'its own switch off');

        $this->livewireAs($this->user)->test(ServiceShow::class, ['service' => $this->service(null)])
            ->assertSee($auto(__('services.payment_method_account_short'), 'Visa 4242'))->assertSee($manual('PayPal'));
    }

    /** As the cron (review of #572): a stopped method, a gateway not enabled, «Auto-charge» off; the domain page too. */
    public function test_the_labels_follow_the_cron_for_stopped_methods_and_switches(): void
    {
        $auto = fn (string $gateway, string $method) => __('services.payment_method_option_auto', ['gateway' => $gateway, 'method' => $method]);
        $manual = fn (string $gateway) => __('services.payment_method_option_manual', ['gateway' => $gateway]);
        $account = __('services.payment_method_account_short');
        $service = $this->service($this->vaultGateway);

        config(['settings.autocharge_max_failures' => 3]);
        $this->vault->forceFill(['failed_count' => 3])->save();
        $this->card->forceFill(['failed_count' => 3])->save();
        $this->assertNull(AutoCharge::agreementFor($this->invoice($service)), 'precondition: the cron charges neither');
        $this->assertSame($manual('PayPal'), ItemPaymentMethod::optionLabel($service, $this->vaultGateway), 'every PayPal method stopped');
        $this->assertSame($manual($account), ItemPaymentMethod::optionLabel($service, null), 'the default stopped');
        $this->card->forceFill(['failed_count' => 0])->save();
        $this->assertSame($auto($account, 'Visa 4242'), ItemPaymentMethod::optionLabel($service->fresh(), null));

        $this->vaultGateway->forceFill(['enabled' => false])->save();
        $this->assertSame(__('services.payment_method_option_unavailable', ['gateway' => 'PayPal']), ItemPaymentMethod::optionLabel($service->fresh(), $this->vaultGateway->fresh()));

        $this->client->forceFill(['auto_charge' => false])->save();
        $this->assertSame($manual($account), ItemPaymentMethod::optionLabel($service->fresh(), null), 'the account\'s «Auto-charge» off');

        $this->client->forceFill(['auto_charge' => true])->save();
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'USD']);
        $this->livewireAs($this->user)->test(DomainShow::class, ['domain' => $domain])->assertSee($auto($account, 'Visa 4242'));
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
