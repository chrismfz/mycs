<?php

namespace MyCS\Extensions\Servers\RacyServer {

    use App\Classes\Extension\Server;
    use App\Models\Service;
    use Closure;

    /** A server whose create runs a hook while the account is being made (something else happens meanwhile). */
    class RacyServer extends Server
    {
        public static ?Closure $duringCreate = null;

        /** @var list<string> */
        public static array $calls = [];

        public function getConfig($values = []): array
        {
            return [];
        }

        public function createServer(Service $service, $settings, $properties)
        {
            self::$calls[] = 'create';
            $hook = self::$duringCreate;
            self::$duringCreate = null; // once
            if ($hook !== null) {
                $hook($service);
            }

            return true;
        }

        public function suspendServer(Service $service, $settings, $properties)
        {
            self::$calls[] = 'suspend';

            return true;
        }

        public function unsuspendServer(Service $service, $settings, $properties)
        {
            self::$calls[] = 'unsuspend';

            return true;
        }

        public function terminateServer(Service $service, $settings, $properties)
        {
            self::$calls[] = 'terminate';

            return true;
        }
    }
}

namespace Tests\Feature {

    use App\Events\ServiceCancellation\Created as CancellationCreated;
    use App\Helpers\ExtensionHelper;
    use App\Jobs\Server\CreateJob;
    use App\Jobs\Server\TerminateJob;
    use App\Listeners\CancellationCreatedListener;
    use App\Mail\Mail as MyCSMail;
    use App\Models\Invoice;
    use App\Models\ModuleAction;
    use App\Models\Server;
    use App\Models\Service;
    use App\Models\User;
    use App\Services\Service\RenewServiceService;
    use App\Services\Service\RetryModuleAction;
    use Exception;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Facades\Queue;
    use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
    use MyCS\Extensions\Servers\RacyServer\RacyServer;
    use Tests\TestCase;

    /**
     * Adversarial review of cd52bc6 (Σ1-a): «paid but still pending» until CreateJob succeeds, and retried server jobs.
     * Every test here fails on the branch and describes what should hold.
     */
    class ModuleActionsAdversarialTest extends TestCase
    {
        use RefreshDatabase;

        private Service $service;

        private Server $server;

        protected function setUp(): void
        {
            parent::setUp();
            require_once __DIR__ . '/../Fixtures/FlakyServer.php';
            FlakyServer::$failures = 0;
            FlakyServer::$calls = [];
            RacyServer::$calls = [];
            RacyServer::$duringCreate = null;
            Queue::fake();
            Mail::fake();
            config(['settings.mail_disable' => false, 'settings.system_email_address' => 'staff@example.com']);
            $this->server = Server::query()->create(['name' => 'Flaky', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
            $product = $this->createProduct();
            $product->product->update(['server_id' => $this->server->id]);
            $user = User::factory()->create();
            $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
                'status' => 'pending', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => null]);
        }

        private function useRacyServer(): void
        {
            $this->server->update(['extension' => 'RacyServer']);
        }

        private function orderInvoice(): array
        {
            $invoice = Invoice::factory()->create(['client_id' => $this->service->client_id, 'status' => 'unpaid', 'currency_code' => 'USD']);
            $item = $invoice->items()->create(['reference_id' => $this->service->id, 'reference_type' => Service::class, 'description' => 'Order',
                'price' => 10, 'quantity' => 1]);

            return [$invoice, $item];
        }

        private function pay(): void
        {
            [$invoice, $item] = $this->orderInvoice();
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
            (new RenewServiceService)->handle($this->service->fresh(), $item);
        }

        private function welcomeMails(): int
        {
            return Mail::queued(MyCSMail::class, fn (MyCSMail $mail) => $mail->templateKey === 'new_server_created')->count();
        }

        private function failingCreate(): void
        {
            try {
                (new CreateJob($this->service->fresh()))->handle();
                $this->fail('the server should refuse');
            } catch (Exception) {
            }
        }

