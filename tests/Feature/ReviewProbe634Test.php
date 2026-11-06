<?php

namespace Tests\Feature;

use App\Events\ServiceCancellation\Created;
use App\Exceptions\DisplayException;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\UnsuspendJob;
use App\Listeners\SendMailListener;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Order\CancelOrder;
use App\Support\Portal\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Independent code review of #634 (addons as services): its probes, each failing before its fix. */
class ReviewProbe634Test extends TestCase
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

    private function parent(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-12-01', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'domain' => 'vm104.example.test', ...$attributes,
        ]);
    }

    private function addon(Service $parent, array $attributes = [], ?int $stock = null): Service
    {
        $product = $this->createProduct();
        $product->product->update(['name' => 'Extra IPv4', 'is_addon' => true, 'stock' => $stock]);

        return Service::factory()->create([
            'client_id' => $parent->client_id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'parent_service_id' => $parent->id, 'status' => 'active', 'expires_at' => '2026-12-01', 'currency_code' => 'USD',
            'price' => 3.00, 'quantity' => 1, ...$attributes,
        ]);
    }

    /** P1: staff's explicit hold (placeOnHold, as the API's status=suspended) on an addon suspended with its parent is lifted with the parent. */
    public function test_staff_hold_on_addon_survives_parent_unsuspend(): void
    {
        Queue::fake();
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $parent->applyStaffChanges(['status' => Service::STATUS_SUSPENDED]);
        $this->assertSame(Service::SUSPEND_REASON_PARENT, $addon->fresh()->suspend_reason);

        $addon->fresh()->placeOnHold(); // staff: hold this addon

        $parent->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);
        $this->assertSame(Service::STATUS_SUSPENDED, $addon->fresh()->status, 'staff hold kept');
    }

    /** P2: an addon in its parent's order is ended twice by CancelOrder (cascade, then the loop with a stale status): stock back twice. */
    public function test_cancel_order_with_parent_and_addon_gives_stock_back_once(): void
    {
        Queue::fake();
        $order = Order::query()->create(['client_id' => $this->client->id, 'currency_code' => 'USD']);
        $parent = $this->parent(['order_id' => $order->id]);
        $addon = $this->addon($parent, ['order_id' => $order->id], stock: 4);

        CancelOrder::handle($order);

        $this->assertSame(Service::STATUS_CANCELLED, $addon->fresh()->status);
        $this->assertSame(5, $addon->product->fresh()->stock, 'one unit back');
    }

    /** P3: the home tiles count addons as services, the list does not show them. */
    public function test_home_count_matches_list(): void
    {
        $parent = $this->parent();
        $this->addon($parent);
        $this->addon($parent);
        $this->actingAs($this->user);

        $this->assertSame(1, (new Summary($this->client))->servicesCount());
    }

    /** P4: a parent's currency changed with addons: they are left in another currency. */
    public function test_parent_currency_change_keeps_addons_currency(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);

        try {
            $parent->applyStaffChanges(['currency_code' => 'EUR']);
        } catch (DisplayException $e) {
            $this->assertSame(__('services.addons.parent_currency'), $e->getMessage()); // refused (fix of ADV-634-5)
        }

        $this->assertSame($parent->fresh()->currency_code, $addon->fresh()->currency_code);
    }

    /** P5: SuspendJob of an addon skipped (paid before the worker), then its UnsuspendJob: a task to unsuspend what nobody suspended. */
    public function test_unsuspend_task_for_never_suspended_addon(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $addon->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now(), 'suspend_reason' => 'Overdue on Payment'])->save();
        $job = new SuspendJob($addon);
        $addon->forceFill(['status' => 'active'])->save(); // paid before the worker took the suspension
        $job->handle();
        (new UnsuspendJob($addon->fresh()))->handle();

        $this->assertFalse(ModuleAction::query()->where('service_id', $addon->id)->where('action', 'unsuspend')->exists(), 'no task for a suspension never done');
    }

    /** P7: an addon ended before the move, its unpaid invoice kept as a debt: the debt goes to the new client with the parent. */
    public function test_move_leaves_ended_addons_debt_with_first_client(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent, ['status' => 'terminated']);
        $debt = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'kept_as_debt_at' => now()->subMonth()]);
        $debt->items()->create(['reference_type' => Service::class, 'reference_id' => $addon->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 3, 'period_start' => '2026-09-01', 'period_end' => '2026-10-01']);
        $to = User::factory()->create()->client();
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All' . uniqid(), 'permissions' => ['*']])->id]);

        (new MoveToClient)->service($parent, $to, $staff);

        $this->assertSame($this->client->id, $debt->fresh()->client_id, 'the first client\'s debt stays theirs');
    }

    /**
     * P6: the customer gets a «cancellation received» mail per addon copy. Each copy keeps its event (its listener
     * cancels its open invoices); the mail and the chat notice are the parent's request's only.
     */
    public function test_one_cancellation_event_per_request(): void
    {
        $parent = $this->parent();
        $this->addon($parent);
        $this->addon($parent);
        Event::fake([Created::class]);

        ServiceCancellation::query()->create(['service_id' => $parent->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        $events = Event::dispatched(Created::class)->map(fn (array $args) => $args[0]);
        $mailer = app(SendMailListener::class);
        $this->assertSame(1, $events->filter(fn ($event) => $mailer->shouldQueue($event))->count(), 'one mail for one request');
    }
}
