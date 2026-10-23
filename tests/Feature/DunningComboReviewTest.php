<?php

namespace MyCS\Extensions\Servers\ComboServer {

    use App\Classes\Extension\Server;
    use App\Models\Service;
    use RuntimeException;

    /** A server that keeps its account's state and can be unreachable for a while. */
    class ComboServer extends Server
    {
        public static bool $suspended = false;

        public static bool $unreachable = false;

        /** @var list<string> */
        public static array $calls = [];

        public function getConfig($values = []): array
        {
            return [];
        }

        public function suspendServer(Service $service, $settings, $properties)
        {
            self::$calls[] = 'suspend';
            if (self::$unreachable) {
                throw new RuntimeException('Server unreachable');
            }
            self::$suspended = true;

            return true;
        }

        public function unsuspendServer(Service $service, $settings, $properties)
        {
            self::$calls[] = 'unsuspend';
            if (self::$unreachable) {
                throw new RuntimeException('Server unreachable');
            }
            self::$suspended = false;

            return true;
        }
    }
}

namespace Tests\Feature {

    use App\Jobs\Server\SuspendJob;
    use App\Jobs\Server\UnsuspendJob;
    use App\Mail\Mail;
    use App\Models\CronStat;
    use App\Models\Invoice;
    use App\Models\Server;
    use App\Models\Service;
    use App\Models\Setting;
    use App\Models\User;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Mail as MailFacade;
    use Illuminate\Support\Facades\Queue;
    use MyCS\Extensions\Servers\ComboServer\ComboServer;
    use RuntimeException;
    use Tests\TestCase;

    /**
     * Adversarial review of #411 + #418 together: the hourly run suspends at 10:05, the customer pays at 10:06, the worker
     * gets to the jobs afterwards. Each test asserts the expected behaviour (both failed on a0a8267a).
     */
    class DunningComboReviewTest extends TestCase
    {
        use RefreshDatabase;

        protected function setUp(): void
        {
            parent::setUp();
            ComboServer::$suspended = false;
            ComboServer::$unreachable = false;
            ComboServer::$calls = [];
            MailFacade::fake();
            config(['settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 14, 'settings.cronjob_invoice' => 0,
                'settings.credits_enabled' => false, 'settings.cronjob_dunning_days' => [1, 2, 3, 4, 5], 'settings.cronjob_dunning_from' => '10:00',
                'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        }

        protected function tearDown(): void
        {
            Carbon::setTestNow();
            parent::tearDown();
        }

        /** @return list<string> template keys of the mails queued so far */
        private function queuedKeys(): array
        {
            return MailFacade::queued(Mail::class)->map(fn (Mail $mail) => $mail->templateKey)->values()->all();
        }

        /** Monday 10:05, the hourly run's hour; the night's daily run reached the suspensions. Overdue since 20/10. */
        private function overdue(bool $withServer): array
        {
            Carbon::setTestNow('2026-10-26 10:05:00');
            Setting::updateOrCreate(['key' => 'cron_dunning_ready_at', 'settingable_type' => CronStat::class],
                ['value' => '2026-10-26 00:00:05', 'type' => 'string']);
            $product = $this->createProduct();
            if ($withServer) {
                $server = Server::query()->create(['name' => 'Combo', 'extension' => 'ComboServer', 'type' => 'server', 'enabled' => true]);
                $product->product->update(['server_id' => $server->id]);
            }
            $user = User::factory()->create();
            $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
                'status' => 'active', 'expires_at' => '2026-10-20', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1]);
            $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-10-20']);
            $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal',
                'quantity' => 1, 'price' => 10.00, 'period_start' => '2026-10-20', 'period_end' => '2026-11-20']);

            return [$service, $invoice];
        }

        /**
         * F1 (#418, UnsuspendJob): the mark was pulled before the server was asked. The payment's unsuspension fails once
         * and is retried: the retry must still send no «unsuspended» for a suspension the customer never got.
         */
        public function test_f1_a_retried_unsuspension_still_sends_no_mail_for_a_suspension_never_done(): void
        {
            [$service, $invoice] = $this->overdue(withServer: true);
            Queue::fake();
            $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);
            $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);

            Carbon::setTestNow('2026-10-26 10:06:00');
            $invoice->fresh()->transactions()->create(['amount' => $invoice->fresh()->remaining]);
            $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);

            // The worker: the cron's suspension first (active: skipped, marked), then the payment's unsuspension.
            Queue::pushed(SuspendJob::class)->sole()->withFakeQueueInteractions()->handle();
            $unsuspend = Queue::pushed(UnsuspendJob::class)->sole();
            ComboServer::$unreachable = true;
            try {
                $unsuspend->withFakeQueueInteractions()->handle();
                $this->fail('unreachable');
            } catch (RuntimeException) {
            }
            ComboServer::$unreachable = false;
            $retry = $unsuspend->withFakeQueueInteractions();
            $retry->job->attempts = 2;
            $retry->handle();

            $this->assertFalse(ComboServer::$suspended);
            $this->assertNotContains('server_suspended', $this->queuedKeys(), 'fixture: never suspended, never told');
            $this->assertNotContains('server_unsuspended', $this->queuedKeys(), 'told «unsuspended» of a suspension it never got');
        }

        /**
         * F2 (finding 11 of #411): a service without a server. Its «unsuspended» mail was sent right after the payment's
         * commit, before the worker reached the cron's SuspendJob, which then told nothing. Now through the queue too.
         */
        public function test_f2_a_serverless_service_paid_before_the_worker_gets_no_unsuspended_mail(): void
        {
            [$service, $invoice] = $this->overdue(withServer: false);
            Queue::fake([SuspendJob::class, UnsuspendJob::class]);
            $this->artisan('app:cron-job', ['--dunning-only' => true])->assertExitCode(0);
            $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status);

            Carbon::setTestNow('2026-10-26 10:06:00');
            $invoice->fresh()->transactions()->create(['amount' => $invoice->fresh()->remaining]);
            $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);
            // The worker, in order: the cron's suspension, then the payment's unsuspension.
            Queue::pushed(SuspendJob::class)->sole()->withFakeQueueInteractions()->handle();
            Queue::pushed(UnsuspendJob::class)->sole()->withFakeQueueInteractions()->handle();

            $this->assertNotContains('server_suspended', $this->queuedKeys(), 'fixture: the worker came after the payment');
            $this->assertNotContains('server_unsuspended', $this->queuedKeys(), 'told «unsuspended» of a suspension it never got');
        }
    }
}
