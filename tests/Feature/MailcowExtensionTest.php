<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Jobs\Server\TerminateJob;
use App\Livewire\Services\Show;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * mailcow (owner 2026-10-22: myip's «MyMail» services), on packages/mailcow, as our WHMCS module: a domain, DKIM and a
 * domain admin per service; suspend and terminate lock the admin (the mail kept) unless the product says delete_all;
 * SSO, password, usage and the domains its admin manages.
 */
class MailcowExtensionTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://mail.test';

    private User $owner;

    private Service $service;

    /** @var array<string, array<string, mixed>> domains at mailcow */
    private array $domains = [];

    /** @var list<array<string, mixed>> domain admins at mailcow */
    private array $admins = [];

    private ?string $refuse = null;

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
        $this->owner = User::factory()->create();
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['aliases' => '100', 'mailboxes' => '100', 'defquota' => '1024', 'maxquota' => '10240', 'quota' => '51200', 'create_dkim' => '1'] as $key => $value) {
            $product->product->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->service = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addMonth(), 'domain' => 'cdnet.gr']);
        Http::fake(fn (Request $request) => $this->mailcow($request));
    }

    private function mailcow(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $ok = Http::response([['type' => 'success', 'msg' => ['ok']]]);
        if ($this->refuse !== null && $request->method() === 'POST') {
            return Http::response([['type' => 'danger', 'msg' => [$this->refuse]]]);
        }

        return match (true) {
            str_starts_with($path, '/api/v1/get/domain-admin/all') => Http::response($this->admins),
            str_starts_with($path, '/api/v1/get/domain/') => Http::response($this->domains[urldecode(basename($path))] ?? []),
            str_starts_with($path, '/api/v1/get/dkim/') => Http::response([]),
            str_starts_with($path, '/api/v1/get/mailbox/all') => Http::response([['username' => 'info@cdnet.gr'], ['username' => 'sales@cdnet.gr'], ['username' => 'x@other.gr']]),
            str_starts_with($path, '/api/v1/get/alias/all') => Http::response([['id' => 7, 'address' => 'hello@cdnet.gr']]),
            $path === '/api/v1/add/domain' => tap($ok, fn () => $this->domains[$request['domain']] = ['domain_name' => $request['domain']]),
            $path === '/api/v1/add/domain-admin' => tap($ok, fn () => $this->admins[] = ['username' => $request['username'], 'selected_domains' => $request['domains'], 'active' => 1]),
            $path === '/api/v1/add/sso/domain-admin' => Http::response(['token' => 'sso-token-1']),
            default => $ok,
        };
    }

    private function sent(string $path): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === $path)->values()->all();
    }

    private function made(array $domains = ['cdnet.gr']): void
    {
        $this->domains['cdnet.gr'] = ['domain_name' => 'cdnet.gr', 'bytes_total' => 300 * 1048576, 'max_quota_for_domain' => 51200 * 1048576];
        $this->admins = [['username' => 'cdnet', 'selected_domains' => implode(', ', $domains), 'active' => 1]];
        $this->service->properties()->create(['key' => 'mailcow_username', 'name' => 'mailcow domain admin', 'value' => 'cdnet']);
        $this->service->forceFill(['username' => 'cdnet', 'password' => 'Old-Pass-123'])->save();
    }

    public function test_create_adds_the_domain_its_dkim_and_its_admin(): void
    {
        ExtensionHelper::createServer($this->service->fresh());

        [$domain] = $this->sent('/api/v1/add/domain');
        $this->assertSame(['cdnet.gr', 100, 51200], [$domain['domain'], (int) $domain['mailboxes'], (int) $domain['quota']]);
        $this->assertCount(1, $this->sent('/api/v1/add/dkim'));
        [$admin] = $this->sent('/api/v1/add/domain-admin');
        $this->assertSame(['cdnet', 'cdnet.gr'], [$admin['username'], $admin['domains']]);
        $service = $this->service->fresh();
        $this->assertSame(['cdnet', $admin['password']], [$service->username, $service->password], 'the password kept, encrypted');
        $this->assertSame('cdnet', $service->properties()->where('key', 'mailcow_username')->value('value'));
    }

    public function test_a_retry_after_a_lost_answer_adopts_what_was_made(): void
    {
        $this->service->properties()->create(['key' => 'mailcow_pending_username', 'name' => 'p', 'value' => 'cdnet']);
        $this->domains['cdnet.gr'] = ['domain_name' => 'cdnet.gr'];
        $this->admins = [['username' => 'cdnet', 'selected_domains' => 'cdnet.gr', 'active' => 1]];

        ExtensionHelper::createServer($this->service->fresh());

        $this->assertSame([], [...$this->sent('/api/v1/add/domain'), ...$this->sent('/api/v1/add/domain-admin')], 'never made twice');
        $this->assertSame('cdnet', $this->service->fresh()->username);
    }

    public function test_a_domain_already_under_another_admin_is_refused(): void
    {
        $this->domains['cdnet.gr'] = ['domain_name' => 'cdnet.gr'];
        $this->admins = [['username' => 'someoneelse', 'selected_domains' => 'cdnet.gr', 'active' => 1]];

        $this->expectExceptionMessage('already on this server under another admin');
        try {
            ExtensionHelper::createServer($this->service->fresh());
        } finally {
            $this->assertSame([], $this->sent('/api/v1/add/domain-admin'));
        }
    }

    public function test_suspend_and_unsuspend_lock_the_admin_only(): void
    {
        $this->made();

        ExtensionHelper::suspendServer($this->service->fresh());
        ExtensionHelper::unsuspendServer($this->service->fresh());

        [$lock, $unlock] = $this->sent('/api/v1/edit/domain-admin');
        $this->assertSame([0, 1], [(int) $lock['attr']['active'], (int) $unlock['attr']['active']]);
        $this->assertSame([], $this->sent('/api/v1/delete/mailbox'));
    }

    public function test_an_admin_of_several_domains_is_not_locked_unless_forced(): void
    {
        $this->made(['cdnet.gr', 'nac.gr']);

        ExtensionHelper::suspendServer($this->service->fresh());

        $this->assertSame([], $this->sent('/api/v1/edit/domain-admin'), 'locking it would lock nac.gr too');
    }

    public function test_terminate_by_default_locks_and_keeps_the_mail(): void
    {
        $this->made();
        $this->service->update(['status' => Service::STATUS_TERMINATED]);

        (new TerminateJob($this->service->fresh(), false))->handle();

        $this->assertCount(1, $this->sent('/api/v1/edit/domain-admin'));
        $this->assertSame([], [...$this->sent('/api/v1/delete/mailbox'), ...$this->sent('/api/v1/delete/domain')]);
    }

    public function test_delete_all_removes_only_this_domains_mail_and_keeps_an_admin_of_others(): void
    {
        $this->made(['cdnet.gr', 'nac.gr']);
        $this->service->product->settings()->create(['key' => 'terminate_mode', 'value' => 'delete_all']);

        ExtensionHelper::terminateServer($this->service->fresh());

        [$mailboxes] = $this->sent('/api/v1/delete/mailbox');
        $this->assertSame(['info@cdnet.gr', 'sales@cdnet.gr'], $mailboxes->data());
        $this->assertSame([7], $this->sent('/api/v1/delete/alias')[0]->data());
        $this->assertSame([], $this->sent('/api/v1/delete/domain-admin'), 'it still manages nac.gr');
        $this->assertSame(['nac.gr'], $this->sent('/api/v1/edit/domain-admin')[0]['attr']['domains']);
        $this->assertCount(1, $this->sent('/api/v1/delete/domain'));
    }

    public function test_sso_signs_the_customer_into_the_domain_admin(): void
    {
        $this->made();
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));

        Livewire::test(Show::class, ['service' => $this->service])->call('goto', 'getLoginUrl')->assertRedirectContains('/domainadmin/');

        $this->assertSame('cdnet', $this->sent('/api/v1/add/sso/domain-admin')[0]['username']);
    }

    public function test_a_new_password_with_no_mysql_option_and_a_refusal_with_its_reason(): void
    {
        $this->made();
        RateLimiter::clear('service-password:' . $this->service->id);
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));
        $page = Livewire::test(Show::class, ['service' => $this->service]);
        $this->assertFalse($page->get('passwordHasMysql'), 'no MySQL here');

        $page->set('newPassword', 'N3w-Strong-Pass')->set('newPasswordConfirmation', 'N3w-Strong-Pass')->call('changeServicePassword');
        $this->assertSame('N3w-Strong-Pass', $this->service->fresh()->password);

        $this->refuse = 'password_complexity';
        $page->set('newPassword', 'An0ther-Strong-Pass')->set('newPasswordConfirmation', 'An0ther-Strong-Pass')->call('changeServicePassword')
            ->assertDispatched('notify', fn ($name, $params) => str_contains((string) ($params[0]['message'] ?? ''), 'password_complexity'));
        $this->assertSame('N3w-Strong-Pass', $this->service->fresh()->password);
    }

    public function test_usage_counts_mailboxes_and_its_domains_are_found(): void
    {
        $this->made();

        $this->artisan('services:usage')->assertExitCode(0);

        $usage = $this->service->usage()->sole();
        $this->assertSame([300, 51200, 2, 100, 'mailboxes'], [$usage->disk_used_mb, $usage->disk_limit_mb, $usage->accounts, $usage->accounts_limit, $usage->accounts_kind]);
        $this->assertSame(['cdnet.gr'], $this->service->hostedDomains()->pluck('domain')->all());
    }
}
