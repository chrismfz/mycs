<?php

namespace MyCS\Extensions\Servers\LostAnswerServer {

    use App\Classes\Extension\Server;
    use App\Models\Service;
    use RuntimeException;

    /**
     * A server that keeps its account's state: a suspension can take effect and its answer still be lost (a timeout), and
     * the server can be unreachable for a while.
     */
    class LostAnswerServer extends Server
    {
        public static bool $suspended = false;

        public static bool $loseNextAnswer = false;

        public static bool $unreachable = false;

        public function getConfig($values = []): array
        {
            return [];
        }

        public function suspendServer(Service $service, $settings, $properties)
        {
            if (self::$unreachable) {
                throw new RuntimeException('Server unreachable');
            }
            self::$suspended = true;
            if (self::$loseNextAnswer) {
                self::$loseNextAnswer = false;
                throw new RuntimeException('cURL error 28: Operation timed out'); // done there, the answer lost
            }

            return true;
        }

        public function unsuspendServer(Service $service, $settings, $properties)
        {
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
    use App\Models\ModuleAction;
    use App\Models\Server;
    use App\Models\Service;
    use App\Models\User;
    use App\Services\Service\RetryModuleAction;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Facades\Queue;
    use MyCS\Extensions\Servers\LostAnswerServer\LostAnswerServer;
    use RuntimeException;
    use Tests\TestCase;

    /**
     * Review of #418: the «never suspended» mark is put by a SuspendJob that found the service active, and read by the next
     * UnsuspendJob as «nothing on the server to undo». A suspension an EARLIER attempt did on the server (its answer
     * lost) is not «never»: the payment's unsuspension must still reach the server.
     */
    class NeverSuspendedMarkReviewTest extends TestCase
    {
        use RefreshDatabase;

        private Service $service;

        protected function setUp(): void
        {
            parent::setUp();
            LostAnswerServer::$suspended = false;
            LostAnswerServer::$loseNextAnswer = false;
            LostAnswerServer::$unreachable = false;
            Queue::fake();
            Mail::fake();
            $server = Server::query()->create(['name' => 'Lost', 'extension' => 'LostAnswerServer', 'type' => 'server', 'enabled' => true]);
            $product = $this->createProduct();
            $product->product->update(['server_id' => $server->id]);
            $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
                'product_id' => $product->product->id, 'status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => now(),
                'suspend_reason' => 'Overdue on Payment', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        }

        /** The cron's suspension: done on the server, its answer lost; retried by the queue after 60 s. */
        private function firstSuspendAttemptDoneButAnswerLost(): void
        {
            LostAnswerServer::$loseNextAnswer = true;
            try {
                (new SuspendJob($this->service))->withFakeQueueInteractions()->handle();
                $this->fail('the answer was lost');
            } catch (RuntimeException) {
            }
            $this->assertTrue(LostAnswerServer::$suspended, 'suspended on the server all the same');
        }

        private function paid(): void
        {
            $this->service->forceFill(['status' => Service::STATUS_ACTIVE, 'dunning_suspended_at' => null, 'suspend_reason' => null])->save();
        }

        /**
         * Queue retries: the server is briefly unreachable, the payment's unsuspension fails once (retried after 60 s);
         * the suspension's retry runs first (it was due first), finds the service active and puts the mark; the
         * unsuspension's retry then skips the server. The customer paid, MyCS says active, the account stays suspended,
         * and nobody hears (both jobs end «successfully», their failures left open forever).
         */
        public function test_a_suspension_done_by_an_earlier_attempt_is_still_undone_by_the_payment(): void
        {
            $this->firstSuspendAttemptDoneButAnswerLost();

            $this->paid();
            LostAnswerServer::$unreachable = true;
            try {
                (new UnsuspendJob($this->service))->withFakeQueueInteractions()->handle();
                $this->fail('unreachable');
            } catch (RuntimeException) {
            }
            LostAnswerServer::$unreachable = false;

            $suspendRetry = (new SuspendJob($this->service))->withFakeQueueInteractions();
            $suspendRetry->job->attempts = 2;
            $suspendRetry->handle();                       // active: skipped, the mark put

            $unsuspendRetry = (new UnsuspendJob($this->service))->withFakeQueueInteractions();
            $unsuspendRetry->job->attempts = 2;
            $unsuspendRetry->handle();

            $this->assertSame(Service::STATUS_ACTIVE, $this->service->fresh()->status);
            $this->assertFalse(LostAnswerServer::$suspended, 'paid and active in MyCS: the account must not stay suspended on the server');
            $this->assertSame(0, ModuleAction::query()->open()->where('service_id', $this->service->id)->count(), 'nothing left open with no one to retry it');
        }

        /**
         * Staff's Retry (Pending Module Actions, or the MCP's propose_retry_module_action): the suspend and the unsuspend
         * were both given up while the server was down. Staff retry the suspend first: «moved on» (and the mark put); then
         * the unsuspend: skipped by the mark, «moved on» again, so staff mark it resolved. The account stays suspended.
         */
        public function test_staff_retrying_a_given_up_suspend_does_not_make_the_unsuspend_retry_a_no_op(): void
        {
            $this->firstSuspendAttemptDoneButAnswerLost();
            ModuleAction::giveUp($this->service, 'suspend');

            $this->paid();
            LostAnswerServer::$unreachable = true;
            try {
                (new UnsuspendJob($this->service))->handle();
            } catch (RuntimeException) {
            }
            ModuleAction::giveUp($this->service, 'unsuspend');
            LostAnswerServer::$unreachable = false;

            $suspend = ModuleAction::query()->open()->where('action', 'suspend')->sole();
            $this->assertFalse(RetryModuleAction::run($suspend), 'the service moved on: nothing to suspend');

            $unsuspend = ModuleAction::query()->open()->where('action', 'unsuspend')->sole();
            $this->assertTrue(RetryModuleAction::run($unsuspend), 'the unsuspension must reach the server');
            $this->assertFalse(LostAnswerServer::$suspended);
        }
    }
}
