<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Exceptions\DisplayException;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Service\AddAddon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Staff's «Add addon» (docs/addons.md, step 3): prorated to the parent's date by default, its own date, or no invoice. */
class AddAddonTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->client = User::factory()->create()->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function parent(string $expires = '2026-12-01', string $unit = 'month', string $status = 'active'): Service
    {
        $product = $this->createProduct();
        $product->plan->update(['billing_unit' => $unit]);

        return Service::factory()->create([
            'client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'domain' => 'vm104.example.test',
        ]);
    }

    /** @return array{0: Product, 1: Plan} an addon product at 3.00 USD a cycle */
    private function addonProduct(string $unit = 'month', string $quantity = 'combined', ?array $targets = null): array
    {
        $made = $this->createProduct();
        $made->product->update(['name' => 'Extra IPv4', 'is_addon' => true, 'allow_quantity' => $quantity, 'addon_targets' => $targets]);
        $made->plan->update(['billing_unit' => $unit]);
        Price::query()->where('plan_id', $made->plan->id)->update(['price' => 3.00]);

        return [$made->product->fresh(), $made->plan->fresh()];
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'All' . uniqid(), 'permissions' => ['*']])->id]);
    }

    public function test_prorated_it_is_invoiced_up_to_the_parents_date_and_waits_for_payment(): void
    {
        $parent = $this->parent('2026-12-01');
        $parent->forceFill(['billing_day' => 1])->save();
        [$product, $plan] = $this->addonProduct();

        $made = (new AddAddon)->handle($parent, $product, 3, null, false, AddAddon::PRORATED);

        $addon = $made['service']->fresh();
        $this->assertSame(Service::STATUS_PENDING, $addon->status);
        $this->assertSame($parent->id, $addon->parent_service_id);
        $this->assertSame(1, (int) $addon->billing_day, 'it renews on its parent\'s day');
        $line = $made['invoice']->items()->sole();
        $this->assertSame('2026-11-20', $line->period_start->toDateString());
        $this->assertSame('2026-12-01', $line->period_end->toDateString());
        $this->assertSame('1.10', number_format((float) $line->price, 2), '3.00 × 11 of 30 days');
        $this->assertSame(3, (int) $line->quantity);
        $this->assertStringStartsWith('Extra IPv4 (vm104.example.test)', $line->description);
        $this->assertNull($made['invoice']->fresh()->finish_pending_at, 'finished: numbered and sent');
    }

    /** Sweep CRON-BILLING-3: a parent on the 31st whose date is a clamped month end steps back on the 31st. */
    public function test_a_parent_on_the_31st_prorates_on_the_31st(): void
    {
        Carbon::setTestNow('2027-02-14 10:00:00');
        $parent = $this->parent('2027-02-28');
        $parent->forceFill(['billing_day' => 31])->save();
        [, $plan] = $this->addonProduct();

        $quote = AddAddon::quote($parent, $plan, '3.00', AddAddon::PRORATED);
        $this->assertSame('2027-02-28', $quote[1]->toDateString());
        $this->assertSame('1.50', $quote[2], '3.00 × 14 of the 28 days from 31 January, not 14 of 31 from 28 January');
    }

    public function test_paid_it_starts_with_its_parents_date_and_staff_get_its_creation_task(): void
    {
        $parent = $this->parent('2026-12-01');
        [$product, $plan] = $this->addonProduct();
        $made = (new AddAddon)->handle($parent, $product, 1, null, false, AddAddon::PRORATED);

        (new RecordPaymentService)->handle($made['invoice']->fresh(), null, $made['invoice']->fresh()->remaining, transactionId: 'addon-1');

        $addon = $made['service']->fresh();
        $this->assertSame(Service::STATUS_ACTIVE, $addon->status);
        $this->assertSame('2026-12-01', $addon->expires_at->toDateString());
        $task = ModuleAction::query()->where('service_id', $addon->id)->sole();
        $this->assertSame('create', $task->action);
        $this->assertStringContainsString("#{$parent->id} vm104.example.test", (string) $task->last_error);
    }

    public function test_then_it_renews_on_its_parents_invoice(): void
    {
        $parent = $this->parent('2026-12-01');
        [$product, $plan] = $this->addonProduct();
        $made = (new AddAddon)->handle($parent, $product, 1, null, false, AddAddon::PRORATED);
        (new RecordPaymentService)->handle($made['invoice']->fresh(), null, $made['invoice']->fresh()->remaining, transactionId: 'addon-2');

        $billing = new RenewalBilling;
        $billing->billRenewal($parent->id, Carbon::parse('2026-12-31'));
        $billing->billRenewal($made['service']->id, Carbon::parse('2026-12-31'));
        $billing->finishRenewalInvoices();

        $renewal = Invoice::query()->whereKeyNot($made['invoice']->id)->sole();
        $this->assertEqualsCanonicalizing([$parent->id, $made['service']->id], $renewal->items()->pluck('reference_id')->map(fn ($id) => (int) $id)->all());
    }

    /** «From its own date» is gone (owner 2026-11-08: an addon always shares its parent's due date): refused, nothing made. */
    public function test_from_its_own_date_is_no_longer_a_way_to_add_one(): void
    {
        $parent = $this->parent('2026-12-01');
        [$product, $plan] = $this->addonProduct();

        try {
            (new AddAddon)->handle($parent, $product, 1, null, false, 'own_date', Carbon::parse('2026-11-25'));
            $this->fail('refused');
        } catch (DisplayException $e) {
            $this->assertSame(__('services.addons.add.start_unknown'), $e->getMessage());
        }
        $this->assertSame(0, Service::query()->whereNotNull('parent_service_id')->count());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_with_no_invoice_it_starts_now_and_its_task_is_made(): void
    {
        $parent = $this->parent('2026-12-01');
        [$product, $plan] = $this->addonProduct();

        $made = (new AddAddon)->handle($parent, $product, 1, '0', false, AddAddon::NO_INVOICE);

        $addon = $made['service']->fresh();
        $this->assertNull($made['invoice']);
        $this->assertSame(Service::STATUS_ACTIVE, $addon->status);
        $this->assertSame('2026-12-01', $addon->expires_at->toDateString());
        $this->assertTrue($addon->price_locked, 'another price than the catalog\'s is kept');
        $this->assertSame('create', ModuleAction::query()->where('service_id', $addon->id)->sole()->action);
    }

    public function test_started_under_a_suspended_parent_it_is_suspended_with_it(): void
    {
        $parent = $this->parent('2026-12-01', status: 'suspended');
        [$product, $plan] = $this->addonProduct();

        $made = (new AddAddon)->handle($parent, $product, 1, null, false, AddAddon::NO_INVOICE);

        $addon = $made['service']->fresh();
        $this->assertSame(Service::STATUS_SUSPENDED, $addon->status);
        $this->assertSame(Service::SUSPEND_REASON_PARENT, $addon->suspend_reason);
    }

    public function test_what_is_refused(): void
    {
        $parent = $this->parent('2026-12-01');
        [$product, $plan] = $this->addonProduct(quantity: 'disabled');
        [$onlyProxmox, $proxmoxPlan] = $this->addonProduct(targets: ['module:Proxmox']);
        $ended = $this->parent(status: 'terminated');
        $cancelling = $this->parent();
        ServiceCancellation::query()->create(['service_id' => $cancelling->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        foreach ([
            [fn () => (new AddAddon)->handle($ended, $product, 1, null, false, AddAddon::PRORATED), 'services.addons.add.refused_status'],
            [fn () => (new AddAddon)->handle($cancelling, $product, 1, null, false, AddAddon::PRORATED), 'services.addons.add.refused_cancelling'],
            [fn () => (new AddAddon)->handle($parent, $product, 2, null, false, AddAddon::PRORATED), 'services.addons.add.quantity'],
            [fn () => (new AddAddon)->handle($this->parent('2026-11-01'), $product, 1, null, false, AddAddon::NO_INVOICE), 'services.addons.add.no_due_date'],
            [fn () => (new AddAddon)->handle($parent, $product, 1, '-1', false, AddAddon::PRORATED), 'services.addons.add.price_negative'],
        ] as [$add, $message]) {
            try {
                $add();
                $this->fail("refused: {$message}");
            } catch (DisplayException $e) {
                $this->assertSame(__($message), $e->getMessage());
            }
        }
        try {
            (new AddAddon)->handle($parent, $onlyProxmox, 1, null, false, AddAddon::PRORATED);
            $this->fail('it does not apply to this service');
        } catch (DisplayException $e) {
            $this->assertSame(__('services.addons.add.not_for_this', ['addon' => 'Extra IPv4']), $e->getMessage());
        }
        $this->assertFalse(AddAddon::products($parent)->contains('id', $onlyProxmox->id));
        $this->assertSame(0, Service::query()->whereNotNull('parent_service_id')->count());
    }

    public function test_staff_are_told_when_a_change_of_package_leaves_an_addon_out(): void
    {
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $parent = $this->parent('2026-12-01');
        [$product, $plan] = $this->addonProduct(targets: ['product:' . $parent->product_id]);
        $made = (new AddAddon)->handle($parent, $product, 1, '0', false, AddAddon::NO_INVOICE);
        $other = $this->createProduct()->product;

        $parent->fresh()->update(['product_id' => $other->id, 'plan_id' => $other->plans()->first()->id]);

        $this->assertTrue(EmailLog::query()->where('subject', "Service #{$parent->id}: addons that no longer apply")
            ->where('body', 'like', "%#{$made['service']->id} Extra IPv4%")->exists());
    }

    public function test_staff_add_it_from_the_services_page(): void
    {
        $parent = $this->parent('2026-12-01');
        [$product, $plan] = $this->addonProduct();

        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $parent->getRouteKey()])
            ->callAction('addAddon', ['product_id' => $product->id, 'plan_id' => $plan->id, 'quantity' => 2, 'start' => AddAddon::PRORATED])
            ->assertHasNoActionErrors();

        $addon = Service::query()->where('parent_service_id', $parent->id)->sole();
        $this->assertSame(2, (int) $addon->quantity);
        $this->assertSame(Service::STATUS_PENDING, $addon->status);
        $this->assertTrue($addon->invoices()->exists());
    }
}
