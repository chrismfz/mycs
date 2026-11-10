<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Admin\Resources\ServiceResource\RelationManagers\UpgradesRelationManager;
use App\Exceptions\DisplayException;
use App\Livewire\Services\Upgrade;
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
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyTrail;
use App\Services\ServiceUpgrade\ChangeCycle;
use App\Services\ServiceUpgrade\PlaceUpgrade;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sweep 6, adversarial verification of «plan-cycle-addons»: change of cycle, upgrades and addons, each test failing
 * for the reason reported. With the fixes (owner 2026-11-10): findings 1 and 2 were cut (refused, the message naming the
 * way through), so their tests now pin the refusal and staff's way through it; finding 4's test lives, rewritten, in
 * Sweep6AddonsFollowCycleTest (#765).
 */
class Sweep6PlanCycleAddonsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'],
            'default_currency' => ['USD', 'string'], 'cronjob_invoice' => [7, 'integer']]);
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

    private function plan(Product $product, string $unit, float $price): Plan
    {
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => ucfirst($unit) . 'ly',
            'billing_unit' => $unit, 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => $price, 'setup_fee' => 0, 'currency_code' => 'USD']);

        return $plan->fresh();
    }

    private function service(Product $product, Plan $plan, float $price, string $start, string $end, array $attributes = []): Service
    {
        $service = Service::factory()->create($attributes + ['client_id' => User::factory()->create()->client()->id,
            'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => $price, 'quantity' => 1,
            'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'expires_at' => $end]);
        $this->periodLine($service, $start, $end, $price, Invoice::STATUS_PAID);

        return $service->fresh();
    }

    private function periodLine(Service $service, string $start, string $end, float $price, string $status): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $line = $invoice->items()->create(['description' => 'Renewal', 'price' => $price, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => $start, 'period_end' => $end]);
        if ($status !== Invoice::STATUS_UNPAID) {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status]);
        }

        return $line;
    }

    private function pay(Invoice $invoice, string $ref): void
    {
        $invoice->refresh();
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->remaining, transactionId: $ref);
    }

    private function upgradeLine(ServiceUpgrade $upgrade): InvoiceItem
    {
        return InvoiceItem::query()->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id)
            ->where('invoice_id', $upgrade->invoice_id)->sole();
    }

    /**
     * S6-PLAN-CYCLE-ADDONS-1: an upgrade placed while a change of cycle «from the next renewal» is set. The cron applied
     * the yearly plan when it billed the renewal; the pending upgrade (priced for the monthly cycle) then read as a
     * downgrade, its line fell to 0.00, and its payment put the service back on a monthly plan. Cut (owner 2026-11-10):
     * no change of package while the change of cycle is set; taken back (one click), the upgrade is placed.
     */
    public function test_an_upgrade_after_a_cycle_change_set_for_the_next_renewal_is_refused_until_it_is_taken_back(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $a = Product::factory()->create(['name' => 'Basic']);
        $aMonthly = $this->plan($a, 'month', 10.00);
        $aYearly = $this->plan($a, 'year', 120.00);
        $b = Product::factory()->create(['name' => 'Pro']);
        $this->plan($b, 'month', 20.00);
        $this->plan($b, 'year', 240.00);
        $service = $this->service($a, $aMonthly, 10.00, '2026-11-01', '2026-12-01');

        // The customer's yearly choice, from the next renewal.
        ChangeCycle::apply($service, $aYearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);
        $this->assertSame($aYearly->id, (int) $service->fresh()->next_plan_id);

        // Then an upgrade to Pro, by the customer or by staff: refused, nothing made.
        foreach ([ServiceUpgrade::SOURCE_CLIENT, ServiceUpgrade::SOURCE_STAFF] as $source) {
            $placed = PlaceUpgrade::handle($service->fresh(), $b, [], $source);
            $this->assertSame(PlaceUpgrade::CYCLE_CHANGE_PENDING, $placed->outcome);
        }
        $this->assertSame(0, ServiceUpgrade::query()->where('service_id', $service->id)->count());
        $this->assertSame(0, InvoiceItem::query()->where('reference_type', ServiceUpgrade::class)->count());
        // The message names the way out, in both languages.
        foreach (['en' => 'take it back', 'el' => 'πάρτε την πίσω'] as $locale => $wayOut) {
            $this->assertStringContainsString($wayOut, __('services.upgrade_cycle_change_pending', [], $locale));
        }

        // Taken back (its own plan, from the next renewal), the upgrade is placed.
        ChangeCycle::apply($service->fresh(), $aMonthly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);
        $this->assertNull($service->fresh()->next_plan_id);
        $this->assertSame(PlaceUpgrade::INVOICED, PlaceUpgrade::handle($service->fresh(), $b, [], ServiceUpgrade::SOURCE_STAFF)->outcome);
    }

    /** S6-PLAN-CYCLE-ADDONS-1 on the pages: the customer's upgrade page sends them back with the way out; staff's «Upgrade/Downgrade» says the same and makes nothing. */
    public function test_the_upgrade_pages_refuse_while_a_cycle_change_is_set_for_the_next_renewal(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $a = Product::factory()->create(['name' => 'Basic', 'server_id' => null, 'stock' => null]);
        $aMonthly = $this->plan($a, 'month', 10.00);
        $aYearly = $this->plan($a, 'year', 120.00);
        $b = Product::factory()->create(['name' => 'Pro', 'server_id' => null, 'stock' => null]);
        $this->plan($b, 'month', 20.00);
        DB::table('product_upgrades')->insert(['product_id' => $a->id, 'upgrade_id' => $b->id]);
        $user = User::factory()->create();
        $service = $this->service($a, $aMonthly, 10.00, '2026-11-01', '2026-12-01', ['client_id' => $user->client()->id]);
        ChangeCycle::apply($service, $aYearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_SYSTEM);

        $this->livewireAs($user)->test(Upgrade::class, ['service' => $service->fresh()])->assertRedirect(route('services.show', $service));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'S6 ' . uniqid(), 'permissions' => ['*']])->id]);
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changePackage', ['product_id' => $b->id])
            ->assertNotified('Not changed');
        $this->assertSame(0, ServiceUpgrade::query()->where('service_id', $service->id)->count());
        $this->assertSame($aYearly->id, (int) $service->fresh()->next_plan_id, 'the yearly choice stands');
    }

    /**
     * S6-PLAN-CYCLE-ADDONS-1, the other way: no change of cycle «from the next renewal» while an upgrade waits for its
     * payment. The message names its invoice; staff cancel the pending change and the change of cycle goes ahead.
     */
    public function test_a_cycle_change_from_the_next_renewal_is_refused_while_an_upgrade_waits_and_goes_once_staff_cancel_it(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $a = Product::factory()->create(['name' => 'Basic']);
        $aMonthly = $this->plan($a, 'month', 10.00);
        $aYearly = $this->plan($a, 'year', 120.00);
        $b = Product::factory()->create(['name' => 'Pro']);
        $this->plan($b, 'month', 20.00);
        $service = $this->service($a, $aMonthly, 10.00, '2026-11-01', '2026-12-01');
        $placed = PlaceUpgrade::handle($service, $b, [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $placed->outcome);

        foreach ([MoneyTrail::SOURCE_SYSTEM, MoneyTrail::SOURCE_STAFF] as $source) {
            try {
                ChangeCycle::apply($service->fresh(), $aYearly, ChangeCycle::NEXT_RENEWAL, $source);
                $this->fail('A change of cycle from the next renewal went through while an upgrade waits for payment.');
            } catch (DisplayException $e) {
                $this->assertSame(__('services.upgrade_waiting', ['invoice' => $placed->invoice->number ?? $placed->invoice->id]), $e->getMessage());
            }
        }
        $this->assertNull($service->fresh()->next_plan_id);
        foreach (['en' => ['pay it', 'cancelled'], 'el' => ['πληρώστε το', 'ακυρώστε']] as $locale => $wayOut) {
            foreach ($wayOut as $words) {
                $this->assertStringContainsString($words, __('services.upgrade_waiting', ['invoice' => 7], $locale));
            }
        }

        // Staff's way through: «Cancel» on the pending change («Package changes» on the service's page), then the change.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'S6 ' . uniqid(), 'permissions' => ['*']])->id]), 'staff');
        Livewire::test(UpgradesRelationManager::class, ['ownerRecord' => $service->fresh(), 'pageClass' => EditService::class])
            ->assertTableActionVisible('cancel', $placed->upgrade)
            ->callTableAction('cancel', $placed->upgrade)
            ->assertTableActionHidden('cancel', $placed->upgrade);
        $this->assertSame(ServiceUpgrade::STATUS_CANCELLED, $placed->upgrade->fresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $placed->invoice->fresh()->status, 'its invoice goes with it');
        $this->assertSame($a->id, (int) $service->fresh()->product_id, 'never applied');

        ChangeCycle::apply($service->fresh(), $aYearly, ChangeCycle::NEXT_RENEWAL, MoneyTrail::SOURCE_STAFF);
        $this->assertSame($aYearly->id, (int) $service->fresh()->next_plan_id);
    }

    /**
     * S6-PLAN-CYCLE-ADDONS-2: a completed upgrade's shortfall invoice (the days of a renewal paid before the upgrade) is
     * not seen by a change of cycle «now»: it stays open, billing the upgraded days that change credited and billed again.
     */
    public function test_a_cycle_change_now_does_not_leave_an_upgrade_shortfall_open(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $a = Product::factory()->create(['name' => 'Basic']);
        $aMonthly = $this->plan($a, 'month', 10.00);
        $this->plan($a, 'year', 120.00);
        $b = Product::factory()->create(['name' => 'Pro']);
        $this->plan($b, 'month', 20.00);
        $bYearly = $this->plan($b, 'year', 240.00);
        $service = $this->service($a, $aMonthly, 10.00, '2026-11-01', '2026-12-01');

        $placed = PlaceUpgrade::handle($service, $b, [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $placed->outcome);
        $upgrade = $placed->upgrade;

        // Nov 25: the cron bills the December renewal (Basic, 10) and reprices the upgrade line; the customer pays the
        // renewal, then the upgrade: the December days at Pro are billed apart (the shortfall).
        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');
        $renewal = InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereDate('period_start', '2026-12-01')->sole()->invoice;
        $this->pay($renewal, 'renewal');
        $this->assertSame('2027-01-01', $service->fresh()->expires_at->toDateString());
        $this->pay($upgrade->fresh()->invoice, 'upgrade');
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);
        $shortfall = Invoice::query()->whereKeyNot($upgrade->invoice_id)
            ->whereHas('items', fn ($q) => $q->where('reference_type', ServiceUpgrade::class)->where('reference_id', $upgrade->id))->sole();
        $this->assertSame(Invoice::STATUS_UNPAID, $shortfall->status);

        // Dec 5: staff change the cycle «now» to Pro yearly. Cut (owner 2026-11-10): refused while that invoice is open,
        // the message naming it.
        Carbon::setTestNow('2026-12-05 10:00:00');
        try {
            ChangeCycle::apply($service->fresh(), $bYearly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
            $this->fail('The upgrade\'s shortfall (Pro over Basic, Dec 1 - Jan 1) stays open after a new yearly Pro period from Dec 5 was billed in full.');
        } catch (DisplayException $e) {
            $this->assertSame(__('services.upgrade_invoice_open', ['invoice' => $shortfall->number ?? $shortfall->id]), $e->getMessage());
        }
        $this->assertSame(Invoice::STATUS_UNPAID, $shortfall->fresh()->status);
        $this->assertSame('2027-01-01', $service->fresh()->expires_at->toDateString(), 'nothing changed');

        // A downgrade (back to Basic) is refused the same way: the shortfall would keep billing Pro for days on Basic.
        $down = PlaceUpgrade::handle($service->fresh(), $a, [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::UPGRADE_INVOICE_OPEN, $down->outcome);
        $this->assertSame($shortfall->id, $down->invoice?->id);
        $this->assertSame($b->id, (int) $service->fresh()->product_id);
        $this->assertSame(1, ServiceUpgrade::query()->where('service_id', $service->id)->count(), 'the refused downgrade left no row');

        // Staff's way through: «Cancel» on the shortfall invoice, then the change of cycle now goes ahead.
        $shortfall->fresh()->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'Cycle changed instead']);
        $invoice = ChangeCycle::apply($service->fresh(), $bYearly, ChangeCycle::NOW, MoneyTrail::SOURCE_STAFF);
        $this->assertNotNull($invoice);
        $this->assertSame($bYearly->id, (int) $service->fresh()->plan_id);
        $this->assertNull(ServiceUpgrade::openInvoiceOf($service->fresh()), 'no upgrade invoice left open');
    }

    /** S6-PLAN-CYCLE-ADDONS-2, the downgrade: once the shortfall is paid (or cancelled), a downgrade is placed as before. */
    public function test_a_downgrade_goes_ahead_once_the_upgrade_shortfall_is_paid(): void
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $a = Product::factory()->create(['name' => 'Basic']);
        $aMonthly = $this->plan($a, 'month', 10.00);
        $b = Product::factory()->create(['name' => 'Pro']);
        $this->plan($b, 'month', 20.00);
        $service = $this->service($a, $aMonthly, 10.00, '2026-11-01', '2026-12-01');
        $upgrade = PlaceUpgrade::handle($service, $b, [], ServiceUpgrade::SOURCE_STAFF)->upgrade;

        Carbon::setTestNow('2026-11-25 10:00:00');
        $this->artisan('app:cron-job');
        $this->pay(InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service->id)
            ->whereDate('period_start', '2026-12-01')->sole()->invoice, 'renewal');
        $this->pay($upgrade->fresh()->invoice, 'upgrade');
        $shortfall = ServiceUpgrade::openInvoiceOf($service->fresh());
        $this->assertNotNull($shortfall, 'the December days at Pro, billed apart');
        $this->assertSame(PlaceUpgrade::UPGRADE_INVOICE_OPEN, PlaceUpgrade::handle($service->fresh(), $a, [], ServiceUpgrade::SOURCE_STAFF)->outcome);

        $this->pay($shortfall, 'shortfall');
        $this->assertSame(PlaceUpgrade::APPLIED, PlaceUpgrade::handle($service->fresh(), $a, [], ServiceUpgrade::SOURCE_STAFF)->outcome);
        $this->assertSame($a->id, (int) $service->fresh()->product_id);
    }

    /**
     * S6-PLAN-CYCLE-ADDONS-3: an upgrade's money is spread from the day it was placed, though the cron repriced its line
     * to the days left when it was paid: a cycle change «now» credits too little of it.
     */
    public function test_a_cycle_change_now_credits_an_upgrade_from_the_days_its_payment_covered(): void
    {
        Carbon::setTestNow('2026-09-04 10:00:00');
        $a = Product::factory()->create(['name' => 'Basic']);
        $aYearly = $this->plan($a, 'year', 120.00);
        $b = Product::factory()->create(['name' => 'Pro']);
        $bMonthly = $this->plan($b, 'month', 20.00);
        $this->plan($b, 'year', 240.00);
        $service = $this->service($a, $aYearly, 120.00, '2026-07-01', '2027-07-01');

        $placed = PlaceUpgrade::handle($service, $b, [], ServiceUpgrade::SOURCE_STAFF);
        $this->assertSame(PlaceUpgrade::INVOICED, $placed->outcome);
        $upgrade = $placed->upgrade;

        // 60 days later the cron reprices the line to the 240 days left, and it is paid.
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->artisan('app:cron-job');
        $paid = BigDecimal::of((string) $this->upgradeLine($upgrade->fresh())->price);
        $this->assertSame('78.90', (string) $paid->toScale(2), '120 a year more for 240 of 365 days');
        $this->pay($upgrade->fresh()->invoice, 'upgrade');
        $this->assertSame(ServiceUpgrade::STATUS_COMPLETED, $upgrade->fresh()->status);

        // 10 days on (230 days left), staff change the cycle «now».
        Carbon::setTestNow('2026-11-13 10:00:00');
        $today = CarbonImmutable::today();
        $quote = ChangeCycle::quote($service->fresh(), $bMonthly, ChangeCycle::NOW);
        $period = BigDecimal::of(ChangeCycle::unused($service->fresh(), $today));
        $upgradePart = BigDecimal::of($quote['credit'])->minus($period);

        // What was paid for 240 days, 230 of them left: 75.61, not 78.90 x 230 / 300 = 60.49.
        $this->assertEqualsWithDelta(75.61, (float) (string) $upgradePart, 0.02,
            "The upgrade's part of the credit is {$upgradePart}: its money is spread from the day it was placed, not from the days it paid for.");
    }
}