        /**
         * A1. The paid period runs while the account does not exist: once staff fix the server after the period, the
         * service is active with an expiry Suspend Days in the past, and the very next cron suspends it «Overdue on
         * Payment» (a renewal invoice is issued the same run, already overdue). The customer who paid and waited
         * for a month gets their account and a suspension the same day.
         */
        public function test_a_service_created_late_is_not_suspended_by_the_next_cron(): void
        {
            $this->travelTo(now()->startOfDay()->addHours(10));
            FlakyServer::$failures = 100;
            $this->pay();
            $this->failingCreate();
            $this->assertSame('pending', $this->service->fresh()->status);

            $this->travel(40)->days(); // 3 retries, then staff's; staff fix the server five weeks later
            FlakyServer::$failures = 0;
            RetryModuleAction::run(ModuleAction::query()->open()->sole());
            $this->assertSame('active', $this->service->fresh()->status);

            $this->artisan('app:cron-job');

            $this->assertSame('active', $this->service->fresh()->status,
                'just created after a paid month the server failed: suspended at once as overdue');
        }

        /** A1b. Later still (past Termination Days): created today, suspended today, terminated tomorrow. */
        public function test_a_service_created_late_is_not_terminated_the_day_after(): void
        {
            $this->travelTo(now()->startOfDay()->addHours(10));
            FlakyServer::$failures = 100;
            $this->pay();
            $this->failingCreate();

            $this->travel(100)->days();
            FlakyServer::$failures = 0;
            RetryModuleAction::run(ModuleAction::query()->open()->sole());
            $this->artisan('app:cron-job');
            $this->travel(1)->days();
            $this->artisan('app:cron-job');

            $this->assertNotSame('terminated', $this->service->fresh()->status, 'created yesterday, terminated today');
        }

        /**
         * A2. Staff «Create server» on an unpaid pending order (decision 4: Create without payment) now makes it active
         * with no expiry: the cron never bills it (expires_at null), never suspends it, and no longer cancels the
         * unpaid order (pending only). Before cd52bc6 the manual create left it pending and orders_cancelled took it.
         */
        public function test_a_manual_create_without_payment_does_not_give_a_free_service_for_ever(): void
        {
            $this->orderInvoice(); // the order's invoice, never paid
            ExtensionHelper::createServer($this->service->fresh());
            $this->assertSame('active', $this->service->fresh()->status);
            // Σ1-b: due from the order date (was null: never billed, suspended or cancelled).
            $this->assertNotNull($this->service->fresh()->expires_at);

            $this->travel(90)->days();
            $this->artisan('app:cron-job');

            $service = $this->service->fresh();
            $this->assertContains($service->status, ['suspended', 'cancelled', 'terminated'],
                "unpaid for 90 days, yet {$service->status} with expires_at " . var_export($service->expires_at, true) . ' and never billed');
        }

        /**
         * A3. TerminateJob now retries (tries 4, up to an hour+ later) but, unlike Suspend/Unsuspend/Create, checks no
         * status: a terminate that failed, retried after staff reinstated the service, deletes a live account.
         */
        public function test_a_retried_terminate_leaves_a_reinstated_service_alone(): void
        {
            $this->service->update(['status' => 'active', 'expires_at' => now()->addMonth()]);
            $this->service->update(['status' => 'terminated']); // cron/cancellation
            FlakyServer::$failures = 1;
            try {
                (new TerminateJob($this->service->fresh()))->handle();
            } catch (Exception) {
            } // server down: the account is still there; the job waits for its retry
            $this->service->update(['status' => 'active']); // customer paid, staff reinstate the service

            FlakyServer::$calls = [];
            (new TerminateJob($this->service->fresh()))->handle(); // the automatic retry, minutes later

            $this->assertNotContains('terminate', FlakyServer::$calls, 'the retry deleted the account of an active service');
        }

