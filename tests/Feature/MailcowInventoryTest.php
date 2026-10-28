<?php

namespace Tests\Feature;

use App\Admin\Resources\MailcowDomainResource\Pages\ListMailcowDomains;
use App\Models\MailcowDomain;
use App\Models\MailcowMailbox;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Mailcow\DomainSync;
use App\Services\Mailcow\MailSetup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Utilities → Mail Domains (owner 2026-10-28: «what goes on in each server», as for Proxmox): a mailcow server's domains
 * and mailboxes read (read-only), each domain mapped to its service or marked internal.
 */
class MailcowInventoryTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://mail.example.test';

    /** @var list<array<string, mixed>> */
    private array $domains = [];

    /** @var list<array<string, mixed>>|array<string, mixed> */
    private array $mailboxes = [];

    private bool $down = false;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        MailSetup::$resolve = fn () => []; // the DNS check after each read: never the real DNS in a test
        $this->server = Server::query()->create(['name' => 'Mymail Node 1', 'extension' => 'Mailcow', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => self::API, 'apikey' => 'api-key-1'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->domains = [
            ['domain_name' => 'Example.org', 'active_int' => 1, 'mboxes_in_domain' => 2, 'max_num_mboxes_for_domain' => 10, 'aliases_in_domain' => 1,
                'max_num_aliases_for_domain' => 20, 'max_quota_for_domain' => 1073741824, 'bytes_total' => 536870912, 'msgs_total' => 40, 'domain_admins' => ['exampleo']],
            ['domain_name' => 'ours.test', 'active_int' => 0, 'mboxes_in_domain' => 0, 'max_num_mboxes_for_domain' => 0, 'max_quota_for_domain' => 0, 'bytes_total' => 0],
        ];
        $this->mailboxes = [
            ['username' => 'info@example.org', 'domain' => 'example.org', 'name' => 'Info', 'active_int' => 1, 'quota' => 536870912, 'quota_used' => 1048576,
                'messages' => 30, 'last_imap_login' => 1792000000, 'last_smtp_login' => 1792000100, 'last_pop3_login' => 0, 'last_sso_login' => 0],
            ['username' => 'old@example.org', 'domain' => 'example.org', 'active_int' => 0, 'quota' => 0, 'quota_used' => 0, 'messages' => 0],
        ];
        Http::fake(function (Request $request) {
            if ($this->down) {
                return Http::failedConnection();
            }
            if ($request->header('X-API-Key') !== ['api-key-1']) {
                return Http::response(['type' => 'error', 'msg' => 'authentication failed'], 401);
            }

            return match ((string) parse_url($request->url(), PHP_URL_PATH)) {
                '/api/v1/get/domain/all' => Http::response($this->domains),
                '/api/v1/get/mailbox/all' => Http::response($this->mailboxes),
                '/api/v1/get/dkim/example.org' => Http::response(['dkim_selector' => 'dkim', 'dkim_txt' => 'v=DKIM1;k=rsa;p=KEY']),
                '/api/v1/get/dkim/ours.test' => Http::response([]),
                default => Http::response(['type' => 'error'], 404),
            };
        });
    }

    protected function tearDown(): void
    {
        MailSetup::$resolve = null;
        parent::tearDown();
    }

    private function staff(array $permissions = ['admin.servers.viewAny', 'admin.servers.update']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function service(string $domain, string $status = 'active'): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);

        return Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => $status, 'domain' => $domain]);
    }

    public function test_the_domains_and_mailboxes_are_read_and_a_domain_mapped_to_its_one_service(): void
    {
        $service = $this->service('example.org');

        $result = DomainSync::sync($this->server);

        $this->assertSame(['seen' => 2, 'new' => 2, 'gone' => 0, 'mailboxes' => 2], $result);
        $domain = MailcowDomain::query()->where('domain', 'example.org')->sole();
        $this->assertSame([true, 2, 10, 1, 20, 1073741824, 536870912, 40, ['exampleo'], $service->id],
            [$domain->active, $domain->mailboxes, $domain->mailbox_limit, $domain->aliases, $domain->alias_limit, $domain->quota_bytes,
                $domain->used_bytes, $domain->messages, $domain->admins, $domain->service_id]);
        $this->assertNull(MailcowDomain::query()->where('domain', 'ours.test')->sole()->service_id, 'no service: not mapped');
        $info = MailcowMailbox::query()->where('address', 'info@example.org')->sole();
        $this->assertSame(['Info', 1048576, 30, 1792000100], [$info->name, $info->used_bytes, $info->messages, $info->last_login_at->timestamp]);
        $this->assertNull(MailcowMailbox::query()->where('address', 'old@example.org')->sole()->last_login_at);
    }

    public function test_a_domain_of_two_live_services_or_of_another_servers_is_left_for_staff(): void
    {
        $this->service('example.org');
        $this->service('example.org');
        $other = Server::query()->create(['name' => 'Other', 'extension' => 'Mailcow', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $other->id]);
        Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'domain' => 'ours.test']);

        DomainSync::sync($this->server);

        $this->assertSame(0, MailcowDomain::query()->whereNotNull('service_id')->count());
    }

    public function test_a_second_read_keeps_the_mapping_marks_the_gone_and_mirrors_the_mailboxes(): void
    {
        DomainSync::sync($this->server);
        MailcowDomain::query()->where('domain', 'ours.test')->update(['internal' => true, 'note' => 'our own']);
        array_pop($this->domains);
        array_pop($this->mailboxes);
        $this->mailboxes[0]['quota_used'] = 2097152;

        $result = DomainSync::sync($this->server);

        $this->assertSame(['seen' => 1, 'new' => 0, 'gone' => 1, 'mailboxes' => 1], $result);
        $gone = MailcowDomain::query()->where('domain', 'ours.test')->sole();
        $this->assertNotNull($gone->gone_at);
        $this->assertSame([true, 'our own'], [$gone->internal, $gone->note], 'kept with its mapping');
        $this->assertSame(['info@example.org'], MailcowMailbox::query()->pluck('address')->all());
        $this->assertSame(2097152, MailcowMailbox::query()->sole()->used_bytes);
    }

    public function test_a_failed_or_empty_read_marks_nothing_gone(): void
    {
        DomainSync::sync($this->server);

        $this->domains = [];
        try {
            DomainSync::sync($this->server);
            $this->fail('an empty list where there were domains is no server emptied');
        } catch (\RuntimeException) {
        }
        $this->domains = [['domain_name' => 'example.org']];
        $this->mailboxes = ['type' => 'error', 'msg' => 'access denied'];
        try {
            DomainSync::sync($this->server);
            $this->fail('an error is no list');
        } catch (\Throwable) {
        }
        $this->down = true;
        $this->artisan('mailcow:sync')->assertExitCode(1);

        $this->assertSame(0, MailcowDomain::query()->whereNotNull('gone_at')->count());
        $this->assertSame(2, MailcowMailbox::query()->count());
    }

    public function test_staff_see_the_domains_their_mailboxes_and_map_one(): void
    {
        DomainSync::sync($this->server);
        $domain = MailcowDomain::query()->where('domain', 'ours.test')->sole();
        $example = MailcowDomain::query()->where('domain', 'example.org')->sole();
        $service = $this->service('ours.test', 'suspended');

        $this->livewireAs($this->staff())->test(ListMailcowDomains::class)
            ->assertCanSeeTableRecords([$domain, $example])
            ->mountTableAction('mailboxes', $example)->assertMountedActionModalSee(['info@example.org', 'old@example.org', '1.0 MB / 512 MB']);
        $this->livewireAs($this->staff())->test(ListMailcowDomains::class)
            ->callTableAction('assign', $domain, data: ['service_id' => $service->id])->assertHasNoTableActionErrors();
        $this->assertSame($service->id, $domain->fresh()->service_id);

        $this->livewireAs($this->staff())->test(ListMailcowDomains::class)->callTableAction('internal', $example, data: ['note' => 'test']);
        $this->assertSame([null, true], [$example->fresh()->service_id, $example->fresh()->internal]);
    }

    public function test_staff_who_may_only_look_cannot_map_or_read(): void
    {
        DomainSync::sync($this->server);
        $domain = MailcowDomain::query()->where('domain', 'ours.test')->sole();

        $this->livewireAs($this->staff(['admin.servers.viewAny']))->test(ListMailcowDomains::class)
            ->assertCanSeeTableRecords([$domain])
            ->assertTableActionHidden('assign', $domain)->assertTableActionHidden('internal', $domain)
            ->assertActionHidden('refresh');
        $this->livewireAs($this->staff([]))->test(ListMailcowDomains::class)->assertForbidden();
    }

    /** Review of #492: a domain gone and back for another customer is not the old customer's any more. */
    public function test_a_domain_gone_and_back_for_another_customer_is_mapped_anew(): void
    {
        $old = $this->service('example.org');
        DomainSync::sync($this->server);
        $this->assertSame($old->id, MailcowDomain::query()->where('domain', 'example.org')->value('service_id'));
        $saved = $this->domains;
        $this->domains = [$saved[1]];
        DomainSync::sync($this->server);
        $old->update(['status' => 'terminated']);
        $new = $this->service('example.org');

        $this->domains = $saved;
        DomainSync::sync($this->server);

        $this->assertSame($new->id, MailcowDomain::query()->where('domain', 'example.org')->value('service_id'));
    }

    /** Review of #492: an unpaid order, or a service whose admin is not the domain's, claims no domain already there. */
    public function test_a_pending_order_or_another_admins_service_claims_nothing(): void
    {
        $this->service('example.org', 'pending');
        $theirs = $this->service('ours.test');
        $theirs->update(['username' => 'someone']);
        $this->domains[1]['domain_admins'] = ['oursadmin'];

        DomainSync::sync($this->server);

        $this->assertSame(0, MailcowDomain::query()->whereNotNull('service_id')->count());
        $theirs->update(['username' => 'oursadmin']);
        DomainSync::sync($this->server);
        $this->assertSame($theirs->id, MailcowDomain::query()->where('domain', 'ours.test')->value('service_id'), 'its admin: its domain');
    }

    /** Review of #492: a gone domain keeps no addresses or names; a mailbox that only cannot log in still receives mail. */
    public function test_a_gone_domain_keeps_no_mailboxes_and_a_receive_only_mailbox_is_active(): void
    {
        $this->mailboxes[1]['active_int'] = 2;
        DomainSync::sync($this->server);
        $this->assertTrue(MailcowMailbox::query()->where('address', 'old@example.org')->value('active'));

        $this->domains = [$this->domains[1]];
        $this->mailboxes = [];
        DomainSync::sync($this->server);

        $this->assertSame(0, MailcowMailbox::query()->count());
        $this->assertNotNull(MailcowDomain::query()->where('domain', 'example.org')->value('gone_at'));
    }

    public function test_one_server_asked_that_is_none_fails(): void
    {
        $this->artisan('mailcow:sync', ['--server' => 999])->assertExitCode(1);
    }

    /** Review of #492, round 2: gone and back within one read; a terminated service whose domain stays keeps it. */
    public function test_a_domain_whose_service_ended_goes_to_the_new_live_one_or_stays_whose_it_was(): void
    {
        $old = $this->service('example.org');
        DomainSync::sync($this->server);
        $old->update(['status' => 'terminated']);

        DomainSync::sync($this->server);
        $this->assertSame($old->id, MailcowDomain::query()->where('domain', 'example.org')->value('service_id'), 'kept: whose it was');

        $new = $this->service('example.org');
        DomainSync::sync($this->server);
        $this->assertSame($new->id, MailcowDomain::query()->where('domain', 'example.org')->value('service_id'));
        $this->artisan('mailcow:sync', ['--server' => 0])->assertExitCode(1);
    }

    /** The service page's mail setup: the DKIM record mailcow has, and the DNS status after each read. */
    public function test_a_read_keeps_each_domains_dkim_and_dns_status(): void
    {
        DomainSync::sync($this->server);

        $domain = MailcowDomain::query()->where('domain', 'example.org')->sole();
        $this->assertSame(['dkim', 'v=DKIM1;k=rsa;p=KEY', 'fail'], [$domain->dkim_selector, $domain->dkim_txt, $domain->dns_status], 'no DNS at all: fail');
        $this->assertNull(MailcowDomain::query()->where('domain', 'ours.test')->value('dkim_txt'), 'none made yet');
    }
}
