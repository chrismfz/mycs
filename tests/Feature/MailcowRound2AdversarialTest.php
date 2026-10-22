<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #324 (mailcow), round 2, on the fix's markers: `mailcow_domain_added` and `mailcow_locked_by_us`
 * are both written before mailcow is asked, and both outlive mailcow's refusal, so they later vouch for something this
 * service never did (a domain someone else added; a lock someone else set).
 */
class MailcowRound2AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://mail.test';

    private Service $service;

    /** @var array<string, array<string, mixed>> */
    private array $domains = [];

    /** @var list<array<string, mixed>> */
    private array $admins = [];

    /** @var array<string, string> path => mailcow's refusal */
    private array $refuse = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $server = Server::query()->create(['name' => 'Mymail Node 1', 'extension' => 'Mailcow', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => self::API, 'apikey' => 'api-key-1', 'sso_path' => '/domainadmin/'] as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $owner = User::factory()->create();
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['aliases' => '100', 'mailboxes' => '100', 'quota' => '51200', 'create_dkim' => '1'] as $key => $value) {
            $product->product->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->service = Service::factory()->create(['client_id' => $owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addMonth(), 'domain' => 'cdnet.gr']);
        Http::fake(fn (Request $request) => $this->mailcow($request));
    }

    private function mailcow(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $ok = Http::response([['type' => 'success', 'msg' => ['ok']]]);
        if (isset($this->refuse[$path])) {
            return Http::response([['type' => 'danger', 'msg' => [$this->refuse[$path]]]]);
        }

        return match (true) {
            str_starts_with($path, '/api/v1/get/domain-admin/all') => Http::response($this->admins),
            str_starts_with($path, '/api/v1/get/domain/') => Http::response($this->domains[urldecode(basename($path))] ?? []),
            str_starts_with($path, '/api/v1/get/dkim/') => Http::response([]),
            $path === '/api/v1/add/domain' => tap($ok, fn () => $this->domains[$request['domain']] = ['domain_name' => $request['domain']]),
            $path === '/api/v1/add/domain-admin' => tap($ok, fn () => $this->admins[] = ['username' => $request['username'], 'selected_domains' => $request['domains'], 'active' => 1]),
            default => $ok,
        };
    }

    /** @return list<Request> */
    private function sent(string $path): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === $path)->values()->all();
    }

    private function quietly(callable $call): void
    {
        try {
            $call();
        } catch (Throwable) {
            // a refusal is the right answer; what matters is what was sent
        }
    }

    /**
     * R2-1: mailcow refuses our add/domain (a definite answer: nothing was made), but `mailcow_domain_added` stays. Later
     * the domain is set up by someone else (staff, by hand, with its own admin and mailboxes); a retry of this create
     * then adopts it as «ours» and adds this customer a second admin of it: SSO into someone else's mail.
     */
    public function test_a_refused_add_domain_never_vouches_for_a_domain_someone_else_adds_later(): void
    {
        $this->refuse['/api/v1/add/domain'] = 'domain_quota_exceeded';
        $this->quietly(fn () => ExtensionHelper::createServer($this->service->fresh()));
        $this->assertSame([], $this->sent('/api/v1/add/domain-admin'), 'the first create stops at the refusal');
        unset($this->refuse['/api/v1/add/domain']);

        // Since then: cdnet.gr set up at mailcow by someone else, with its own admin.
        $this->domains['cdnet.gr'] = ['domain_name' => 'cdnet.gr'];
        $this->admins = [['username' => 'staffmade', 'selected_domains' => 'cdnet.gr', 'active' => 1]];

        $this->quietly(fn () => ExtensionHelper::createServer($this->service->fresh()));

        $this->assertSame([], $this->sent('/api/v1/add/domain-admin'), 'a domain this service never made, under another admin, adopted');
        $this->assertNull($this->service->fresh()->username);
    }

    /**
     * R2-2: suspend writes `mailcow_locked_by_us` before the lock; mailcow refuses the lock, the mark stays. Staff then
     * lock the admin by hand (abuse); the suspend's retry leaves it as it is (already locked), and the unsuspend unlocks
     * the hand lock because of the stale mark (the ADV-10 case again, one refusal later).
     */
    public function test_a_refused_lock_never_lets_the_unsuspend_undo_a_lock_by_hand(): void
    {
        $this->domains['cdnet.gr'] = ['domain_name' => 'cdnet.gr'];
        $this->admins = [['username' => 'cdnet', 'selected_domains' => 'cdnet.gr', 'active' => 1]];
        $this->service->properties()->create(['key' => 'mailcow_username', 'name' => 'mailcow domain admin', 'value' => 'cdnet']);
        $this->service->forceFill(['username' => 'cdnet'])->save();

        $this->refuse['/api/v1/edit/domain-admin'] = 'access_denied';
        $this->quietly(fn () => ExtensionHelper::suspendServer($this->service->fresh()));
        unset($this->refuse['/api/v1/edit/domain-admin']);

        $this->admins[0]['active'] = 0; // locked by hand, for abuse
        $this->quietly(fn () => ExtensionHelper::suspendServer($this->service->fresh()));
        $this->quietly(fn () => ExtensionHelper::unsuspendServer($this->service->fresh()));

        foreach ($this->sent('/api/v1/edit/domain-admin') as $edit) {
            $this->assertNotSame(1, (int) ($edit['attr']['active'] ?? 0), 'it was locked by hand, not by the suspend');
        }
    }
}
