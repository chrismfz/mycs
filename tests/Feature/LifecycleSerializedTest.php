<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Predeploy R2: one suspension or unsuspension of a service on its server at a time (ExtensionHelper::lifecycleLock), and
 * the job holding it settles the account with the service's status once its call is answered (SerializesLifecycle).
 */
class LifecycleSerializedTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> the WHM calls, in the order they completed */
    private array $completed = [];

    private bool $remoteSuspended = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
    }

    /** A cPanel service suspended for non-payment and its renewal invoice. @return array{0: Service, 1: Invoice} */
    private function suspendedService(): array
    {
        $server = Server::query()->create(['name' => 'Review WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://review-whm.test:2087', 'username' => 'root', 'apikey' => 'test-key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $client = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id,
            'product_id' => $product->product->id, 'plan_id' => $product->plan->id, 'server_id' => $server->id,
            'status' => 'suspended', 'dunning_suspended_at' => now(), 'expires_at' => today()->subDay(),
            'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        $service->properties()->create(['key' => 'cpanel_username', 'value' => 'reviewuser']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id,
            'description' => 'Renewal', 'price' => 10, 'quantity' => 1]);

        return [$service, $invoice];
    }

    /** WHM answering; $duringSuspend runs while its suspendacct is on its way (a payment coming in meanwhile). */
    private function fakeWhm(?\Closure $duringSuspend = null): void
    {
        Http::fake(function ($request) use ($duringSuspend) {
            if (str_contains($request->url(), '/accountsummary')) {
                return Http::response(['metadata' => ['result' => 1]]); // whose account it is, asked first (S7-PROVISIONING-1)
            }
            if (str_contains($request->url(), '/unsuspendacct')) {
                $this->remoteSuspended = false;
                $this->completed[] = 'unsuspend';

                return Http::response(['metadata' => ['result' => 1]]);
            }
            if (str_contains($request->url(), '/suspendacct')) {
                if ($duringSuspend !== null) {
                    $run = $duringSuspend;
                    $duringSuspend = null;
                    $run();
                }
                $this->remoteSuspended = true;
                $this->completed[] = 'suspend';

                return Http::response(['metadata' => ['result' => 1]]);
            }
            throw new \RuntimeException('Unexpected mocked WHM endpoint');
        });
    }

    /**
     * The predeploy reviewer's R2 (test_payment_unsuspension_cannot_be_undone_by_an_in_flight_cpanel_suspend). Its
     * precondition asserted the bad completion order (unsuspend, then the earlier suspend): with the lock, the payment's
     * unsuspension waits for the suspension, and the suspension's job settles it once answered.
     */
    public function test_payment_unsuspension_cannot_be_undone_by_an_in_flight_cpanel_suspend(): void
    {
        [$service, $invoice] = $this->suspendedService();
        $this->fakeWhm(fn () => (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'race-wire'));

        (new SuspendJob($service, false))->handle();

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('active', $service->fresh()->status);
        $this->assertSame(['suspend', 'unsuspend'], $this->completed, 'the unsuspension came after the suspension, never before it');
        $this->assertFalse($this->remoteSuspended, 'A paid active service must not end up suspended on WHM');
    }

    /**
     * Only a job set aside is done by the holder: a status changed meanwhile with no job (staff saving it by hand) is not
     * acted on behind anyone's back, and what MyCS did last is never taken as proof (adversarial ADV-585-1, -2).
     */
    public function test_nothing_is_reconciled_without_a_job_set_aside(): void
    {
        [$service] = $this->suspendedService();
        $this->fakeWhm(fn () => Service::query()->whereKey($service->id)->update(['status' => 'active']));

        (new SuspendJob($service, false))->handle();

        $this->assertSame(['suspend'], $this->completed, 'nothing done behind anyone\'s back');
    }

    /** A job run in a request (staff's Retry) while the lock is held is refused out loud, never «nothing to do». */
    public function test_a_job_run_in_a_request_while_the_lock_is_held_is_refused(): void
    {
        [$service] = $this->suspendedService();
        $this->fakeWhm();
        $service->forceFill(['status' => 'active'])->save();
        $lock = ExtensionHelper::lifecycleLock($service);
        $this->assertTrue($lock->get());

        try {
            (new UnsuspendJob($service->fresh()))->handle();
            $this->fail('refused out loud');
        } catch (DisplayException) {
        } finally {
            $lock->release();
        }
        $this->assertSame([], $this->completed);
    }
}
