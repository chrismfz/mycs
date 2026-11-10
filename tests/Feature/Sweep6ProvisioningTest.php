<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\TerminateJob;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceCancellation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\FlakyServer\FlakyServer;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Sweep 6, provisioning (adversarial verifier):
 * - S6-PROVISIONING-1: Mailcow acts on a domain admin another live service also holds (no shared-account guard, as
 *   cPanel's / DirectAdmin's SharedAccount::otherLive).
 * - S6-PROVISIONING-2: a pending service ended between two create attempts: its half-made account is never terminated
 *   and its open «create» row is never given to staff.
 */
class Sweep6ProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://mail.test';

    /** @var array<string, array<string, mixed>> */
    private array $domains = [];

    /** @var list<array<string, mixed>> */
    private array $admins = [];

    private function mailcow(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $ok = Http::response([['type' => 'success', 'msg' => ['ok']]]);

        return match (true) {
            str_starts_with($path, '/api/v1/get/domain-admin/all') => Http::response($this->admins),
            str_starts_with($path, '/api/v1/get/domain/') => Http::response($this->domains[urldecode(basename($path))] ?? []),
            str_starts_with($path, '/api/v1/get/dkim/') => Http::response([]),
            str_starts_with($path, '/api/v1/get/mailbox/all') => Http::response([['username' => 'info@example.com']]),
            str_starts_with($path, '/api/v1/get/alias/all') => Http::response([]),
            default => $ok,
        };
    }

    /** @return list<Request> */
    private function sent(string $path): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === $path)->values()->all();
    }

    /** Two live services on one mailcow server, both on the admin «example» of example.com: B is the one acted on. */
    private function twoServicesOnOneAdmin(): array
    {
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $server = Server::query()->create(['name' => 'Mail Node', 'extension' => 'Mailcow', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => self::API, 'apikey' => 'api-key-1', 'sso_path' => '/domainadmin/'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $make = fn () => Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10,
            'expires_at' => now()->addMonth(), 'domain' => 'example.com', 'username' => 'example']);
        $a = $make();
        $b = $make();
        foreach ([$a, $b] as $service) {
            $service->properties()->create(['key' => 'mailcow_username', 'name' => 'mailcow domain admin', 'value' => 'example']);
        }
        $this->domains['example.com'] = ['domain_name' => 'example.com'];
        $this->admins = [['username' => 'example', 'selected_domains' => 'example.com', 'active' => 1]];
        Http::fake(fn (Request $request) => $this->mailcow($request));

        return [$a, $b];
    }

    /** S6-PROVISIONING-1: B's suspension locks the admin paid, active service A also uses. */
    public function test_mailcow_suspend_never_locks_an_admin_another_live_service_holds(): void
    {
        [, $b] = $this->twoServicesOnOneAdmin();

        $refused = false;
        try {
            ExtensionHelper::suspendServer($b->fresh());
        } catch (Throwable) {
            $refused = true;
        }

        $this->assertCount(0, $this->sent('/api/v1/edit/domain-admin'), "service A's domain admin locked by B's suspension");
        $this->assertTrue($refused, 'refused for staff, as cPanel and DirectAdmin do for a shared account');
    }

    /** S6-PROVISIONING-1: B's delete_all termination wipes A's live mail, admin and domain. */
    public function test_mailcow_delete_all_never_wipes_a_domain_another_live_service_holds(): void
    {
        [, $b] = $this->twoServicesOnOneAdmin();
        $b->product->settings()->create(['key' => 'terminate_mode', 'value' => 'delete_all']);

        try {
            ExtensionHelper::terminateServer($b->fresh());
        } catch (Throwable) {
            // a refusal is the right answer
        }

        $this->assertCount(0, [...$this->sent('/api/v1/delete/mailbox'), ...$this->sent('/api/v1/delete/domain-admin'), ...$this->sent('/api/v1/delete/domain')],
            "service A's mailboxes, admin and domain deleted by B's termination");
    }

    /** S6-PROVISIONING-1: a pending order with the live admin's name (WHMCS names it from its domain) never adopts it. */
    public function test_mailcow_create_never_adopts_an_admin_another_live_service_holds(): void
    {
        [$a, $b] = $this->twoServicesOnOneAdmin();
        $b->properties()->where('key', 'mailcow_username')->delete();
        $b->forceFill(['status' => 'pending'])->save();

        try {
            ExtensionHelper::createServer($b->fresh());
            $this->fail('adopted the admin of service #' . $a->id);
        } catch (Throwable $e) {
            $this->assertStringContainsString("service #{$a->id} has the domain admin «example»", $e->getMessage());
        }

        $this->assertFalse($b->properties()->where('key', 'mailcow_username')->exists());
        $this->assertCount(0, $this->sent('/api/v1/add/domain-admin'));
    }

    /** S6-PROVISIONING-1: the refusal names the other service, as cPanel's; alone on its admin, it is suspended as before. */
    public function test_mailcow_refusal_names_the_other_service_and_a_sole_service_is_still_suspended(): void
    {
        [$a, $b] = $this->twoServicesOnOneAdmin();
        try {
            ExtensionHelper::suspendServer($b->fresh());
            $this->fail('not refused');
        } catch (Throwable $e) {
            $this->assertStringContainsString("service #{$a->id} has the domain admin «example»", $e->getMessage());
        }
        $this->assertFalse($b->properties()->where('key', 'mailcow_locked_by_us')->exists());

        $a->forceFill(['status' => Service::STATUS_CANCELLED])->save();
        ExtensionHelper::suspendServer($b->fresh());
        $this->assertCount(1, $this->sent('/api/v1/edit/domain-admin'));
    }

    /**
     * S6-PROVISIONING-2: attempt 1 of a create fails (the account may exist: a timeout after the server made it); the
     * pending service is cancelled at once (no terminate queued for a pending one); attempt 2 returns as if done.
     */
    public function test_a_pending_service_ended_between_create_attempts_is_terminated_or_given_to_staff(): void
    {
        $this->markTestIncomplete('S6-PROVISIONING-2: left as is, record only (owner 2026-11-09; docs/sweeps/2026-11-09-sixth.md).');
        require_once __DIR__ . '/../Fixtures/FlakyServer.php';
        FlakyServer::$failures = 1;
        FlakyServer::$calls = [];
        Queue::fake([TerminateJob::class, CreateJob::class]);
        Mail::fake();
        $server = Server::query()->create(['name' => 'Flaky', 'extension' => 'FlakyServer', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'pending', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()]);

        try {
            (new CreateJob($service))->handle(); // attempt 1: the answer lost
            $this->fail('attempt 1 should fail');
        } catch (RuntimeException) {
        }
        $this->assertSame(1, ModuleAction::query()->open()->where('action', 'create')->count());

        ServiceCancellation::query()->create(['service_id' => $service->id, 'type' => 'immediate', 'reason' => 'Changed my mind']);
        $this->assertSame(Service::STATUS_CANCELLED, $service->fresh()->status);

        (new CreateJob($service))->handle(); // attempt 2, after the backoff: the queue counts it done

        $row = ModuleAction::query()->where('action', 'create')->sole();
        $terminated = Queue::pushed(TerminateJob::class)->isNotEmpty();
        $givenToStaff = $row->status === ModuleAction::STATUS_OPEN && $row->given_up_at !== null;
        $this->assertTrue($terminated || $givenToStaff,
            "the account a failed create may have left stays on the server: no terminate queued, the row {$row->status} with given_up_at "
            . var_export($row->given_up_at, true));
    }
}
