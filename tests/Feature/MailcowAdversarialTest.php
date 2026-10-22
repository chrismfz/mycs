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
 * Adversarial review of #324 (mailcow): another customer's admin adopted, locked, signed into or deleted; an admin not
 * found sent an empty domain list; a lock by hand undone; a retry of our own half-made domain refused.
 */
class MailcowAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://mail.test';

    private Service $service;

    /** @var array<string, array<string, mixed>> */
    private array $domains = [];

    /** @var list<array<string, mixed>> */
    private array $admins = [];

    /** @var list<array<string, mixed>> */
    private array $mailboxes = [];

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

        return match (true) {
            str_starts_with($path, '/api/v1/get/domain-admin/all') => Http::response($this->admins),
            str_starts_with($path, '/api/v1/get/domain/') => Http::response($this->domains[urldecode(basename($path))] ?? []),
            str_starts_with($path, '/api/v1/get/dkim/') => Http::response([]),
            str_starts_with($path, '/api/v1/get/mailbox/all') => Http::response($this->mailboxes),
            str_starts_with($path, '/api/v1/get/alias/all') => Http::response([]),
            $path === '/api/v1/add/domain' => tap($ok, fn () => $this->domains[$request['domain']] = ['domain_name' => $request['domain']]),
            $path === '/api/v1/add/domain-admin' => tap($ok, fn () => $this->admins[] = ['username' => $request['username'], 'selected_domains' => $request['domains'], 'active' => 1]),
            $path === '/api/v1/add/sso/domain-admin' => Http::response(['token' => 'sso-token-1']),
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

    /** An imported service (or one staff typed) whose services.username is another customer's admin of victim.gr. */
    private function foreignAdmin(): void
    {
        $this->domains['cdnet.gr'] = ['domain_name' => 'cdnet.gr'];
        $this->domains['victim.gr'] = ['domain_name' => 'victim.gr'];
        $this->admins = [
            ['username' => 'victim', 'selected_domains' => 'victim.gr', 'active' => 1],
            ['username' => 'cdnet', 'selected_domains' => 'cdnet.gr', 'active' => 1],
        ];
        $this->mailboxes = [['username' => 'info@cdnet.gr'], ['username' => 'ceo@victim.gr']];
        $this->service->forceFill(['username' => 'victim'])->save();
    }

    /** ADV-1: the pending name, taken since by another customer's admin (cdnet.com), is adopted: a takeover. */
    public function test_a_retry_never_adopts_an_admin_made_since_for_another_domain(): void
    {
        $this->service->properties()->create(['key' => 'mailcow_pending_username', 'name' => 'p', 'value' => 'cdnet']);
        $this->domains['cdnet.com'] = ['domain_name' => 'cdnet.com'];
        $this->admins = [['username' => 'cdnet', 'selected_domains' => 'cdnet.com', 'active' => 1]];

        $this->quietly(fn () => ExtensionHelper::createServer($this->service->fresh()));

        $this->assertNotSame('cdnet', $this->service->fresh()->username, 'cdnet is the admin of cdnet.com, another customer');
    }

    /** ADV-2: Credentials::username gives up after 20 tries with a name that is taken, and create adopts it. */
    public function test_a_username_that_is_never_free_is_not_adopted(): void
    {
        $this->admins = [['username' => 'cdnet', 'selected_domains' => 'other.gr', 'active' => 1]];
        foreach (range(1000, 9999) as $n) {
            $this->admins[] = ['username' => "cdnet{$n}", 'selected_domains' => "d{$n}.gr", 'active' => 1];
        }

        $this->quietly(fn () => ExtensionHelper::createServer($this->service->fresh()));

        $this->assertNull($this->service->fresh()->username, 'every candidate belongs to someone else');
    }

    /** ADV-3: SSO into the admin on services.username, though it does not manage this service's domain. */
    public function test_sso_never_signs_into_an_admin_that_does_not_manage_the_services_domain(): void
    {
        $this->foreignAdmin();

        $this->quietly(fn () => ExtensionHelper::callService($this->service->fresh(), 'getLoginUrl', [], true));

        $this->assertCount(0, $this->sent('/api/v1/add/sso/domain-admin'), "a token for victim.gr's admin");
    }

    /** ADV-4: suspend locks another customer's admin. */
    public function test_suspend_never_locks_an_admin_that_does_not_manage_the_services_domain(): void
    {
        $this->foreignAdmin();

        $this->quietly(fn () => ExtensionHelper::suspendServer($this->service->fresh()));

        $this->assertCount(0, $this->sent('/api/v1/edit/domain-admin'), "victim.gr's admin locked");
    }

    /** ADV-5: the password of another customer's admin changed (and kept here, so this customer knows it). */
    public function test_a_password_is_never_set_on_an_admin_that_does_not_manage_the_services_domain(): void
    {
        $this->foreignAdmin();

        $this->quietly(fn () => ExtensionHelper::callService($this->service->fresh(), 'changePassword', ['N3w-Strong-Pass', false], true));

        $this->assertCount(0, $this->sent('/api/v1/edit/domain-admin'), "victim.gr's admin's password changed");
    }

    /** ADV-6: delete_all deletes the domain's mail though the admin on the service does not manage it. */
    public function test_delete_all_never_runs_when_the_admin_does_not_manage_the_services_domain(): void
    {
        $this->foreignAdmin();
        $this->service->product->settings()->create(['key' => 'terminate_mode', 'value' => 'delete_all']);

        $this->quietly(fn () => ExtensionHelper::terminateServer($this->service->fresh()));

        $this->assertCount(0, [...$this->sent('/api/v1/delete/mailbox'), ...$this->sent('/api/v1/delete/domain')], "cdnet.gr belongs to the admin cdnet, not to this service's victim");
        $this->assertCount(0, $this->sent('/api/v1/edit/domain-admin'));
    }

    /** ADV-7: the usage and the hosted domains show another customer's domain. */
    public function test_usage_and_hosted_domains_never_read_an_admin_that_does_not_manage_the_services_domain(): void
    {
        $this->foreignAdmin();

        $this->artisan('services:usage');

        $this->assertSame([], $this->service->hostedDomains()->pluck('domain')->all(), 'victim.gr is shown to this customer');
        $this->assertNull($this->service->usage()->first(), "victim.gr's usage is shown to this customer");
    }

    /**
     * ADV-8: an admin not found (here an imported «CDNET», mailcow's «cdnet»: its collation ignores case) is still edited,
     * with domains: []; mailcow rebuilds the admin's list from it, so every domain is taken off the admin.
     */
    public function test_suspend_never_sends_an_empty_domain_list_for_an_admin_not_found(): void
    {
        $this->admins = [['username' => 'cdnet', 'selected_domains' => 'cdnet.gr, nac.gr', 'active' => 1]];
        $this->service->forceFill(['username' => 'CDNET'])->save();

        $this->quietly(fn () => ExtensionHelper::suspendServer($this->service->fresh()));
        $this->quietly(fn () => ExtensionHelper::callService($this->service->fresh(), 'changePassword', ['N3w-Strong-Pass', false], true));

        foreach ($this->sent('/api/v1/edit/domain-admin') as $edit) {
            $this->assertNotSame([], $edit['attr']['domains'] ?? null, 'the admin edited with no domains');
        }
    }

    /** ADV-9: delete_all deletes an admin it could not find (domains: [] → «only for this domain»). */
    public function test_delete_all_never_deletes_an_admin_not_found(): void
    {
        $this->admins = [['username' => 'cdnet', 'selected_domains' => 'cdnet.gr, nac.gr', 'active' => 1]];
        $this->service->forceFill(['username' => 'CDNET'])->save();
        $this->service->product->settings()->create(['key' => 'terminate_mode', 'value' => 'delete_all']);

        $this->quietly(fn () => ExtensionHelper::terminateServer($this->service->fresh()));

        $this->assertCount(0, $this->sent('/api/v1/delete/domain-admin'), 'the admin of nac.gr too');
    }

    /** ADV-10: an admin locked by hand (abuse) before the suspension is unlocked by the unsuspend. */
    public function test_unsuspend_never_unlocks_an_admin_locked_by_hand(): void
    {
        $this->domains['cdnet.gr'] = ['domain_name' => 'cdnet.gr'];
        $this->admins = [['username' => 'cdnet', 'selected_domains' => 'cdnet.gr', 'active' => 0]];
        $this->service->forceFill(['username' => 'cdnet'])->save();

        ExtensionHelper::suspendServer($this->service->fresh());
        ExtensionHelper::unsuspendServer($this->service->fresh());

        foreach ($this->sent('/api/v1/edit/domain-admin') as $edit) {
            $this->assertNotSame(1, (int) ($edit['attr']['active'] ?? 0), 'it was locked by hand, not by the suspend');
        }
    }

    /** ADV-11: a retry after our own add/domain's answer was lost is refused, though the docblock says it adopts. */
    public function test_a_retry_after_a_lost_add_domain_answer_finishes_the_create(): void
    {
        $this->service->properties()->create(['key' => 'mailcow_pending_username', 'name' => 'p', 'value' => 'cdnet']);
        $this->domains['cdnet.gr'] = ['domain_name' => 'cdnet.gr'];

        $this->quietly(fn () => ExtensionHelper::createServer($this->service->fresh()));

        $this->assertSame('cdnet', $this->service->fresh()->username, 'stuck: refused as «under another admin»');
    }
}
