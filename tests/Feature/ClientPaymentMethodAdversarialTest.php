<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Livewire\Client\PaymentMethods;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\C2AdvNoVault\C2AdvNoVault;
use MyCS\Extensions\Gateways\C2AdvPay\C2AdvPay;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * Adversarial review of PR C2 (decision 6: the saved payment method that pays renewals is the account's). `test_bug_*`
 * fail on the branch as it is (a real issue); `test_holds_*` pin a guarantee that was attacked and held.
 */
class ClientPaymentMethodAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']]);
        config(['settings.cronjob_invoice' => 7]);
        if (!class_exists(C2AdvPay::class)) {
            eval('namespace MyCS\Extensions\Gateways\C2AdvPay; class C2AdvPay { public static array $charges = []; public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) { self::$charges[] = [(int) $invoice->id, (int) $agreement->id, number_format((float) $amount, 2, ".", "")]; return true; } }');
        }
        if (!class_exists(C2AdvNoVault::class)) {
            // A gateway that no longer offers saved methods (e.g. PayPal with «billing agreements» switched off).
            eval('namespace MyCS\Extensions\Gateways\C2AdvNoVault; class C2AdvNoVault { public static array $charges = []; public function __construct($config = []) {}
                public function supportsBillingAgreements() { return false; }
                public function charge($invoice, $amount, $agreement) { self::$charges[] = [(int) $invoice->id, (int) $agreement->id, number_format((float) $amount, 2, ".", "")]; return true; } }');
        }
        C2AdvPay::$charges = [];
        C2AdvNoVault::$charges = [];
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    private function client(): Client
    {
        return $this->user->client()->fresh();
    }

    private function agreementOf(Client $client, string $extension = 'C2AdvPay'): BillingAgreement
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => $extension], ['name' => $extension, 'type' => 'gateway']);

        return BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Card ' . uniqid(),
            'external_reference' => 'ref-' . uniqid('', true)]);
    }

    private function service(array $attributes = [], ?Client $client = null): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => ($client ?? $this->client())->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25',
        ]);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /** @return Collection<int, Invoice> */
    private function renewalInvoices(): Collection
    {
        return Invoice::query()->whereHas('items', fn ($q) => $q->where('reference_type', Service::class))->orderBy('id')->get();
    }

    private function workOn(User $user, Client $client): void
    {
        $this->actingAs($user);
        $this->assertTrue(app(CurrentClient::class)->switchTo($client->id), 'precondition: the user sees that account');
        $this->assertSame($client->id, CurrentClient::id());
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'C2Adv' . uniqid(), 'permissions' => ['*']])->id]);
    }

    // ------------------------------------------------------------------------------------------------ the cron

    public function test_holds_a_grouped_renewal_is_charged_once_across_two_runs(): void
    {
        $card = $this->agreementOf($this->client());
        $this->service();
        $this->service();

        $this->cron();
        $this->cron(); // the same day again: nothing left to finish

        $invoice = $this->renewalInvoices()->sole();
        $this->assertSame([[(int) $invoice->id, $card->id, '20.00']], C2AdvPay::$charges);
    }

    public function test_holds_a_subscription_renewal_next_to_a_regular_one_is_not_charged(): void
    {
        $card = $this->agreementOf($this->client());
        $this->service(['subscription_id' => 'sub_c2adv']);
        $regular = $this->service();

        $this->cron();

        $this->assertCount(2, $this->renewalInvoices());
        $charged = $this->renewalInvoices()->first(fn (Invoice $invoice) => $invoice->items()->where('reference_id', $regular->id)->exists());
        $this->assertSame([[(int) $charged->id, $card->id, '10.00']], C2AdvPay::$charges, 'only the regular renewal is charged');
    }

    public function test_holds_separate_invoices_are_each_charged_once(): void
    {
        $this->client()->update(['separate_invoices' => true]);
        $this->agreementOf($this->client());
        $this->service();
        $this->service();

        $this->cron();
        $this->cron();

        $this->assertCount(2, $this->renewalInvoices());
        $this->assertCount(2, C2AdvPay::$charges);
        $this->assertSame($this->renewalInvoices()->pluck('id')->map(fn ($id) => (int) $id)->all(), array_column(C2AdvPay::$charges, 0));
    }

    public function test_holds_a_default_pointing_to_another_accounts_card_is_never_charged(): void
    {
        $other = User::factory()->create()->client();
        $foreign = $this->agreementOf($other);
        $this->agreementOf($this->client());
        // Behind the observer's back (a bad import, a hand-edited row): the account names B's card.
        DB::table('clients')->where('id', $this->client()->id)->update(['default_billing_agreement_id' => $foreign->id]);
        $this->service();

        $this->cron();

        $this->assertSame([], C2AdvPay::$charges);
        $this->assertSame(Invoice::STATUS_UNPAID, $this->renewalInvoices()->sole()->status);
    }

    public function test_holds_a_default_removed_behind_the_models_back_is_not_charged(): void
    {
        $card = $this->agreementOf($this->client());
        DB::table('billing_agreements')->where('id', $card->id)->update(['deleted_at' => now()]);
        $this->assertSame($card->id, (int) $this->client()->default_billing_agreement_id, 'precondition: still named');
        $this->service();

        $this->cron();

        $this->assertSame([], C2AdvPay::$charges);
    }

    public function test_holds_a_hard_deleted_default_or_deleted_gateway_is_not_charged(): void
    {
        $card = $this->agreementOf($this->client());
        $this->service();
        // The gateway is deleted: its saved methods go by cascade (no model events, no observer).
        DB::table('extensions')->where('id', $card->gateway_id)->delete();
        DB::table('billing_agreements')->where('id', $card->id)->delete(); // and, where no cascade ran, the method itself

        $this->cron();

        $this->assertSame([], C2AdvPay::$charges);
        $this->assertNull($this->client()->autoChargeAgreement());
    }

    public function test_holds_auto_charge_switched_off_on_payment_methods_stops_the_cron(): void
    {
        $this->agreementOf($this->client());
        $this->service();
        $this->workOn($this->user, $this->client());

        $this->livewireAs($this->user)->test(PaymentMethods::class)->set('autoCharge', false);
        $this->cron();

        $this->assertFalse($this->client()->auto_charge);
        $this->assertSame([], C2AdvPay::$charges);
    }

    public function test_holds_removing_the_default_does_not_charge_the_other_card(): void
    {
        $default = $this->agreementOf($this->client());
        $other = $this->agreementOf($this->client());
        $this->service();
        $this->workOn($this->user, $this->client());

        // The customer removes the default from «Payment methods» (the gateway agrees).
        $this->livewireAs($this->user)->test(PaymentMethods::class)->call('removePaymentMethod', $default->ulid);
        $this->assertSoftDeleted($default);
        $this->cron();

        $this->assertNull($this->client()->default_billing_agreement_id);
        $this->assertNotContains($other->id, array_column(C2AdvPay::$charges, 1), 'a card the customer did not choose is not charged');
        $this->assertSame([], C2AdvPay::$charges);
    }

    // ------------------------------------------------------------------------------------------- client area

    public function test_holds_make_default_refuses_a_removed_card_of_the_same_account(): void
    {
        $first = $this->agreementOf($this->client());
        $removed = $this->agreementOf($this->client());
        $removed->delete();
        $this->workOn($this->user, $this->client());

        try {
            $this->livewireAs($this->user)->test(PaymentMethods::class)->call('makeDefault', $removed->ulid);
        } catch (\Throwable) {
        }

        $this->assertSame($first->id, (int) $this->client()->default_billing_agreement_id);
    }

    public function test_holds_make_default_by_direct_call_with_a_granted_accounts_card_is_refused(): void
    {
        // U owns A and has a full grant on B; working on A, U names B's card by its ulid in a direct Livewire call.
        $b = User::factory()->create()->client();
        ClientUser::query()->create(['client_id' => $b->id, 'user_id' => $this->user->id, 'granted_at' => now()]);
        $ownCard = $this->agreementOf($this->client());
        $cardOfB = $this->agreementOf($b);
        $this->workOn($this->user, $this->client());

        try {
            $this->livewireAs($this->user)->test(PaymentMethods::class)->call('makeDefault', $cardOfB->ulid);
        } catch (\Throwable) {
        }

        $this->assertSame($ownCard->id, (int) $this->client()->default_billing_agreement_id);
        $this->assertSame($cardOfB->id, (int) $b->fresh()->default_billing_agreement_id);
    }

    /**
     * «Use for recurring» while paying: the page refuses the payment (the card's gateway no longer offers saved methods),
     * yet the card has already become the account's renewal method and «Auto-charge» was forced back on. The cron then
     * charges, unasked, a method the invoice page itself said cannot be used.
     */
    public function test_bug_set_as_default_on_a_refused_payment_still_makes_it_the_renewal_method(): void
    {
        $usable = $this->agreementOf($this->client());
        $unusable = $this->agreementOf($this->client(), 'C2AdvNoVault');
        $this->client()->update(['auto_charge' => false]); // the owner opted out
        $service = $this->service();
        $invoice = Invoice::factory()->create(['client_id' => $this->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'price' => 10, 'quantity' => 1, 'description' => 'x']);
        $this->workOn($this->user, $this->client());

        try {
            $this->livewireAs($this->user)->test(InvoiceShow::class, ['invoice' => $invoice->fresh()])
                ->set('selectedMethod', $unusable->ulid)->set('setAsDefault', true)->call('processPayment');
        } catch (\Throwable) {
        }

        $this->assertSame([], C2AdvNoVault::$charges, 'precondition: the page refused to charge it');
        $client = $this->client();
        $this->assertSame($usable->id, (int) $client->default_billing_agreement_id,
            'a method the page refused to charge must not become the account\'s renewal method');
        $this->assertFalse($client->auto_charge, 'a refused payment must not turn «Auto-charge» back on');
    }

    // ---------------------------------------------------------------------------------------------- admin form

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_holds_the_admin_form_does_not_save_another_accounts_card(): void
    {
        $own = $this->agreementOf($this->client());
        $foreign = $this->agreementOf(User::factory()->create()->client());
        $staff = $this->staff();

        try {
            $this->livewireAs($staff)->test(EditClient::class, ['record' => $this->client()->getRouteKey()])
                ->fillForm(['default_billing_agreement_id' => $foreign->id])->call('save');
        } catch (\Throwable) {
        }

        $this->assertSame($own->id, (int) $this->client()->default_billing_agreement_id);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_holds_the_admin_form_switches_auto_charge_off_and_picks_another_own_card(): void
    {
        $this->agreementOf($this->client());
        $second = $this->agreementOf($this->client());
        $staff = $this->staff();

        $this->livewireAs($staff)->test(EditClient::class, ['record' => $this->client()->getRouteKey()])
            ->assertFormSet(['auto_charge' => true])
            ->fillForm(['auto_charge' => false, 'default_billing_agreement_id' => $second->id])->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($this->client()->auto_charge);
        $this->assertSame($second->id, (int) $this->client()->default_billing_agreement_id);
    }

    // -------------------------------------------------------------------------------------------- change cycle

    private function cycleService(): array
    {
        Carbon::setTestNow('2026-07-02 10:00:00');
        $product = Product::factory()->create(['name' => 'Hosting']);
        $plans = [];
        foreach (['year' => 120.00, 'month' => 12.00] as $unit => $price) {
            $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => $unit,
                'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
            Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);
            $plans[$unit] = $plan->fresh();
        }
        $service = Service::factory()->create(['client_id' => $this->client()->id, 'product_id' => $product->id, 'plan_id' => $plans['year']->id,
            'price' => 120.00, 'quantity' => 1, 'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01']);
        $paid = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $paid->items()->create(['description' => 'Renewal', 'price' => 120.00, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => '2026-01-01', 'period_end' => '2027-01-01']);
        DB::table('invoices')->where('id', $paid->id)->update(['status' => Invoice::STATUS_PAID]);

        return [$service->fresh(), $plans['month']];
    }

    public function test_holds_change_cycle_now_does_not_charge_with_auto_charge_off(): void
    {
        [$service, $monthly] = $this->cycleService();
        $this->agreementOf($this->client());
        $this->client()->update(['auto_charge' => false]);

        $invoice = ChangeCycle::apply($service, $monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertNotNull($invoice);
        $this->assertSame([], C2AdvPay::$charges);
    }

    public function test_holds_change_cycle_now_then_the_cron_charges_once(): void
    {
        [$service, $monthly] = $this->cycleService();
        $card = $this->agreementOf($this->client());

        $invoice = ChangeCycle::apply($service, $monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        $this->cron();

        $this->assertSame([[(int) $invoice->id, $card->id, '12.00']], C2AdvPay::$charges);
    }

    // ----------------------------------------------------------------------------------------------- migration

    private function rollback(): void
    {
        // Back to before C2's migration, whatever came after it.
        $steps = DB::table('migrations')->where('migration', '>=', '2026_11_08_000000')->count();
        $this->artisan('migrate:rollback', ['--step' => $steps])->assertExitCode(0);
    }

    private function forward(): void
    {
        $this->artisan('migrate')->assertExitCode(0);
    }

    public function test_holds_the_migration_on_ties_foreign_removed_and_cancelled(): void
    {
        $tie = $this->client();
        $mixed = User::factory()->create()->client();
        $onlyCancelled = User::factory()->create()->client();
        $other = User::factory()->create()->client();
        $x = $this->agreementOf($tie);
        $y = $this->agreementOf($tie); // newer
        $removed = $this->agreementOf($mixed);
        $own = $this->agreementOf($mixed);
        $foreign = $this->agreementOf($other);
        $cancelledCard = $this->agreementOf($onlyCancelled);
        $s = [
            $this->service([], $tie), $this->service([], $tie),
            $this->service([], $mixed), $this->service([], $mixed), $this->service([], $mixed), $this->service([], $mixed), $this->service([], $mixed),
            $this->service(['status' => Service::STATUS_CANCELLED], $onlyCancelled),
            $this->service(['status' => Service::STATUS_SUSPENDED], $other),
        ];

        $this->rollback();
        $set = fn (Service $service, BillingAgreement $card) => DB::table('services')->where('id', $service->id)->update(['billing_agreement_id' => $card->id]);
        $set($s[0], $x);
        $set($s[1], $y);
        // $mixed: two on a removed card, two on another account's card (a bad row), one on its own.
        $set($s[2], $removed);
        $set($s[3], $removed);
        $set($s[4], $foreign);
        $set($s[5], $foreign);
        $set($s[6], $own);
        $set($s[7], $cancelledCard);
        $set($s[8], $foreign);
        DB::table('billing_agreements')->where('id', $removed->id)->update(['deleted_at' => now()]);
        $this->forward();

        $this->assertSame($y->id, (int) $tie->fresh()->default_billing_agreement_id, 'a tie takes the newest');
        $this->assertSame($own->id, (int) $mixed->fresh()->default_billing_agreement_id, 'neither a removed nor a foreign card');
        $this->assertNull($onlyCancelled->fresh()->default_billing_agreement_id, 'a cancelled service gives none');
        $this->assertSame($foreign->id, (int) $other->fresh()->default_billing_agreement_id);
        $this->assertSame($own->id, $mixed->fresh()->autoChargeAgreement()?->id);
    }

    public function test_holds_a_rollback_and_forward_never_charges_an_account_that_opted_out(): void
    {
        $card = $this->agreementOf($this->client());
        $this->client()->update(['auto_charge' => false]);
        $this->service();

        $this->rollback();
        $this->forward();
        $this->cron();

        $this->assertNull($this->client()->autoChargeAgreement());
        $this->assertNotContains($card->id, array_column(C2AdvPay::$charges, 1));
    }

    /**
     * A rollback and forward forgets «Auto-charge» off (the column is dropped and comes back as true) and its default
     * with it; the first card the customer adds afterwards becomes the default (BillingAgreement::created) and the renewals
     * of an account that had opted out are charged again.
     */
    public function test_bug_a_rollback_and_forward_turns_auto_charge_back_on(): void
    {
        $this->agreementOf($this->client());
        $this->client()->update(['auto_charge' => false]);

        $this->rollback();
        $this->forward();
        $later = $this->agreementOf($this->client()); // the customer adds a card afterwards

        $this->assertNotSame($later->id, $this->client()->autoChargeAgreement()?->id,
            'an account that switched «Auto-charge» off is not charged after a migration round trip');
    }
}
