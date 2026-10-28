<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Livewire\Services\MailSetup as MailSetupComponent;
use App\Livewire\Services\Show as ServiceShow;
use App\Models\MailcowDomain;
use App\Models\MailcowMailbox;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Mailcow\MailSetup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A mail service's setup (owner 2026-10-28), for staff and the customer: the DNS its domain needs, checked against what
 * the DNS answers, and its mailboxes as mailcow:sync last read them.
 */
class MailcowServicePageTest extends TestCase
{
    use RefreshDatabase;

    private const DKIM = 'v=DKIM1;k=rsa;t=s;s=email;p=MIIBKEY';

    /** @var array<string, list<string>> «TYPE name» => records */
    private array $dns = [];

    private int $lookups = 0;

    private Service $service;

    private MailcowDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->dns = [
            'A mail.example.net' => ['192.0.2.25'],
            'MX example.org' => ['mail.example.net'],
            'TXT example.org' => ['v=spf1 mx ~all'],
            'TXT dkim._domainkey.example.org' => [self::DKIM],
        ];
        MailSetup::$resolve = function (string $name, string $type) {
            $this->lookups++;

            return $this->dns["{$type} {$name}"] ?? [];
        };
        $server = Server::query()->create(['name' => 'Mymail Node 1', 'extension' => 'Mailcow', 'type' => 'server', 'enabled' => true]);
        $server->settings()->create(['key' => 'host', 'value' => 'https://mail.example.net']);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $this->service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'domain' => 'example.org']);
        $this->domain = MailcowDomain::query()->create(['server_id' => $server->id, 'domain' => 'example.org', 'mailboxes' => 1, 'mailbox_limit' => 10,
            'quota_bytes' => 1073741824, 'used_bytes' => 1048576, 'dkim_selector' => 'dkim', 'dkim_txt' => self::DKIM, 'last_seen_at' => now()]);
        MailcowMailbox::query()->create(['mailcow_domain_id' => $this->domain->id, 'address' => 'info@example.org', 'name' => 'Info',
            'quota_bytes' => 536870912, 'used_bytes' => 1048576, 'messages' => 3]);
    }

    protected function tearDown(): void
    {
        MailSetup::$resolve = null;
        parent::tearDown();
    }

    private function staff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
    }

    public function test_the_customer_sees_what_their_dns_needs_and_their_mailboxes(): void
    {
        $user = $this->service->client->users()->first();

        $this->livewireAs($user)->test(ServiceShow::class, ['service' => $this->service])
            ->assertSeeLivewire(MailSetupComponent::class);
        $this->livewireAs($user)->test(MailSetupComponent::class, ['service' => $this->service])
            ->assertSee(__('services.mailcow.dns_ok'))
            ->assertSee('_dmarc.example.org')->assertSee('v=DMARC1; p=quarantine')
            ->assertSee('info@example.org')->assertSee('1.0 MB / 512 MB');
    }

    public function test_a_missing_needed_record_says_what_to_set(): void
    {
        unset($this->dns['TXT dkim._domainkey.example.org']);

        $this->livewireAs($this->service->client->users()->first())->test(MailSetupComponent::class, ['service' => $this->service])
            ->assertSee(__('services.mailcow.dns_not_ok'))->assertSee(self::DKIM);
    }

    public function test_the_check_is_kept_and_checked_again_on_request_once_in_a_while(): void
    {
        $page = $this->livewireAs($this->service->client->users()->first())->test(MailSetupComponent::class, ['service' => $this->service]);
        $first = $this->lookups;
        $page->call('$refresh');
        $this->assertSame($first, $this->lookups, 'kept: no lookup again');

        $page->call('recheck');
        $this->assertGreaterThan($first, $after = $this->lookups);
        $page->call('recheck');
        $this->assertSame($after, $this->lookups, 'once in 20 seconds');
    }

    public function test_another_customer_or_a_guest_sees_nothing(): void
    {
        $this->livewireAs(User::factory()->create())->test(MailSetupComponent::class, ['service' => $this->service])->assertForbidden();
        $this->livewireAs($this->staff())->test(MailSetupComponent::class, ['service' => $this->service])->assertForbidden();
    }

    public function test_staff_see_the_mail_section_and_the_list_its_dns_status(): void
    {
        $staff = $this->staff();

        $this->livewireAs($staff)->test(EditService::class, ['record' => $this->service->getRouteKey()])
            ->assertSeeLivewire(MailSetupComponent::class);
        $this->livewireAs($staff)->test(MailSetupComponent::class, ['service' => $this->service, 'admin' => true])
            ->assertSee('DNS ready for mail')->assertSee('info@example.org');

        MailSetup::refreshStatuses($this->domain->server_id);
        $this->assertSame('warn', $this->domain->fresh()->dns_status, 'needed ones OK, advised ones missing');
        $this->dns['TXT _dmarc.example.org'] = ['v=DMARC1; p=none'];
        $this->dns['CNAME autodiscover.example.org'] = ['mail.example.net'];
        $this->dns['CNAME autoconfig.example.org'] = ['mail.example.net'];
        MailSetup::refreshStatuses($this->domain->server_id);
        $this->assertSame('ok', $this->domain->fresh()->dns_status);
        $this->dns['MX example.org'] = [];
        MailSetup::refreshStatuses($this->domain->server_id);
        $this->assertSame('fail', $this->domain->fresh()->dns_status);
    }

    public function test_staff_who_may_not_see_services_get_nothing(): void
    {
        $this->livewireAs($this->staff(['admin.servers.viewAny']))
            ->test(MailSetupComponent::class, ['service' => $this->service, 'admin' => true])->assertForbidden();
    }

    public function test_a_domain_mapped_to_another_service_is_not_this_ones(): void
    {
        $this->domain->update(['service_id' => Service::factory()->create(['client_id' => User::factory()->create()->client()->id,
            'plan_id' => $this->service->plan_id, 'product_id' => $this->service->product_id])->id]);

        $this->assertNull(MailcowDomain::forService($this->service));
    }
}
