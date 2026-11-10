<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\TerminateJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Order\CancelOrder;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #634 (addons as services of their own, docs/addons.md «Βήμα 2»). Each test fails on the branch
 * as pushed for a defect found; its docblock says the scenario and what should happen.
 */
class Adv634Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 03:00:00');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function parent(?string $expires = '2026-12-01', string $status = 'active', array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'domain' => 'vm104.example.test', ...$attributes,
        ]);
    }

    private function addon(?Service $parent, ?string $expires = '2026-12-01', string $status = 'active', ?int $stock = null, array $attributes = []): Service
    {
        $product = $this->createProduct();
        $product->product->update(['name' => 'Extra IPv4', 'is_addon' => true, 'stock' => $stock]);

        return Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'parent_service_id' => $parent?->id, 'status' => $status, 'expires_at' => $expires, 'currency_code' => 'USD',
            'price' => 3.00, 'quantity' => 1, ...$attributes,
        ]);
    }

    private function openInvoiceFor(Service ...$services): Invoice
    {
        // Issued a week before the first of them was due (nothing is suspended for an invoice issued fewer than Suspend Days ago).
        $invoice = Invoice::factory()->create(['client_id' => $services[0]->client_id, 'status' => 'unpaid', 'currency_code' => 'USD',
            'created_at' => $services[0]->expires_at?->copy()->subWeek() ?? now()]);
        foreach ($services as $service) {
            $start = $service->expires_at;
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
                'quantity' => 1, 'price' => $service->price, 'period_start' => $start, 'period_end' => $start?->copy()->addMonth()]);
        }

        return $invoice;
    }

    /**
     * ADV-634-1. The parent is suspended (dunning or staff), its addon with it («Parent service suspended»). Staff then
     * suspend the addon itself from its page (Suspend server: a manual hold, e.g. abuse from its IP, and the task to do it
     * by hand). The parent is paid and comes back.
     *
     * Expected: the addon stays suspended: staff's hold is staff's, a payment of the parent never lifts it ("Not one staff
     * hold: their hold stays theirs"). Actual: placeOnHold() keeps the reason «Parent service suspended» (it clears a
     * reason only for a dunning suspension), so AddonCascade::unsuspend() brings it back and even queues an unsuspension
     * task for staff (its last by-hand task was the suspension staff asked for).
     */
    public function test_adv_634_1_staffs_hold_on_an_addon_suspended_with_its_parent_is_not_lifted_by_the_parent(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);
        $parent->applyStaffChanges(['status' => Service::STATUS_SUSPENDED]);
        $this->assertSame(Service::SUSPEND_REASON_PARENT, $addon->fresh()->suspend_reason, 'precondition: suspended with its parent');

        // Staff's «Suspend server» on the addon's page (EditService: placeOnHold, then the server action).
        $addon = $addon->fresh();
        $addon->placeOnHold();
        ExtensionHelper::suspendServer($addon);
        Queue::fake();

        $parent->fresh()->applyStaffChanges(['status' => Service::STATUS_ACTIVE]);

        $this->assertSame(Service::STATUS_SUSPENDED, $addon->fresh()->status, 'staff held it: it stays suspended');
        Queue::assertNotPushed(UnsuspendJob::class, fn (UnsuspendJob $job) => $job->service->id === $addon->id);
    }

    /**
     * ADV-634-2. Staff «Cancel Order» on an order that has the parent and its addon (an imported WHMCS order, where an
     * addon is ordered with its hosting; later the customer's own purchase). CancelOrder loads the order's services, then
     * cancels them one by one: the parent first, whose AddonCascade::end() cancels the addon and gives its stock back;
     * then the addon, from the collection read before (still pending in memory), is cancelled again and gives its stock
     * back a second time.
     *
     * Expected: one unit back (4 → 5). Actual: 6.
     */
    public function test_adv_634_2_cancelling_an_order_with_parent_and_addon_gives_the_addons_stock_back_once(): void
    {
        $order = Order::query()->create(['client_id' => $this->user->client()->id, 'currency_code' => 'USD']);
        $parent = $this->parent(null, 'pending', ['order_id' => $order->id]);
        $addon = $this->addon($parent, null, 'pending', stock: 4, attributes: ['order_id' => $order->id]);

        CancelOrder::handle($order->fresh());

        $this->assertSame(Service::STATUS_CANCELLED, $addon->fresh()->status);
        $this->assertSame(5, $addon->product->fresh()->stock, 'one unit taken, one given back');
    }

    /**
     * ADV-634-3a. Ids: an addon can have a lower id than its parent (staff fasten an older service to a newer one; the
     * importer's own ids). Both overdue, on the same renewal. The cron suspends in id order: the addon first, for its own
     * invoice («Overdue on Payment», dunning marker, a SuspendJob: a task for staff to suspend it by hand and a
     * «suspended» mail to the customer), then its parent.
     *
     * Expected: as with a higher id (AddonsTest::test_the_cron_suspends_the_addon_with_its_parent_and_asks_its_server_nothing):
     * suspended with its parent, nothing asked of staff for it. The outcome must not depend on the order of the ids.
     */
    public function test_adv_634_3a_an_addon_with_a_lower_id_is_suspended_with_its_parent_not_on_its_own(): void
    {
        Queue::fake();
        $addon = $this->addon(null, '2026-11-01');
        $parent = $this->parent('2026-11-01');
        $addon->update(['parent_service_id' => $parent->id]);
        $this->openInvoiceFor($parent, $addon);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_SUSPENDED, $parent->fresh()->status);
        $addon->refresh();
        $this->assertSame(Service::SUSPEND_REASON_PARENT, $addon->suspend_reason);
        $this->assertNull($addon->dunning_suspended_at);
        Queue::assertNotPushed(SuspendJob::class, fn (SuspendJob $job) => $job->service->id === $addon->id);
    }

    /**
     * ADV-634-3b. Same root cause, at the end of the period: the parent's request is its addon's too (with_parent), both
     * due today, the addon with the lower id. processCancellations() ends the addon first, on its own: a TerminateJob,
     * i.e. a task for staff to take the addon off by hand and a «service cancelled» mail of its own; then the parent.
     *
     * Expected: as with a higher id (AddonsTest::test_an_addon_paid_further_ends_the_day_its_parent_does): ended with
     * its parent, no task for it (the parent's termination covers its machine).
     */
    public function test_adv_634_3b_an_addon_with_a_lower_id_ends_with_its_parent_at_the_end_of_the_period(): void
    {
        Queue::fake();
        $addon = $this->addon(null, '2026-11-20');
        $parent = $this->parent('2026-11-20');
        $addon->update(['parent_service_id' => $parent->id]);
        ServiceCancellation::query()->create(['service_id' => $parent->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(Service::STATUS_CANCELLED, $parent->fresh()->status);
        $this->assertSame(Service::STATUS_CANCELLED, $addon->fresh()->status);
        Queue::assertNotPushed(TerminateJob::class, fn (TerminateJob $job) => $job->service->id === $addon->id);
    }

    /**
     * ADV-634-4. The customer asks once to cancel a service with two addons at the end of its period. requestWithParent()
     * saves a ServiceCancellation per addon through the model: each fires ServiceCancellation\Created, so the customer
     * gets a «cancellation received» mail per addon (and staff a chat notice per addon).
     *
     * Expected: one mail for the one request (it can name the addons, as the form does). Actual: three.
     */
    public function test_adv_634_4_one_cancellation_request_is_one_mail_to_the_customer(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
        $parent = $this->parent();
        $this->addon($parent);
        $this->addon($parent);

        ServiceCancellation::query()->create(['service_id' => $parent->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);

        $this->assertSame(1, EmailLog::query()->where('template_key', 'service_cancellation_received')->count());
    }

    /**
     * ADV-634-5. «Same currency as its parent, always» (docs/addons.md). keepAddonWithParent() only reads the addon's
     * own save: the parent's currency changed (staff's edit, the admin API's `currency_code`, which UpdateServiceRequest
     * accepts) leaves its addons in the old one, so their renewals are never on the parent's invoice again.
     *
     * Expected: refused (or the addons follow). Actual: the parent in EUR, its addon in USD.
     */
    public function test_adv_634_5_the_parents_currency_cannot_leave_its_addons_in_another(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent);

        try {
            $parent->applyStaffChanges(['currency_code' => 'EUR']);
        } catch (DisplayException) {
            // refused: fine
        }

        $this->assertSame($parent->fresh()->currency_code, $addon->fresh()->currency_code);
    }

    /**
     * ADV-634-6. A pending addon (staff fastened it and billed it; step 3's «Add addon» will do so for every addon) is
     * paid. RenewServiceService starts it only through CreateJob when hasServerActions(), which is false for an addon
     * (provisioningServer() is null): it turns active and nobody is told to add the IP, the disk or the RAM on the
     * parent's machine.
     *
     * Expected (docs/addons.md, Server: «κάθε ενέργεια ενός addon (create, suspend, unsuspend, terminate) γίνεται εργασία
     * για το staff»): a «create» task for staff in Pending Module Actions, naming its parent. Actual: none.
     */
    public function test_adv_634_6_a_paid_pending_addon_gives_staff_its_create_task(): void
    {
        $parent = $this->parent();
        $addon = $this->addon($parent, null, 'pending');
        $invoice = $this->openInvoiceFor($addon);

        (new RecordPaymentService)->handle($invoice, null, '3.00', transactionId: 'adv-634-6');

        $this->assertSame(Service::STATUS_ACTIVE, $addon->fresh()->status);
        $task = ModuleAction::query()->where('service_id', $addon->id)->where('action', 'create')->first();
        $this->assertNotNull($task, 'staff are told to add it on its parent\'s machine');
        $this->assertSame(ModuleAction::SUBJECT_BY_HAND, $task->subject);
    }

    /**
     * ADV-634-7. A pending addon paid while its parent is suspended. RenewServiceService's «stays suspended with it» only
     * reads an addon already suspended: a pending one turns active under a suspended parent, and stays active when the
     * parent is later unsuspended or kept suspended for months.
     *
     * Expected (docs/addons.md «Πληρωμή addon όσο η γονική είναι σε αναστολή»): paid, it waits suspended with its parent
     * («Parent service suspended») and comes back with it.
     */
    public function test_adv_634_7_a_pending_addon_paid_while_its_parent_is_suspended_waits_with_it(): void
    {
        Queue::fake();
        $parent = $this->parent('2026-11-01', 'suspended');
        $addon = $this->addon($parent, null, 'pending');
        $invoice = $this->openInvoiceFor($addon);

        (new RecordPaymentService)->handle($invoice, null, '3.00', transactionId: 'adv-634-7');

        $addon->refresh();
        $this->assertSame(Service::STATUS_SUSPENDED, $addon->status);
        $this->assertSame(Service::SUSPEND_REASON_PARENT, $addon->suspend_reason);
    }

    /**
     * ADV-634-8. Parent and addon both overdue, each on its own invoice (different due dates). The cron suspends the
     * parent first and the addon with it («Parent service suspended», no dunning marker), so the addon's own dunning
     * never starts. The customer pays the parent only.
     *
     * Expected: the addon is still unpaid past Suspend Days: it stays suspended, now for its own invoice («εκτός αν ένα
     * addon είχε ανασταλεί για δικό του απλήρωτο»). Actual: it comes back active with its parent, unpaid, until a later
     * run suspends it again (its own SuspendJob: a by-hand task and a mail), and its termination clock starts over.
     */
    public function test_adv_634_8_an_addon_unpaid_on_its_own_is_not_brought_back_by_its_parents_payment(): void
    {
        Queue::fake();
        $parent = $this->parent('2026-11-01');
        $addon = $this->addon($parent, '2026-11-05');
        $parentInvoice = $this->openInvoiceFor($parent);
        $this->openInvoiceFor($addon);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Service::SUSPEND_REASON_PARENT, $addon->fresh()->suspend_reason, 'precondition: suspended with its parent');

        (new RecordPaymentService)->handle($parentInvoice, null, '10.00', transactionId: 'adv-634-8');

        $this->assertSame(Service::STATUS_ACTIVE, $parent->fresh()->status);
        $this->assertSame(Service::STATUS_SUSPENDED, $addon->fresh()->status, 'its own invoice is still overdue');
    }

    /**
     * ADV-634-9. An addon fastened to a service that already has an end-of-period request (the request was made before;
     * staff's «Addon of» lists it). requestWithParent() ran only when the request was made, so the new addon has none: the
     * cron bills its next period, past the day the parent ends (and a saved card pays it), and AddonCascade::end() then
     * ends it with that period paid and not delivered.
     *
     * Expected: an addon of a service that is ending is not billed past that end (it takes the request, as the others
     * did). Actual: a renewal invoice for 2026-11-25 → 2026-12-25 while its parent ends on 2026-12-01.
     */
    public function test_adv_634_9_an_addon_fastened_to_an_ending_service_is_not_billed_past_its_end(): void
    {
        Queue::fake();
        $parent = $this->parent('2026-12-01');
        ServiceCancellation::query()->create(['service_id' => $parent->id, 'type' => 'end_of_period', 'reason' => 'Leaving']);
        $addon = $this->addon($parent, '2026-11-25');

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertFalse($addon->invoices()->whereIn('invoices.status', Invoice::OPEN_STATUSES)->exists(), 'not billed past its parent\'s end');
    }

    /**
     * ADV-634-10. The addon alone is overdue (its parent is paid up): the cron suspends it and queues its SuspendJob; the
     * customer pays before the worker reaches it. SuspendJob then finds it active and marks «never suspended», and
     * UnsuspendJob skips an offline machine's task on that mark (review of #443) only through ExtensionHelper::isOffline(),
     * which is false for an addon (no provisioning server): staff get a task to «unsuspend» an addon nobody suspended.
     *
     * Expected: as for an offline module, no task for an unsuspension of what was never suspended. Actual: an open
     * «unsuspend» task in Pending Module Actions.
     */
    public function test_adv_634_10_no_unsuspend_task_for_an_addon_never_suspended_on_its_machine(): void
    {
        Queue::fake();
        $parent = $this->parent('2026-12-20');
        $addon = $this->addon($parent, '2026-11-01');
        $invoice = $this->openInvoiceFor($addon);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame('Overdue on Payment', $addon->fresh()->suspend_reason, 'precondition: suspended for its own invoice');

        (new RecordPaymentService)->handle($invoice, null, '3.00', transactionId: 'adv-634-10');

        // The worker runs them in their order: the suspension (now moot), then the unsuspension.
        Queue::pushed(SuspendJob::class, fn (SuspendJob $job) => $job->service->id === $addon->id)->each(fn (SuspendJob $job) => $job->handle());
        Queue::pushed(UnsuspendJob::class, fn (UnsuspendJob $job) => $job->service->id === $addon->id)->each(fn (UnsuspendJob $job) => $job->handle());

        $this->assertFalse(ModuleAction::query()->where('service_id', $addon->id)->exists(), 'nothing was done on its machine, nothing to undo');
    }
}
