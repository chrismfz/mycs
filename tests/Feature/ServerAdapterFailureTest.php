<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\CreateJob;
use App\Mail\Mail as MyCSMail;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A server adapter's failure must be a failure (audit sweep KR-3, SVC-1): WHM answers HTTP 200 with
 * metadata.result = 0 when it refuses (duplicate domain, bad package), and CPanel::createServer returned true anyway;
 * the other actions returned false, which nobody checked. The service went active without an account and the
 * customer got "your server is ready".
 */
class ServerAdapterFailureTest extends TestCase
{
    use RefreshDatabase;

    private const WHM = 'https://whm.test:2087/json-api';

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Mail::fake();

        $server = Server::query()->create(['name' => 'WHM', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }

        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'basic']);

        $this->service = Service::factory()->create([
            'user_id' => User::factory()->create()->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'currency_code' => 'USD',
            'price' => 10.00,
        ]);
        $this->service->properties()->create(['key' => 'domain', 'name' => 'Domain', 'value' => 'example.test']);
    }

    private function whm(string $endpoint, int $result, string $reason = ''): void
    {
        Http::fake([self::WHM . $endpoint . '*' => Http::response([
            'metadata' => ['result' => $result, 'reason' => $reason, 'command' => ltrim($endpoint, '/')],
        ])]);
    }

    private function created(): void
    {
        $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'exampleuser']);
    }

    public function test_a_refused_create_throws_with_whms_reason_and_stores_no_username(): void
    {
        $this->whm('/createacct', 0, 'Sorry, a DNS entry for “example.test” already exists.');

        try {
            ExtensionHelper::createServer($this->service);
            $this->fail('A refused create must throw.');
        } catch (Exception $e) {
            $this->assertStringContainsString('createacct', $e->getMessage());
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->assertNull($this->service->properties()->where('key', 'cpanel_username')->first());
    }

    public function test_a_successful_create_stores_the_username(): void
    {
        $this->whm('/createacct', 1);

        ExtensionHelper::createServer($this->service);

        $this->assertNotNull($this->service->properties()->where('key', 'cpanel_username')->value('value'));
    }

    public function test_the_create_job_fails_and_sends_no_server_created_email(): void
    {
        $this->whm('/createacct', 0, 'Package does not exist');

        try {
            (new CreateJob($this->service))->handle();
            $this->fail('The job must fail.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Package does not exist', $e->getMessage());
        }

        // Other mail (e.g. the new user's) may be queued by the fixtures: only the server-created one matters.
        Mail::assertNotQueued(MyCSMail::class, fn (MyCSMail $mail) => $mail->emailTemplate->key === 'new_server_created');
    }

    /** @return array<string, array{string, string}> */
    public static function actions(): array
    {
        return [
            'suspend' => ['suspendServer', '/suspendacct'],
            'unsuspend' => ['unsuspendServer', '/unsuspendacct'],
            'terminate' => ['terminateServer', '/removeacct'],
            'upgrade' => ['upgradeServer', '/modifyacct'],
        ];
    }

    #[DataProvider('actions')]
    public function test_a_refused_action_throws(string $action, string $endpoint): void
    {
        $this->created();
        $this->whm($endpoint, 0, 'User exampleuser does not exist');

        $this->expectExceptionMessage('does not exist');
        ExtensionHelper::$action($this->service);
    }

    public function test_a_refused_terminate_keeps_the_username(): void
    {
        $this->created();
        $this->whm('/removeacct', 0, 'Account is locked');

        try {
            ExtensionHelper::terminateServer($this->service);
            $this->fail('A refused terminate must throw.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Account is locked', $e->getMessage());
        }

        $this->assertSame('exampleuser', $this->service->properties()->where('key', 'cpanel_username')->value('value'));
    }

    #[DataProvider('actions')]
    public function test_a_successful_action_returns_normally(string $action, string $endpoint): void
    {
        $this->created();
        $this->whm($endpoint, 1);

        $this->assertNotFalse(ExtensionHelper::$action($this->service));
    }

    public function test_an_adapter_returning_false_is_a_failure(): void
    {
        require_once __DIR__ . '/../Fixtures/FalseServer.php';
        $server = Server::query()->create(['name' => 'Legacy', 'extension' => 'FalseServer', 'type' => 'server', 'enabled' => true]);
        $this->service->product->update(['server_id' => $server->id]);

        $this->expectExceptionMessage('Server action suspendServer failed for service #' . $this->service->id . ' (FalseServer)');
        ExtensionHelper::suspendServer($this->service->fresh());
    }
}
