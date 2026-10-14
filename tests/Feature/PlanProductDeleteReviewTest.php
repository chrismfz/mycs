<?php

namespace Tests\Feature;

use App\Admin\Resources\ProductResource\Pages\EditProduct;
use App\Events\ServiceCancellation\Created;
use App\Listeners\CancellationCreatedListener;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Adversarial review of PR #73 (BIL-6 / CR-10). Each test states the behaviour the PR's own reasoning implies.
 */
class PlanProductDeleteReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 7,
            'settings.system_email_address' => 'staff@example.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-11-15',
        ], $attributes));
    }

    /** As a delete before BIL-6 (or an import that found no plan) left it. */
    private function planless(Service $service): Service
    {
        Schema::disableForeignKeyConstraints();
        DB::table('services')->where('id', $service->id)->update(['plan_id' => null]);
        Schema::enableForeignKeyConstraints();

        return $service->fresh();
    }

    /**
     * R1. The cron no longer bills a plan-less service ("staff set one"), but still suspends it for non-payment and then
     * terminates it (deprovisions it): the customer was never sent an invoice for the period it is cut off for.
     */
    public function test_a_planless_service_that_the_cron_does_not_bill_is_not_suspended_or_terminated_for_non_payment(): void
    {
        $service = $this->planless($this->service(['expires_at' => '2026-10-10']));

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(0, $service->invoices()->count(), 'not billed (the PR)');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'never billed, so not suspended as unpaid');
    }

    public function test_a_planless_suspended_service_is_not_terminated_for_non_payment(): void
    {
        $service = $this->planless($this->service(['expires_at' => '2026-09-01', 'status' => 'suspended', 'dunning_suspended_at' => '2026-09-05']));

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'never billed: not terminated');
    }

    /**
     * R2 (CR-10). The end-of-period request cancels the pending upgrades "an upgrade of a service about to end makes no
     * sense", but the service is upgradable again right after: the customer starts a new upgrade, pays it, and the
     * service ends at the period's end anyway (the very refund the PR wanted to avoid).
     */
    public function test_a_service_with_an_end_of_period_request_is_not_upgradable(): void
    {
        $service = $this->service();
        $target = $this->createProduct();
        $service->product->upgrades()->attach($target->product->id);

        $this->assertTrue($service->fresh()->upgradable, 'precondition: upgradable before the request');

        $cancellation = ServiceCancellation::withoutEvents(fn () => ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'end_of_period', 'reason' => 'leaving']));
        (new CancellationCreatedListener)->handle(new Created($cancellation));

        $this->assertFalse($service->fresh()->upgradable, 'a service about to end offers no new upgrade');
    }

    /**
     * R3. The PR keeps plan-less services around and tells staff to set a plan, but the customer's own service page
     * still dereferences the null plan (Service::cancellable / upgradable): a 500 on services.show.
     */
    public function test_a_planless_service_page_does_not_crash(): void
    {
        $service = $this->planless($this->service());

        $this->assertFalse($service->cancellable);
        $this->assertFalse($service->upgradable);
    }

    /**
     * R4. A payment on a plan-less service: RenewServiceService now throws a DisplayException "left to BIL-9". The
     * payment must be recorded (invoice paid, apply pending), not lost or thrown back at staff.
     */
    public function test_a_payment_on_a_planless_service_is_kept_and_left_to_apply(): void
    {
        $service = $this->planless($this->service(['expires_at' => '2026-10-25']));
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-25']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-10-25', 'period_end' => '2026-11-25']);

        (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, 10);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->apply_pending_since, 'left to the cron (BIL-9)');
        $this->assertSame('2026-10-25', $service->fresh()->expires_at->toDateString(), 'not renewed without a plan');
    }

    /** R5. The repeater's delete guard: a plan in use stays in the form (and so is not deleted on save). */
    public function test_removing_a_plan_in_use_from_the_product_form_keeps_it(): void
    {
        $service = $this->service();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        $page = $this->livewireAs($admin)->test(EditProduct::class, ['record' => $service->product->getRouteKey()]);
        $key = array_key_first($page->get('data.plan'));
        $page->callAction(TestAction::make('delete')->schemaComponent('plan', 'form')->arguments(['item' => $key]));
        $page->assertNotified('Whoops!'); // the guard ran and cancelled the removal
        $page->call('save');

        $this->assertNotNull($service->plan()->first(), 'the plan in use stays');
    }

    /**
     * R6. The guard runs when the item is removed; the row is deleted only on Save. A plan that a service started using
     * in between (an order placed meanwhile) makes Save throw the model's DisplayException, which Filament does not turn
     * into a notification: a 500 for staff, the form's other changes lost.
     */
    public function test_saving_after_a_plan_became_used_meanwhile_is_a_notification_not_a_500(): void
    {
        $service = $this->service();
        $product = $service->product;
        $spare = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class,
            'name' => 'Spare', 'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        $page = $this->livewireAs($admin)->test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $key = collect($page->get('data.plan'))->search(fn ($item) => ($item['id'] ?? null) === $spare->id);
        $this->assertNotFalse($key);
        $page->callAction(TestAction::make('delete')->schemaComponent('plan', 'form')->arguments(['item' => $key]));
        $page->assertNotNotified('Whoops!'); // unused when removed
        $this->service(['plan_id' => $spare->id, 'product_id' => $product->id]); // ordered meanwhile

        $page->call('save'); // must not throw

        $this->assertNotNull($spare->fresh());
    }

    /**
     * R5b (round 2). EditProduct::save catches the DisplayException and says «Product not saved» ("the whole save is
     * rolled back"), but the page has no database transaction (Filament's default is off; only EditInvoice turns it
     * on): a plan removed in the same save and deleted before the refused one stays deleted (the product's own fields
     * are written after the relationships, so they happen to be spared).
     */
    public function test_a_save_refused_for_a_plan_used_meanwhile_changes_nothing(): void
    {
        $service = $this->service();
        $product = $service->product;
        $other = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class,
            'name' => 'Other', 'billing_unit' => 'month', 'billing_period' => 3, 'type' => 'recurring']);
        $spare = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class,
            'name' => 'Spare', 'billing_unit' => 'year', 'billing_period' => 1, 'type' => 'recurring']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner' . uniqid(), 'permissions' => ['*']])->id]);

        $page = $this->livewireAs($admin)->test(EditProduct::class, ['record' => $product->getRouteKey()]);
        foreach ([$other, $spare] as $plan) { // both unused when removed
            $key = collect($page->get('data.plan'))->search(fn ($item) => ($item['id'] ?? null) === $plan->id);
            $page->callAction(TestAction::make('delete')->schemaComponent('plan', 'form')->arguments(['item' => $key]));
        }
        $page->set('data.name', 'Renamed');
        $this->service(['plan_id' => $spare->id, 'product_id' => $product->id]); // ordered meanwhile

        $page->call('save');

        $page->assertNotified(__('Product not saved'));
        $this->assertSame('Test Product', $product->fresh()->name, 'not saved means nothing saved');
        $this->assertNotNull($other->fresh(), 'nor the other plan removed in the same save');
    }
}
