<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\SuspendJob;
use App\Jobs\Server\UnsuspendJob;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    /** The payment's own unsuspension, run after the suspension's job settled it: not sent twice, no second mail. */
    public function test_an_unsuspension_already_done_is_not_sent_again(): void
    {
        [$service, $invoice] = $this->suspendedService();
        $this->fakeWhm(fn () => (new RecordPaymentService)->handle($invoice, null, 10, transactionId: 'race-wire-2'));
        (new SuspendJob($service, false))->handle();
        $this->assertSame(['suspend', 'unsuspend'], $this->completed);

        (new UnsuspendJob($service->fresh()))->handle(); // the payment's job, its turn come

        $this->assertSame(['suspend', 'unsuspend'], $this->completed, 'unsuspended once');
        $this->assertSame('active', Cache::get(ExtensionHelper::onServerKey($service->id)));
    }

    /** While a suspension of it runs, an unsuspension in this process is left to it (never sent alongside). */
    public function test_nothing_is_sent_while_another_call_of_the_service_holds_its_lock(): void
    {
        [$service] = $this->suspendedService();
        $this->fakeWhm();
        $service->forceFill(['status' => 'active'])->save();
        $lock = ExtensionHelper::lifecycleLock($service);
        $this->assertTrue($lock->get());

        (new UnsuspendJob($service->fresh()))->handle();
        $this->assertSame([], $this->completed, 'nothing sent while the lock is held');

        $lock->release();
    }
}
