<?php

namespace Tests\Feature;

use App\Admin\Resources\OrderResource\Pages\ListOrders;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Money\MoneyCheck;
use App\Services\Service\DeleteService;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Gateways\Stripe\Stripe;
use MyCS\Extensions\Others\DiscordNotifications\DiscordNotifications;
use Tests\TestCase;

/**
 * Adversarial review of PR #82 (one way to delete a service; an order with services is not deleted).
 */
class ServiceOrderDeleteReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 03:00:00');
        Queue::fake();
        Mail::fake();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.credits_enabled' => true, 'settings.credits_auto_use' => false]);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct(['stock' => 5]);
        $user = User::factory()->create();
        $order = Order::query()->create(['client_id' => $user->client()->id, 'currency_code' => 'USD']);

        return Service::factory()->create(array_merge([
            'client_id' => $user->client()->id, 'order_id' => $order->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-11-15',
        ], $attributes));
    }

    private function renewal(Service $service): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-15']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10, 'period_start' => '2026-11-15', 'period_end' => '2026-12-15']);

        return $invoice->fresh();
    }

    /**
     * R1 (high). A running service with a gateway subscription is deleted: the subscription is not cancelled (only a
     * status change to ended does it, endedBySave; DeleteService never sets one, not even with $terminate). Stripe keeps
     * charging the customer, and the webhook finds no service: the money is recorded nowhere.
     */
    public function test_r1_deleting_a_service_cancels_its_gateway_subscription(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/*' => Http::response(['id' => 'sub_123'])]);
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
        $service = $this->service(['subscription_id' => 'sub_123']);
        $service->properties()->create(['key' => 'has_stripe_subscription', 'name' => 'Subscription', 'value' => '1']);

        (new DeleteService)->handle($service->fresh());

        $this->assertNull(Service::find($service->id));
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/subscriptions/sub_123'));
    }

    /**
     * R2. A pending service (never provisioned) with «also delete on the server» — the bulk action's checkbox is ticked by
     * default — is sent to terminateServer for an account that never existed; the server refuses and the record cannot be
     * deleted. EditService hides the checkbox for pending; DeleteService does not skip it.
     */
    public function test_r2_a_pending_service_is_not_terminated_on_the_server(): void
    {
        require_once __DIR__ . '/../Fixtures/FalseServer.php';
        $service = $this->service(['status' => Service::STATUS_PENDING]);
        $server = Server::query()->create(['name' => 'Legacy', 'extension' => 'FalseServer', 'type' => 'server', 'enabled' => true]);
        $service->product->update(['server_id' => $server->id]);

        (new DeleteService)->handle($service->fresh(), terminate: true);

        $this->assertNull(Service::find($service->id), 'an order never provisioned has no account to terminate');
        $this->assertSame(6, (int) $service->product->fresh()->stock);
    }

    /**
     * R3. The open invoices are cancelled before the service is locked; under the lock nothing is read again. A renewal
     * the cron bills in between (the service is still active) stays open and payable for a service that is gone.
     */
    public function test_r3_a_renewal_billed_between_the_cancellation_and_the_delete_is_not_left_open(): void
    {
        $service = $this->service();
        $this->renewal($service);
        // The cron's renewal, committed right before the delete takes the service's lock (billRenewal locks the service
        // too: once the delete holds it, the cron waits, then finds no service). Read under the lock, it is cancelled.
        $billed = false;
        DB::listen(function ($query) use ($service, &$billed) {
            if (!$billed && str_contains(strtolower($query->sql), 'for update') && preg_match('/from [`"]services[`"]/', $query->sql)) {
                $billed = true;
                $this->renewal($service);
            }
        });

        (new DeleteService)->handle($service->fresh());

        $this->assertNull(Service::find($service->id));
        $this->assertSame(0, Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)
            ->whereHas('items', fn ($query) => $query->where('reference_type', Service::class)->where('reference_id', $service->id))->count(),
            'an open invoice billing a deleted service');
    }

    /**
     * R4. An invoice left open for staff (gateway money on it) bills a service that is now deleted: the money check's
     * nets (open_for_cancelled, paid_for_cancelled) look only at services that exist and ended; it is seen by none.
     */
    public function test_r4_the_money_check_sees_an_open_invoice_of_a_deleted_service(): void
    {
        $this->markTestSkipped('Fixed upstream of this case: a service whose open invoice holds money is not deleted (DeleteService refuses, see test_holds_money_refuses_the_delete).');
        $service = $this->service();
        $invoice = $this->renewal($service);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 4.00, null, 'pi_r4', currency: 'usd');

        $left = (new DeleteService)->handle($service->fresh());

        $this->assertNotSame([], $left, 'left open for staff');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $findings = array_filter((new MoneyCheck)->run(), fn ($finding) => $finding['subject'] === "invoice #{$invoice->id}");
        $this->assertNotSame([], $findings, 'an open invoice for a deleted service, holding money, is reported by no rule');
    }

    /**
     * R5. The customer pays the rest of that invoice (it is still open and payable in the client area): ProcessPaidInvoice
     * skips a line whose service is gone, silently, and clears the apply marker. An ended service gets «Payment for an ended
     * service: needs staff attention»; a deleted one gets nothing: the money bought nothing and nobody is told.
     */
    public function test_r5_a_payment_for_a_deleted_service_is_reported_to_staff(): void
    {
        $this->markTestSkipped('Fixed upstream of this case: a service whose open invoice holds money is not deleted (DeleteService refuses, see test_holds_money_refuses_the_delete).');
        $service = $this->service();
        $invoice = $this->renewal($service);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 4.00, null, 'pi_r5a', currency: 'usd');
        (new DeleteService)->handle($service->fresh());

        ExtensionHelper::addPayment($invoice->id, 'Stripe', 6.00, null, 'pi_r5b', currency: 'usd');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertTrue(EmailLog::query()->where('subject', 'like', '%attention%')->where('body', 'like', "%#{$invoice->id}%")->exists(),
            'a payment for a deleted service, applied to nothing, told to no one');
    }

    /**
     * R6. Orders bulk delete: the `using` override drops the orders with services silently, and Filament still reports
     * success («Deleted»). The default action already counted them as failures (the model's deleting guard throws).
     */
    public function test_r6_the_orders_bulk_delete_reports_the_orders_it_skipped(): void
    {
        $service = $this->service();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);

        $this->livewireAs($admin)->test(ListOrders::class)
            ->callTableBulkAction('delete', [$service->order])
            ->assertNotNotified(__('filament-actions::delete.multiple.notifications.deleted.title'));

        $this->assertNotNull(Order::find($service->order_id));
    }

    /**
     * R7 (MariaDB only: SQLite runs the cascades in an order that passes). A customer without money history (an unpaid
     * first order) is deleted by staff: users -> orders and users -> services both cascade, and orders now RESTRICT on
     * services. On MariaDB 10.11 the users delete fails with SQLSTATE 1451 (a 500 on EditUser, which checks only money
     * history). Either it goes (the services through DeleteService first) or it is refused with a reason, not a raw
     * foreign-key error. Run: DB_CONNECTION=mariadb.
     */
    public function test_r7_deleting_a_customer_with_a_service_is_not_a_foreign_key_error(): void
    {
        $service = $this->service(['status' => Service::STATUS_PENDING]);

        try {
            Client::query()->findOrFail($service->client_id)->delete();
        } catch (DisplayException) {
            $this->assertNotNull(Client::find($service->client_id));

            return; // refused with a reason: fine
        } catch (QueryException $e) {
            $this->fail('a raw foreign-key error: ' . $e->getMessage());
        }
        $this->assertNull(Client::find($service->client_id));
        $this->assertNull(Order::find($service->order_id));
    }

    /** R4/R5 fixed: the delete is refused while an open invoice of the service holds money from a gateway. */
    public function test_holds_money_refuses_the_delete(): void
    {
        $service = $this->service();
        $invoice = $this->renewal($service);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 4.00, null, 'pi_refuse', currency: 'usd');

        try {
            (new DeleteService)->handle($service->fresh());
            $this->fail('deleted with money on its open invoice');
        } catch (DisplayException) {
        }

        $this->assertNotNull(Service::find($service->id));
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /**
     * N1 (re-review). The message says «refund it or move it to credit first», but holdsMoney() counts any Succeeded
     * gateway payment, whatever was refunded or moved to credit (a refund keeps the status Succeeded and raises
     * refunded_amount / credited_amount). After staff do exactly that, the service still cannot be deleted.
     */
    public function test_n1_after_the_payment_is_moved_to_credit_the_service_can_be_deleted(): void
    {
        $service = $this->service();
        $invoice = $this->renewal($service);
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 4.00, null, 'pi_n1', currency: 'usd');
        ExtensionHelper::refundToCredit($invoice->transactions()->firstOrFail(), 4.00);

        (new DeleteService)->handle($service->fresh());

        $this->assertNull(Service::find($service->id));
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
    }

    /**
     * N2 (re-review). The refusal under the lock ($left !== []) comes after the server account was terminated and the
     * gateway subscription cancelled. CancelOpenInvoices leaves an invoice open for reasons other than money (an
     * imported WHMCS line whose item is not known, a period already delivered, other services plus loose lines, a rest
     * of nothing), which holdsMoney() does not pre-check: the service is refused with «holds a payment» (not true),
     * stays active, and its subscription (and, with $terminate, its account) is already gone.
     */
    public function test_n2_a_refused_delete_has_not_cancelled_the_subscription_first(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/*' => Http::response(['id' => 'sub_n2'])]);
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();
        $service = $this->service(['subscription_id' => 'sub_n2']);
        $service->properties()->create(['key' => 'has_stripe_subscription', 'name' => 'Subscription', 'value' => '1']);
        $invoice = $this->renewal($service);
        // An imported WHMCS line (a domain) the importer could not type: CancelOpenInvoices leaves the invoice open.
        $invoice->items()->create(['reference_type' => null, 'reference_id' => 999999, 'description' => 'Domain renewal', 'quantity' => 1, 'price' => 12]);

        try {
            (new DeleteService)->handle($service->fresh());
        } catch (DisplayException) {
            $this->assertNotNull(Service::find($service->id));
            Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/subscriptions/sub_n2'));
            $this->assertSame('sub_n2', $service->fresh()->subscription_id, 'refused, but its subscription is cancelled already');

            return;
        }
        $this->assertNull(Service::find($service->id));
    }

    /**
     * D1 (low, dry run). The dry run's rolled-back cancellation fires the synchronous model events (InvoiceObserver
     * `event(new Invoice\Updated)`, InvoiceItem/AdjustmentNote/ServiceUpgrade events) as if it had happened. Listeners
     * that act outside the database must wait for the commit: DiscordNotifications posted «Invoice Updated» (status
     * cancelled) for an invoice that stays unpaid when the delete is refused. Fixed in Σ4: Discord sends after commit.
     */
    public function test_d1_a_refused_delete_announces_no_cancellation(): void
    {
        $service = $this->service();
        $plain = $this->renewal($service);
        $withMoney = $this->renewal($service);
        ExtensionHelper::addPayment($withMoney->id, 'Stripe', 4.00, null, 'pi_d1', currency: 'usd');
        Http::fake(['https://discord.test/*' => Http::response([])]);
        (new DiscordNotifications(['webhook_url' => 'https://discord.test/hook', 'events' => ['Invoice Updated'], 'ping_type' => 'none']))->boot();

        try {
            (new DeleteService)->handle($service->fresh());
            $this->fail('deleted with money on an open invoice');
        } catch (DisplayException) {
        }

        $this->assertSame(Invoice::STATUS_UNPAID, $plain->fresh()->status);
        $announced = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'discord.test'))
            ->map(fn ($pair) => collect($pair[0]['embeds'][0]['fields'])->pluck('value')->implode(', '));
        $this->assertEmpty($announced->filter(fn ($fields) => str_contains($fields, 'cancelled'))->all(), 'Discord announced a cancellation the dry run rolled back');
    }

    /**
     * D2 (low, window). The dry run releases the service lock before the server and the gateway are called; a payment
     * that lands meanwhile (a customer paying the renewal while the account is being removed) makes the real run refuse,
     * after the subscription was cancelled (and, with $terminate, the account removed). The service is left running, with
     * no subscription and maybe no account: it should at least end up ended (as markTerminated), not stay active.
     */
    public function test_d2_a_payment_during_the_gateway_call_does_not_leave_the_service_running(): void
    {
        $service = $this->service(['subscription_id' => 'sub_d2']);
        $service->properties()->create(['key' => 'has_stripe_subscription', 'name' => 'Subscription', 'value' => '1']);
        $invoice = $this->renewal($service);
        Http::preventStrayRequests();
        Http::fake(['https://api.stripe.com/*' => function () use ($invoice) {
            ExtensionHelper::addPayment($invoice->id, 'Stripe', 4.00, null, 'pi_d2', currency: 'usd'); // lands meanwhile

            return Http::response(['id' => 'sub_d2']);
        }]);
        (new Stripe(['stripe_secret_key' => 'sk_test']))->boot();

        try {
            (new DeleteService)->handle($service->fresh());
        } catch (DisplayException) {
        }

        $after = Service::find($service->id);
        $this->assertTrue($after === null || $after->isEnded() || $after->subscription_id === 'sub_d2',
            'refused after its subscription was cancelled: left active, no subscription, the renewal half paid');
    }
}
