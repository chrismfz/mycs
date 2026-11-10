<?php

namespace Tests\Feature;

use App\Admin\Resources\CategoryResource\Pages\EditCategory;
use App\Admin\Resources\CategoryResource\RelationManagers\ProductsRelationManager;
use App\Admin\Resources\ProductAddonResource\Pages\CreateProductAddon;
use App\Admin\Resources\ProductAddonResource\Pages\ListProductAddons;
use App\Admin\Resources\ProductResource\Pages\ListProducts;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Exceptions\DisplayException;
use App\Exceptions\NothingToDoOnServer;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Livewire\Services\Index;
use App\Livewire\Services\Show;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Service\AddonCascade;
use App\Services\Service\DeleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Addons as services of their own fastened to a parent (docs/addons.md, step 2): they follow it (suspend, back, end,
 * a cancellation request), their own server actions are staff's tasks, and they move, delete and show with it.
 */
class AddonsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 03:00:00');
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function parent(string $expires = '2026-12-01', string $status = 'active', array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'domain' => 'vm104.example.test', ...$attributes,
        ]);
    }

    private function addon(Service $parent, string $expires = '2026-12-01', string $status = 'active', ?int $stock = null, int $quantity = 1): Service
    {
        $product = $this->createProduct();
        $product->product->update(['name' => 'Extra IPv4', 'is_addon' => true, 'stock' => $stock]);

        return Service::factory()->create([
            'client_id' => $parent->client_id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'parent_service_id' => $parent->id, 'status' => $status, 'expires_at' => $expires, 'currency_code' => 'USD',
            'price' => 3.00, 'quantity' => $quantity,
        ]);
    }

    private function openInvoiceFor(Service ...$services): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $services[0]->client_id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        foreach ($services as $service) {
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
                'quantity' => 1, 'price' => $service->price, 'period_start' => $service->expires_at, 'period_end' => $service->expires_at->copy()->addMonth()]);
        }

        return $invoice;
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'All' . uniqid(), 'permissions' => ['*']])->id]);
    }

    public function test_the_cron_suspends_the_addon_with_its_parent_and_asks_its_server_nothing(): void
    {
        Queue::fake();
        $parent = $this->parent('2026-11-01');
        $this->billedAhead($parent);
        $addon = $this->addon($parent, '2026-11-01');

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $parent->fresh()->status);
        $addon->refresh();
        $this->assertSame(Service::STATUS_SUSPENDED, $addon->status);
        $this->assertSame(Service::SUSPEND_REASON_PARENT, $addon->suspend_reason);
        $this->assertNull($addon->dunning_suspended_at, 'suspended with its parent, not for its own invoice');
        Queue::assertPushed(SuspendJob::class, fn (SuspendJob $job) => $job->service->id === $parent->id);
        Queue::assertNotPushed(SuspendJob::class, fn (SuspendJob $job) => $job->service->id === $addon->id);
    }

    public function test_unsuspending_the_parent_brings_back_only_those_suspended_with_it(): void
    {
        Queue::fake();
        $parent = $this->parent();
        $with = $this->addon($parent);
        $own = $this->addon($parent);
        $own->forceFill(['status' => 'suspended', 'suspend_reason' => 'Overdue on Payment', 'dunning_suspended_at' => now()])->save();

        $parent->applyStaffChanges(['status' => Service::STATUS_SUSPENDED]);
        $this->assertSame(Service::STATUS_SUSPENDED, $with->fresh()->status);
        $this->assertSame('Overdue on Payment', $own->fresh()->suspend_reason, 'its own reason is kept');

        $parent->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);

        $with->refresh();
        $this->assertSame(Service::STATUS_ACTIVE, $with->status);
        $this->assertNull($with->suspend_reason);
        $this->assertSame(Service::STATUS_SUSPENDED, $own->fresh()->status, 'suspended for its own invoice: stays');
        Queue::assertNotPushed(UnsuspendJob::class, 'nothing was done by hand on the machine for it');
    }

    public function test_an_addon_suspended_by_hand_gets_its_unsuspension_as_a_task_when_its_parent_is_back(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $addon->placeOnHold();
        ExtensionHelper::suspendServer($addon); // staff's task: suspend it by hand
        $addon->forceFill(['suspend_reason' => Service::SUSPEND_REASON_PARENT])->save();
        $parent->applyStaffChanges(['status' => Service::STATUS_SUSPENDED]);
        Queue::fake();

        $parent->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);

        $this->assertSame(Service::STATUS_ACTIVE, $addon->fresh()->status);
        Queue::assertPushed(UnsuspendJob::class, fn (UnsuspendJob $job) => $job->service->id === $addon->id);
    }

    public function test_an_addons_own_server_action_is_a_task_for_staff_naming_its_parent(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent, quantity: 3);

        ExtensionHelper::suspendServer($addon);

        $task = ModuleAction::query()->where('service_id', $addon->id)->sole();
        $this->assertSame('suspend', $task->action);
        $this->assertSame(ModuleAction::SUBJECT_BY_HAND, $task->subject);
        $this->assertSame(ModuleAction::STATUS_OPEN, $task->status);
        $this->assertStringContainsString('Extra IPv4 ×3', (string) $task->last_error);
        $this->assertStringContainsString("#{$parent->id} vm104.example.test", (string) $task->last_error);
    }

    public function test_an_addon_of_an_ended_parent_has_nothing_left_on_its_machine(): void
    {
        $parent = $this->parent(status: 'terminated');
        $addon = $this->addon($parent, status: 'terminated');

        $this->expectException(NothingToDoOnServer::class);
        ExtensionHelper::terminateServer($addon);
    }

    public function test_no_module_is_ever_asked_for_an_addon(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $addon->product->update(['server_id' => $parent->product->server_id]);

        $this->assertNull($addon->fresh()->provisioningServer());
        $this->assertTrue($addon->fresh()->hasServerActions(), 'its actions run, as staff\'s tasks (ADV-634-6)');
    }

    public function test_the_cron_terminating_the_parent_ends_its_addons_with_their_invoices_and_stock(): void
    {
        Queue::fake();
        $parent = $this->parent('2026-10-01', 'suspended');
        $parent->forceFill(['dunning_suspended_at' => now()->subDays(30), 'suspend_reason' => 'Overdue on Payment'])->save();
        $addon = $this->addon($parent, '2026-10-01', stock: 4);
        $addon->forceFill(['status' => 'suspended', 'suspend_reason' => Service::SUSPEND_REASON_PARENT])->save();
        $later = $this->addon($parent, '2027-01-01');
        $invoice = $this->openInvoiceFor($addon);
        config(['settings.cronjob_order_terminate' => 10]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_TERMINATED, $parent->fresh()->status);
        $this->assertSame(Service::STATUS_TERMINATED, $addon->fresh()->status);
        $this->assertSame(Service::STATUS_TERMINATED, $later->fresh()->status, 'paid further: ended with its parent all the same');
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertSame(5, $addon->product->fresh()->stock);
        Queue::assertNotPushed(TerminateJob::class, fn (TerminateJob $job) => $job->service->parent_service_id !== null);
    }

    public function test_an_end_of_period_request_is_its_addons_too_and_is_withdrawn_with_it(): void
    {
        $parent = $this->parent('2026-12-01');
        $addon = $this->addon($parent, '2026-11-25');
        $invoice = $this->openInvoiceFor($addon);

        $request = ServiceCancellation::query()->create(['service_id' => $parent->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        $made = ServiceCancellation::query()->where('service_id', $addon->id)->sole();
        $this->assertTrue($made->with_parent);
        $this->assertSame('end_of_period', $made->type);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status, 'nothing left to pay that would renew it');

        $request->delete();

        $this->assertFalse(ServiceCancellation::query()->where('service_id', $addon->id)->exists());
    }

    public function test_an_addon_paid_further_ends_the_day_its_parent_does(): void
    {
        Queue::fake();
        $parent = $this->parent('2026-11-20');
        $addon = $this->addon($parent, '2027-03-01');
        ServiceCancellation::query()->create(['service_id' => $parent->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_CANCELLED, $parent->fresh()->status);
        $this->assertSame(Service::STATUS_CANCELLED, $addon->fresh()->status);
    }

    public function test_an_immediate_cancellation_of_the_parent_ends_its_addons(): void
    {
        Queue::fake([TerminateJob::class]);
        $parent = $this->parent();
        $addon = $this->addon($parent);

        ServiceCancellation::query()->create(['service_id' => $parent->id, 'type' => 'immediate', 'reason' => 'Leaving']);

        $this->assertSame(Service::STATUS_CANCELLED, $parent->fresh()->status);
        $this->assertSame(Service::STATUS_CANCELLED, $addon->fresh()->status);
        $this->assertFalse(ServiceCancellation::query()->where('service_id', $addon->id)->exists(), 'ended with it, no request of its own');
    }

    public function test_an_addon_paid_while_its_parent_is_suspended_stays_suspended_with_it(): void
    {
        Queue::fake();
        $parent = $this->parent('2026-11-01', 'suspended');
        $addon = $this->addon($parent, '2026-11-01', 'suspended');
        $addon->forceFill(['suspend_reason' => 'Overdue on Payment', 'dunning_suspended_at' => now()->subDays(2)])->save();
        $invoice = $this->openInvoiceFor($addon);

        (new RecordPaymentService)->handle($invoice, null, '3.00', transactionId: 'addon-paid');

        $addon->refresh();
        $this->assertSame('2026-12-01', $addon->expires_at->toDateString(), 'its period is paid');
        $this->assertSame(Service::STATUS_SUSPENDED, $addon->status);
        $this->assertSame(Service::SUSPEND_REASON_PARENT, $addon->suspend_reason, 'back with its parent');
        Queue::assertNotPushed(UnsuspendJob::class);
    }

    public function test_an_addon_keeps_to_its_parents_account_and_currency_one_level_only(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $other = $this->parent(attributes: ['client_id' => User::factory()->create()->client()->id]);
        $euro = $this->parent(attributes: ['currency_code' => 'EUR']);

        foreach ([
            [fn () => $addon->update(['parent_service_id' => $other->id]), 'services.addons.parent_other_client'],
            [fn () => $addon->update(['parent_service_id' => $euro->id]), 'services.addons.parent_other_currency'],
            [fn () => $this->addon($addon), 'services.addons.parent_is_addon'],
            [fn () => $parent->update(['parent_service_id' => $euro->id]), 'services.addons.has_addons'],
            [fn () => $parent->update(['parent_service_id' => $parent->id]), 'services.addons.parent_self'],
        ] as [$change, $message]) {
            try {
                $change();
                $this->fail("refused: {$message}");
            } catch (DisplayException $e) {
                $this->assertSame(__($message), $e->getMessage());
            }
        }
    }

    public function test_its_invoice_line_names_its_parent(): void
    {
        $addon = $this->addon($this->parent());

        $this->assertStringStartsWith('Extra IPv4 (vm104.example.test)', $addon->describeLine(Carbon::parse('2026-12-01'), Carbon::parse('2027-01-01')));
    }

    public function test_it_moves_with_its_parent_never_on_its_own(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $invoice = $this->openInvoiceFor($parent, $addon);
        $to = User::factory()->create()->client();

        try {
            (new MoveToClient)->service($addon, $to, $this->staff());
            $this->fail('an addon moves with its parent');
        } catch (DisplayException $e) {
            $this->assertSame(__('services.addons.move_addon'), $e->getMessage());
        }

        (new MoveToClient)->service($parent, $to, $this->staff());

        $this->assertSame($to->id, $addon->fresh()->client_id);
        $this->assertSame($to->id, $invoice->fresh()->client_id, 'their invoice moved whole, one invoice still');
    }

    /** Not without staff's confirmation (owner 2026-11-10: with it they go together, StaffFreedomServicesTest). */
    public function test_a_parent_with_running_addons_is_not_deleted_unless_staff_confirm_them_too(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $ended = $this->addon($parent, status: 'cancelled');

        try {
            (new DeleteService)->handle($parent);
            $this->fail('refused');
        } catch (DisplayException $e) {
            $this->assertSame(__('services.addons.delete_parent'), $e->getMessage());
        }
        $this->assertNotNull($addon->fresh());

        (new DeleteService)->handle($parent, withAddons: true);

        $this->assertNull($parent->fresh());
        $this->assertNull($addon->fresh(), 'a running addon goes with it');
        $this->assertNull($ended->fresh()->parent_service_id, 'an ended addon stays, on its own');
    }

    public function test_an_addon_product_is_never_ordered_alone(): void
    {
        $addon = $this->createProduct()->product;
        $addon->update(['is_addon' => true]);

        $this->assertFalse(Product::query()->whereKey($addon->id)->reachable()->exists());
        $this->assertFalse(Product::query()->whereKey($addon->id)->listed()->exists());
    }

    public function test_what_an_addon_applies_to(): void
    {
        $parent = $this->parent();
        $addon = $this->createProduct()->product;
        $addon->update(['is_addon' => true]);

        $this->assertTrue($addon->appliesTo($parent), 'none set: any service');
        $addon->update(['addon_targets' => ['module:Proxmox']]);
        $this->assertFalse($addon->fresh()->appliesTo($parent));
        $addon->update(['addon_targets' => ['module:Proxmox', 'product:' . $parent->product_id]]);
        $this->assertTrue($addon->fresh()->appliesTo($parent));
    }

    public function test_setup_product_addons_lists_the_addons_and_products_the_rest(): void
    {
        $plain = $this->createProduct()->product;
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $this->livewireAs($this->staff());

        Livewire::test(ListProductAddons::class)->assertCanSeeTableRecords([$addon->product])->assertCanNotSeeTableRecords([$plain]);
        Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$plain])->assertCanNotSeeTableRecords([$addon->product]);
    }

    public function test_a_product_addon_made_from_its_page_is_an_addon(): void
    {
        $this->livewireAs($this->staff());
        $vm = $this->createProduct()->product;

        Livewire::test(CreateProductAddon::class)
            ->fillForm(['name' => 'More RAM', 'slug' => 'more-ram', 'category_id' => $vm->category_id, 'allow_quantity' => 'separated',
                'addon_targets' => ['product:' . $vm->id], 'plan' => [['name' => 'Monthly', 'type' => 'free']]])
            ->call('create')->assertHasNoFormErrors();

        $made = Product::query()->where('slug', 'more-ram')->sole();
        $this->assertTrue($made->is_addon);
        $this->assertNull($made->server_id);
        $this->assertSame(['product:' . $vm->id], $made->addon_targets);
    }

    public function test_the_parents_page_lists_its_addons(): void
    {
        $parent = $this->parent();
        $this->addon($parent);

        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $parent->getRouteKey()])->assertOk();
        $this->livewireAs($this->user)->test(Show::class, ['service' => $parent])->assertSee('Extra IPv4');
    }

    public function test_the_customers_list_shows_addons_under_their_parent(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $this->livewireAs($this->user)->test(Index::class)
            ->assertViewHas('services', fn ($services) => collect($services->items())->pluck('id')->all() === [$parent->id])
            ->set('search', 'Extra')
            ->assertViewHas('services', fn ($services) => in_array($addon->id, collect($services->items())->pluck('id')->all(), true));
    }

    /** Code review 4 of #634: an addon paid ahead ends with its parent, and staff are told to refund or credit it. */
    public function test_an_addon_paid_ahead_ended_with_its_parent_is_told_to_staff(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent, '2027-03-01');

        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);

        $parent->markTerminated();

        $this->assertSame(Service::STATUS_TERMINATED, $addon->fresh()->status);
        $this->assertTrue(EmailLog::query()->where('subject', 'like', '%Addons ended with their service%')
            ->where('body', 'like', "%Addon #{$addon->id} was paid until 2027-03-01%")->exists());
    }

    /** Code review 6 of #634: a payment that saw its parent suspended, the parent back meanwhile: it comes back too. */
    public function test_an_addon_left_with_a_parent_that_came_back_meanwhile_comes_back(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent, status: 'suspended');
        $addon->forceFill(['suspend_reason' => Service::SUSPEND_REASON_PARENT])->save();

        AddonCascade::settle($addon->id);

        $this->assertSame(Service::STATUS_ACTIVE, $addon->fresh()->status);
    }

    /** Code review 7 of #634: an addon with its own request already: the parent's request goes through, its own is kept. */
    public function test_an_addon_with_its_own_request_keeps_it_and_the_parents_goes_through(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);
        ServiceCancellation::query()->create(['service_id' => $addon->id, 'type' => 'immediate', 'reason' => 'Only the IP']);

        ServiceCancellation::query()->create(['service_id' => $parent->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        $this->assertSame('immediate', ServiceCancellation::query()->where('service_id', $addon->id)->sole()->type);
        $this->assertTrue(ServiceCancellation::query()->where('service_id', $parent->id)->exists());
    }

    /** Code review 11 of #634: Product Groups → Products lists products, not addons (theirs is another page). */
    public function test_product_groups_list_no_addons(): void
    {
        $plain = $this->createProduct()->product;
        $addon = $this->addon($this->parent());
        $addon->product->update(['category_id' => $plain->category_id]);
        $this->livewireAs($this->staff());

        Livewire::test(ProductsRelationManager::class, ['ownerRecord' => $plain->category, 'pageClass' => EditCategory::class])
            ->assertCanSeeTableRecords([$plain])->assertCanNotSeeTableRecords([$addon->product]);
    }
}
