<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Service\RetryModuleAction;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #585 (one suspension or unsuspension of a service on its server at a time: lifecycleLock, the
 * onServer marker, SerializesLifecycle::reconcile). Each test fails on the PR head (73616e28) for the reason in its
 * docblock. The WHM calls are faked over HTTP (cPanel module), as in LifecycleSerializedTest.
 */
#[Group('adversarial')]
class LifecycleSerializedAdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> the WHM calls that completed, in order */
    private array $completed = [];

    /** What WHM has: the account suspended or not. */
    private bool $remoteSuspended = false;

    /** @var array<string, bool> endpoints that WHM refuses (result 0) */
    private array $refuse = [];

    /** @var array<string, \Closure> run once while that endpoint's call is on its way */
    private array $during = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['settings.mail_disable' => false, 'app.debug' => false]);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        (new EmailTemplateSeeder)->run();
        $this->fakeWhm();
    }

    private function fakeWhm(): void
    {
        Http::fake(function ($request) {
            $endpoint = str_contains($request->url(), '/unsuspendacct') ? 'unsuspendacct'
                : (str_contains($request->url(), '/suspendacct') ? 'suspendacct' : null);
            if ($endpoint === null) {
                throw new \RuntimeException('Unexpected mocked WHM endpoint ' . $request->url());
            }
            if (isset($this->during[$endpoint])) {
                $run = $this->during[$endpoint];
                unset($this->during[$endpoint]);
                $run();
            }
            if ($this->refuse[$endpoint] ?? false) {
                return Http::response(['metadata' => ['result' => 0, 'reason' => 'WHM is down for maintenance']]);
            }
            $this->remoteSuspended = $endpoint === 'suspendacct';
            $this->completed[] = $endpoint === 'suspendacct' ? 'suspend' : 'unsuspend';

            return Http::response(['metadata' => ['result' => 1]]);
        });
    }

    /** A cPanel service with its renewal invoice (unpaid). @return array{0: Service, 1: Invoice} */
    private function whmService(string $status): array
    {
        $server = Server::query()->create(['name' => 'Review WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://review-whm.test:2087', 'username' => 'root', 'apikey' => 'test-key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $client = User::factory()->create(['email' => 'owner@example.test'])->client();
        $service = Service::factory()->create(['client_id' => $client->id,
            'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'server_id' => $server->id,
            'status' => $status, 'dunning_suspended_at' => $status === 'suspended' ? now() : null,
            'expires_at' => $status === 'suspended' ? today()->subDay() : today()->addMonth(),
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        $service->properties()->create(['key' => 'cpanel_username', 'value' => 'reviewuser']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id,
            'description' => 'Renewal', 'price' => 10, 'quantity' => 1]);
        $this->remoteSuspended = $status === 'suspended';

        return [$service, $invoice];
    }

    /** As the cron's suspendServices: suspended for non-payment, its SuspendJob run (sync: in this process). */
    private function cronSuspends(Service $service): void
    {
        $service->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now(), 'suspend_reason' => 'Overdue on Payment',
            'expires_at' => today()->subDay()])->save();
        (new SuspendJob($service->fresh()))->handle();
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->admin()->create();
    }

    /** @return list<string> */
    private function mailed(): array
    {
        return EmailLog::query()->where('to', 'owner@example.test')->orderBy('id')->pluck('template_key')->all();
    }

    /**
     * ADV-585-1. Staff «Suspend server» while a call of the service holds its lifecycle lock: EditService saves the hold
     * (placeOnHold) BEFORE it asks for the lock, then is refused («try again in a minute»). The service is left
     * suspended in MyCS while its account is active on WHM, and staff were told it failed.
     */
    public function test_a_staff_suspension_refused_by_the_lock_leaves_no_hold_behind(): void
    {
        [$service] = $this->whmService('active');
        $other = ExtensionHelper::lifecycleLock($service);
        $this->assertTrue($other->get(), 'precondition: another call of the service holds its lock');

        $this->livewireAs($this->staff())->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', ['action' => 'suspend', 'sendNotification' => false]);
        $other->release();

        $this->assertSame([], $this->completed, 'precondition: refused, nothing sent to WHM');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status,
            'refused («try again in a minute»): the service must not be left suspended in MyCS while active on WHM');
    }

    /**
     * ADV-585-1 (its consequence through reconcile). A payment's unsuspension is on its way; staff suspend it meanwhile
     * with «Send Notification» unticked and are refused. The hold saved anyway is then carried out by the unsuspension's
     * reconcile, which suspends the account and mails the customer «suspended»: an action staff were told did not
     * happen, with the email they chose not to send.
     */
    public function test_a_refused_staff_suspension_is_not_carried_out_by_reconcile_with_its_email(): void
    {
        [$service] = $this->whmService('suspended');
        $staff = $this->staff();
        $this->during['unsuspendacct'] = function () use ($service, $staff) {
            $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
                ->callAction('changeStatus', ['action' => 'suspend', 'sendNotification' => false]);
        };
        $service->forceFill(['status' => 'active', 'dunning_suspended_at' => null])->save(); // paid

        (new UnsuspendJob($service->fresh()))->handle();

        $this->assertNotContains('server_suspended', $this->mailed(),
            'staff were refused and chose no email: the customer must not be mailed «suspended»');
        $this->assertNotSame(['unsuspend', 'suspend'], $this->completed,
            'staff were told their suspension did not run: it must not be run behind their back');
    }

    /**
     * ADV-585-2. The cron's suspension attempt dies after WHM suspended the account and before its answer is recorded
     * (worker killed: OOM, the 120 s timeout, a hard restart). No failure row is written (giveUp only after the last
     * attempt) and the marker still says 'active' from the service's previous unsuspension. The payment's UnsuspendJob
     * then trusts the marker («unsuspended already») and sends nothing: the customer paid, MyCS says active, WHM keeps
     * the account suspended. The suspension's own retry finds the service active and returns; nobody is told. Before
     * #585 the unsuspension always asked WHM («a mark is no proof», review of #418).
     */
    public function test_a_killed_suspension_attempt_does_not_make_the_payments_unsuspension_a_no_op(): void
    {
        [$service, $invoice] = $this->whmService('active');
        ExtensionHelper::unsuspendServer($service); // last month's unsuspension: marker 'active' (30 days)
        $this->assertSame('active', Cache::get(ExtensionHelper::onServerKey($service->id)));

        // The cron suspends it; its job's WHM call goes through and the worker dies before anything is written.
        $service->forceFill(['status' => 'suspended', 'dunning_suspended_at' => now(), 'suspend_reason' => 'Overdue on Payment',
            'expires_at' => today()->subDay()])->save();
        $this->remoteSuspended = true;
        $this->completed = [];

        (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'adv-585-2');

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: paid, active in MyCS');
        $this->assertSame(['unsuspend'], $this->completed, 'the payment\'s unsuspension must ask WHM');
        $this->assertFalse($this->remoteSuspended, 'a paid active service must not stay suspended on WHM');
    }

    /**
     * ADV-585-3. The payment's unsuspension fails until given up; staff unsuspend the account in WHM by hand and mark
     * the action resolved (what Pending Module Actions tells them to do). The marker still says 'suspended' (30 days).
     * When the customer is suspended again within them, the cron's SuspendJob reads «suspended already, no open
     * unsuspend» and sends nothing, not even the email: an unpaid account keeps running.
     */
    public function test_an_unsuspension_done_by_hand_does_not_make_the_next_suspension_a_no_op(): void
    {
        [$service] = $this->whmService('active');
        $this->cronSuspends($service);
        $this->assertTrue($this->remoteSuspended, 'precondition: suspended on WHM');
        $this->assertSame('suspended', Cache::get(ExtensionHelper::onServerKey($service->id)));

        // Paid; its unsuspension refused by WHM until given up.
        $service->forceFill(['status' => 'active', 'dunning_suspended_at' => null, 'expires_at' => today()->addMonth()])->save();
        $this->refuse['unsuspendacct'] = true;
        try {
            (new UnsuspendJob($service->fresh()))->handle();
            $this->fail('precondition: WHM refused the unsuspension');
        } catch (Throwable) {
        }
        ModuleAction::giveUp($service, 'unsuspend');
        // Staff unsuspend it in WHM by hand and close the action.
        unset($this->refuse['unsuspendacct']);
        $this->remoteSuspended = false;
        ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'unsuspend')->sole()->markResolved(null);

        $this->travel(20)->days();
        $this->completed = [];
        EmailLog::query()->delete();
        $this->cronSuspends($service);

        $this->assertSame(['suspend'], $this->completed, 'overdue again: the cron\'s suspension must reach WHM');
        $this->assertTrue($this->remoteSuspended, 'an unpaid service must not keep running on WHM');
        $this->assertContains('server_suspended', $this->mailed(), 'and the customer is told');
    }

    /**
     * ADV-585-4. A suspension's job is answered after a payment landed; its reconcile's unsuspension is refused by WHM.
     * The failure is thrown out of the SUSPEND job, so the queue retries and finally gives up a 'suspend': staff are
     * mailed «could not suspend service» and get a Retry on a suspension of an active, paid service, while the real
     * failure, the 'unsuspend' row, stays open without given_up_at, so it has no Retry button (ModuleActionResource
     * shows Retry only once given up). Marking the bogus 'suspend' resolved leaves the paid account suspended for good.
     */
    public function test_a_reconcile_failure_is_given_up_as_the_unsuspension_it_is(): void
    {
        [$service, $invoice] = $this->whmService('suspended');
        $this->during['suspendacct'] = fn () => (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'adv-585-4');
        $this->refuse['unsuspendacct'] = true;
        $job = new SuspendJob($service, false);
        $error = null;
        try {
            $job->handle();
        } catch (Throwable $e) {
            $error = $e;
        }
        $this->assertNotNull($error, 'precondition: the reconcile\'s unsuspension was refused');
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: paid meanwhile');
        $job->failed($error); // its last attempt (the retries find it active and reconcile the same way)

        $this->assertFalse(ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'suspend')->exists(),
            'no failed «suspend» for staff to retry on a paid, active service');
        $this->assertTrue(ModuleAction::query()->open()->where('service_id', $service->id)->where('action', 'unsuspend')->whereNotNull('given_up_at')->exists(),
            'the unsuspension that failed is the one given up, so staff can Retry it');
    }

    /**
     * ADV-585-5. On a worker the job waits for the lock with block(150), but the jobs' own $timeout is 120: the worker
     * kills the job (and its process) at 120 s while it waits, so «retried 30 seconds later if it is still held» never
     * happens. The attempt is spent, the job comes back only after retry_after (300 s), and a last attempt is given up
     * («could not unsuspend ... after 4 attempts»).
     */
    public function test_a_worker_waits_for_the_lock_less_than_its_own_timeout(): void
    {
        [$service] = $this->whmService('suspended');
        $service->forceFill(['status' => 'active', 'dunning_suspended_at' => null])->save();
        Cache::extend('adv585', fn () => Cache::repository(new Adv585SpyStore));
        config(['cache.stores.adv585' => ['driver' => 'adv585'], 'cache.default' => 'adv585']);
        Cache::forgetDriver('adv585');
        Adv585SpyLock::$waits = [];

        $job = (new UnsuspendJob($service->fresh()))->withFakeQueueInteractions(); // on a worker, not the sync queue
        $job->handle();

        $this->assertNotNull(Adv585SpyLock::longestWait(), 'precondition: a worker waits for the lock');
        $this->assertLessThan($job->timeout, Adv585SpyLock::longestWait(), 'the wait must end before the worker kills the job');
    }

    /**
     * ADV-585-6. Staff's Retry (Pending Module Actions, and the MCP proposal) runs the job in the request: the lock held
     * by a worker's job, it returns without doing anything, and Retry reports false: «Nothing to do. The service is
     * active now: mark the action resolved if the account is as it should be.» The account was never asked; the true
     * answer is «busy, try again in a minute».
     */
    public function test_a_retry_held_back_by_the_lock_is_not_reported_as_nothing_to_do(): void
    {
        [$service] = $this->whmService('suspended');
        $service->forceFill(['status' => 'active', 'dunning_suspended_at' => null])->save();
        $row = ModuleAction::recordFailure($service, 'unsuspend', 'CPanel', new \RuntimeException('cPanel unsuspendacct failed'));
        $row->forceFill(['given_up_at' => now()])->save();
        $worker = ExtensionHelper::lifecycleLock($service);
        $this->assertTrue($worker->get(), 'precondition: a worker\'s job of the service holds its lock');

        try {
            $done = RetryModuleAction::run($row);
        } catch (Throwable) {
            $done = null; // refused out loud: what staff should see
        } finally {
            $worker->release();
        }

        $this->assertSame([], $this->completed, 'precondition: nothing reached WHM');
        $this->assertNotFalse($done, 'held back by the lock is not «nothing to do: mark it resolved»');
    }
}

/** An array cache whose locks record how long a worker would wait for them. */
class Adv585SpyStore extends ArrayStore
{
    public function lock($name, $seconds = 0, $owner = null)
    {
        return new Adv585SpyLock($this, $name, $seconds, $owner);
    }
}

class Adv585SpyLock extends ArrayLock
{
    /** @var list<int> */
    public static array $waits = [];

    public static function longestWait(): ?int
    {
        return self::$waits === [] ? null : max(self::$waits);
    }

    public function block($seconds, $callback = null)
    {
        self::$waits[] = (int) $seconds;

        return parent::block(0, $callback);
    }
}