        /** A3b. Same through staff's «Retry» on the old open terminate row (no create/unsuspend supersedes it). */
        public function test_staff_retry_of_an_old_terminate_does_not_delete_an_active_account(): void
        {
            $this->service->update(['status' => 'terminated', 'expires_at' => now()->addMonth()]);
            FlakyServer::$failures = 1;
            try {
                (new TerminateJob($this->service->fresh()))->handle();
            } catch (Exception) {
            }
            $this->service->update(['status' => 'active']);
            ExtensionHelper::unsuspendServer($this->service->fresh()); // even a successful later action leaves it open
            $open = ModuleAction::query()->open()->where('action', 'terminate')->sole();

            FlakyServer::$calls = [];
            RetryModuleAction::run($open);

            $this->assertNotContains('terminate', FlakyServer::$calls, 'Retry terminated an active service');
        }

        /**
         * A4. Immediate cancellation while the create is in flight (CreateJob is checked for pending before the server
         * call, not after): the listener sees pending, so no TerminateJob; the create then completes. The account
         * exists on a cancelled service, nobody will remove it, and the customer gets the welcome mail.
         */
        public function test_cancelled_during_the_create_no_welcome_mail(): void
        {
            $this->useRacyServer();
            $this->pay();
            RacyServer::$duringCreate = function (Service $service) {
                $cancellation = $service->cancellation()->create(['type' => 'immediate', 'reason' => 'changed my mind']);
                (new CancellationCreatedListener)->handle(new CancellationCreated($cancellation));
            };

            (new CreateJob($this->service->fresh()))->handle();

            $this->assertSame('cancelled', $this->service->fresh()->status);
            $this->assertSame(0, $this->welcomeMails(), 'welcome mail (with the password) for a cancelled service');
        }

        public function test_cancelled_during_the_create_the_new_account_is_not_left_on_the_server(): void
        {
            $this->useRacyServer();
            $this->pay();
            RacyServer::$duringCreate = function (Service $service) {
                $cancellation = $service->cancellation()->create(['type' => 'immediate', 'reason' => 'changed my mind']);
                (new CancellationCreatedListener)->handle(new CancellationCreated($cancellation));
            };

            (new CreateJob($this->service->fresh()))->handle();

            $terminated = in_array('terminate', RacyServer::$calls, true)
                || Queue::pushed(TerminateJob::class)->isNotEmpty()
                || ModuleAction::query()->open()->where('service_id', $this->service->id)->exists();
            $this->assertTrue($terminated, 'an account created for a cancelled service: no terminate queued, nothing open for staff');
        }

        /**
         * A5. One create at a time is promised (ShouldBeUnique), but only for dispatch: staff's Retry (RetryModuleAction
         * calls handle() directly) or a second worker running while a create is in flight both pass the pending check
         * and both create. Two accounts on the server, two welcome mails.
         */
        public function test_two_creates_at_once_make_one_account(): void
        {
            $this->useRacyServer();
            $this->pay();
            RacyServer::$duringCreate = function (Service $service) {
                (new CreateJob($service->fresh()))->handle(); // staff click Retry / Trigger create while the job runs
            };

            (new CreateJob($this->service->fresh()))->handle();

            $this->assertSame(1, count(array_keys(RacyServer::$calls, 'create', true)), 'two accounts created for one service');
            $this->assertSame(1, $this->welcomeMails());
        }

        /**
         * A6. Paid while its product had a server, the product's server is removed before the job runs: CreateJob
         * swallows «No server assigned», sends the welcome mail, and leaves the paid service pending for ever — not in
         * Pending Module Actions (no row), never billed, never cancelled.
         */
        public function test_a_paid_service_whose_product_lost_its_server_does_not_stay_pending_for_ever(): void
        {
            $this->pay();
            $this->assertSame('pending', $this->service->fresh()->status);
            $this->service->product->update(['server_id' => null]);

            (new CreateJob($this->service->fresh()))->handle();

            $pending = $this->service->fresh()->status === 'pending';
            $visible = ModuleAction::query()->open()->where('service_id', $this->service->id)->exists();
            $this->assertFalse($pending && !$visible, 'paid, welcome mail sent (' . $this->welcomeMails() . '), still pending, nothing for staff');
        }
    }
}
