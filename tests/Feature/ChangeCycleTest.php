<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Exceptions\DisplayException;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\BillingAgreement;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\CycleFakePay\CycleFakePay;
use Tests\TestCase;

/**
 * SVC-14b: a service changes its billing cycle to another plan of its product, from the next renewal (staff and
 * customer: nothing charged now) or now (staff: the unused days become credit, a new period starts today, billed).
 */
class ChangeCycleTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Plan $yearly;

    private Plan $monthly;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-02 10:00:00');
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']]);

        $this->product = Product::factory()->create(['name' => 'Hosting']);
        $this->yearly = $this->plan('Yearly', 'year', 120.00);
        $this->monthly = $this->plan('Monthly', 'month', 12.00);
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

    private function plan(string $name, string $unit, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $this->product->id, 'priceable_type' => Product::class, 'name' => $name,
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    /** A yearly service paid for 2026-01-01 → 2027-01-01. */
    private function service(array $attributes = []): Service
    {
        $service = Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id,
            'product_id' => $this->product->id, 'plan_id' => $this->yearly->id, 'price' => 120.00, 'quantity' => 1,
            'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => '2027-01-01']);
        $this->periodLine($service, '2026-01-01', '2027-01-01', 120.00, Invoice::STATUS_PAID);

        return $service->fresh();
    }

    private function periodLine(Service $service, string $start, string $end, float $price, string $status): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $line = $invoice->items()->create(['description' => 'Renewal', 'price' => $price, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        if ($status !== Invoice::STATUS_UNPAID) {
            // As the importer writes it (the observer lets only a recorded payment mark one paid).
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status]);
        }

        return $line;
    }

    private function admin(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Cycle' . uniqid(), 'permissions' => ['*']])->id]);
    }

    // ------------------------------------------------------------------ from the next renewal

    public function test_from_the_next_renewal_nothing_moves_now(): void
    {
        $service = $this->service();

        $this->assertNull(ChangeCycle::apply($service, $this->monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF));

        $service->refresh();
        // Set to come: the period paid keeps its plan and price (what an upgrade or a credit is priced on: code review B3).
        $this->assertSame($this->monthly->id, (int) $service->next_plan_id);
        $this->assertSame($this->yearly->id, (int) $service->plan_id);
        $this->assertSame('120.00', number_format((float) $service->price, 2, '.', ''));
        $this->assertSame('2027-01-01', $service->expires_at->toDateString(), 'the period paid runs to its end');
        $this->assertSame(0, Credit::query()->count());
        $this->assertSame(1, Invoice::query()->count(), 'nothing billed');
    }

    public function test_the_cron_bills_the_next_period_in_the_new_cycle(): void
    {
        $this->settings(['cronjob_invoice' => [7, 'integer']]);
        $service = $this->service();
        $open = $this->periodLine($service, '2027-01-01', '2028-01-01', 120.00, Invoice::STATUS_UNPAID);

        ChangeCycle::apply($service, $this->monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);
        $this->assertSame(Invoice::STATUS_CANCELLED, $open->invoice->fresh()->status, 'the renewal of the old cycle goes');

        Carbon::setTestNow('2026-12-28 10:00:00');
        $this->artisan('app:cron-job');

        $line = InvoiceItem::query()->where('reference_id', $service->id)->whereDate('period_start', '2027-01-01')
            ->whereHas('invoice', fn ($query) => $query->where('status', Invoice::STATUS_UNPAID))->sole();
        $this->assertSame('2027-02-01', $line->period_end->toDateString());
        $this->assertSame('12.00', number_format((float) $line->price, 2, '.', ''));
        $service->refresh();
        $this->assertSame([$this->monthly->id, null], [(int) $service->plan_id, $service->next_plan_id]);
        $this->assertSame('12.00', number_format((float) $service->price, 2, '.', ''));
    }

    public function test_its_own_plan_takes_the_change_back(): void
    {
        $service = $this->service(['price' => 150.00]);
        ChangeCycle::apply($service, $this->monthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);

        ChangeCycle::apply($service->fresh(), $this->yearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);

        $service->refresh();
        $this->assertNull($service->next_plan_id);
        $this->assertSame('150.00', number_format((float) $service->price, 2, '.', ''), 'a staff price stays');
    }

    public function test_the_customer_changes_it_from_the_next_renewal(): void
    {
        $service = $this->service();
        $user = $service->client->users()->first();

        $this->livewireAs($user)->test(ServiceShow::class, ['service' => $service])
            ->set('cyclePlan', $this->monthly->id)->call('changeCycle');

        $this->assertSame($this->monthly->id, (int) $service->fresh()->next_plan_id);
        $this->assertSame('2027-01-01', $service->fresh()->expires_at->toDateString());
    }

    public function test_the_customer_cannot_pick_another_products_plan(): void
    {
        $service = $this->service();
        $other = Product::factory()->create();
        $foreign = Plan::factory()->create(['priceable_id' => $other->id, 'priceable_type' => Product::class, 'billing_unit' => 'month',
            'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $foreign->id, 'price' => 1, 'setup_fee' => 0, 'currency_code' => 'USD']);

        $this->livewireAs($service->client->users()->first())->test(ServiceShow::class, ['service' => $service])
            ->set('cyclePlan', $foreign->id)->call('changeCycle');

        $this->assertNull($service->fresh()->next_plan_id);
    }

    // ------------------------------------------------------------------ refused

    public function test_it_is_refused_while_a_subscription_an_upgrade_or_a_paid_open_invoice_is_there(): void
    {
        $service = $this->service(['subscription_id' => 'sub_1']);
        $this->assertNotNull(ChangeCycle::refusal($service, $this->monthly, ChangeCycle::NEXT_RENEWAL));

        $service->update(['subscription_id' => null]);
        ServiceUpgrade::create(['service_id' => $service->id, 'product_id' => $this->product->id, 'plan_id' => $this->yearly->id]);
        $this->assertNotNull(ChangeCycle::refusal($service->fresh(), $this->monthly, ChangeCycle::NEXT_RENEWAL));

        ServiceUpgrade::query()->delete();
        $line = $this->periodLine($service, '2027-01-01', '2028-01-01', 120.00, Invoice::STATUS_UNPAID);
        $line->invoice->transactions()->create(['amount' => 10, 'status' => 'succeeded', 'transaction_id' => 'part', 'fee' => 0]);
        $this->assertNotNull(ChangeCycle::refusal($service->fresh(), $this->monthly, ChangeCycle::NEXT_RENEWAL));

        $this->expectException(DisplayException::class);
        ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
    }

    // ------------------------------------------------------------------ now

    public function test_now_credits_the_unused_days_and_bills_a_new_period_from_today(): void
    {
        $service = $this->service();
        $open = $this->periodLine($service, '2027-01-01', '2028-01-01', 120.00, Invoice::STATUS_UNPAID);

        $invoice = ChangeCycle::apply($service, $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        // 120.00 × 183 / 365 of the year paid is not used.
        $this->assertSame('60.16', number_format((float) Credit::query()->sole()->amount, 2, '.', ''));
        $service->refresh();
        $this->assertSame('2026-07-02', $service->expires_at->toDateString(), 'the old period ends today');
        $this->assertSame($this->monthly->id, (int) $service->plan_id);
        $this->assertSame(Invoice::STATUS_CANCELLED, $open->invoice->fresh()->status, 'the renewal of the old cycle goes');
        $paid = InvoiceItem::query()->whereDate('period_start', '2026-01-01')->sole();
        $this->assertSame('2026-07-02', $paid->period_end->toDateString(), 'the year paid ends today: the rest is the credit');
        $this->assertSame('120.00', number_format((float) $paid->price, 2, '.', ''), 'what was paid stays');

        $line = $invoice->items()->sole();
        $this->assertSame(['2026-07-02', '2026-08-02'], [$line->period_start->toDateString(), $line->period_end->toDateString()]);
        $this->assertSame('12.00', number_format((float) $line->price, 2, '.', ''));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status, 'credit pays it only with «Automatically use credits»');
    }

    public function test_now_the_credit_pays_the_new_period_when_credits_are_used_automatically(): void
    {
        $this->settings(['credits_auto_use' => [true, 'boolean']]);
        $service = $this->service();

        $invoice = ChangeCycle::apply($service, $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame('48.16', number_format((float) Credit::query()->sole()->amount, 2, '.', ''), '60.16 − 12.00');
        $this->assertSame('2026-08-02', $service->fresh()->expires_at->toDateString());
    }

    public function test_now_credits_with_the_tax_it_was_paid_with(): void
    {
        $this->settings(['tax_enabled' => [true, 'boolean'], 'tax_type' => ['exclusive', 'string']]);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        $service = $this->service();
        $service->client->primaryContact->update(['country' => 'GR']);
        // The year was paid with its tax: 120.00 + 24%.
        DB::table('invoice_items')->where('reference_id', $service->id)->update(['tax_rate' => 24, 'tax_amount' => 28.80, 'line_total' => 148.80]);

        $invoice = ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame('74.60', number_format((float) Credit::query()->sole()->amount, 2, '.', ''), '148.80 × 183 / 365');
        $this->assertSame(14.88, (float) $invoice->fresh()->total);
    }

    public function test_now_charges_the_accounts_saved_method_once(): void
    {
        // Decision 6 (PR C2): the account's method pays the new period, as a renewal; not the service's.
        if (!class_exists(CycleFakePay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\CycleFakePay; class CycleFakePay { public static array $charges = []; public function __construct($config = []) {}
                public function charge($invoice, $amount, $agreement) { self::$charges[] = [(int) $invoice->id, number_format((float) $amount, 2, ".", "")]; return true; } }');
        }
        $service = $this->service();
        $gateway = Gateway::query()->create(['extension' => 'CycleFakePay', 'name' => 'CycleFakePay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $service->client_id, 'gateway_id' => $gateway->id, 'name' => 'Card', 'external_reference' => 'c-1']);

        $invoice = ChangeCycle::apply($service->fresh(), $this->monthly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);

        $this->assertSame([[(int) $invoice->id, '12.00']], CycleFakePay::$charges);
    }

    public function test_now_is_refused_for_a_renewal_paid_ahead(): void
    {
        $service = $this->service(['expires_at' => '2028-01-01']);
        $this->periodLine($service, '2027-01-01', '2028-01-01', 120.00, Invoice::STATUS_PAID);

        $this->assertNotNull(ChangeCycle::refusal($service->fresh(), $this->monthly, ChangeCycle::NOW));
        $this->assertNull(ChangeCycle::refusal($service->fresh(), $this->monthly, ChangeCycle::NEXT_RENEWAL));
    }

    public function test_now_is_refused_without_account_credit(): void
    {
        $this->settings(['credits_enabled' => [false, 'boolean']]);
        $service = $this->service();

        $this->assertNotNull(ChangeCycle::refusal($service, $this->monthly, ChangeCycle::NOW));
        $this->assertNull(ChangeCycle::refusal($service, $this->monthly, ChangeCycle::NEXT_RENEWAL));
    }

    public function test_staff_change_it_from_the_service_page(): void
    {
        $service = $this->service();

        $this->livewireAs($this->admin())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeCycle', ['plan_id' => $this->monthly->id, 'mode' => ChangeCycle::NOW])
            ->assertNotified('Billing cycle changed');

        $this->assertSame($this->monthly->id, (int) $service->fresh()->plan_id);
        $this->assertSame(1, Credit::query()->count());
    }
}
