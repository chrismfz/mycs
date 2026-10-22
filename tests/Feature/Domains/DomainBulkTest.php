<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Index;
use App\Models\ClientUser;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Several domains at once in the client area (as WHMCS's renewal cart and «bulkdomain», used by myip's customers every
 * day): renewed together on one invoice, auto renew on or off, nameservers, transfer lock; each as its own page does,
 * under the same permissions, only the account's own domains, at most BULK_MAX.
 */
class DomainBulkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainRegistrarConnection $op;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> method, path and payload of each registrar call */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        Cache::flush();
        Http::preventStrayRequests();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.domain_billing_enabled' => true, 'settings.default_currency' => 'EUR']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->user = User::factory()->create();
        $tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '23.33']);
        Http::fake(function (Request $request) {
            $this->calls[] = [$request->method(), (string) parse_url($request->url(), PHP_URL_PATH), $request->data()];

            return match (true) {
                str_contains($request->url(), '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
                $request->method() === 'PUT' => Http::response(['data' => ['success' => true]]),
                default => Http::response(['data' => ['id' => 30391502, 'status' => 'ACT', 'expiration_date' => '2027-11-17 00:00:00',
                    'name_servers' => [['name' => 'ns1.myip.gr'], ['name' => 'ns2.myip.gr']]]]),
            };
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(string $sld, array $attributes = [], ?User $owner = null): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => ($owner ?? $this->user)->client()->id, 'sld' => $sld, 'tld' => 'com',
            'fqdn' => "{$sld}.com", 'domain_tld_id' => DomainTld::query()->value('id'), 'registrar_connection_id' => $this->op->id,
            'registrar_domain_id' => (string) random_int(1000, 99999), 'status' => DomainStatus::Active, 'expires_at' => '2027-11-17',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'transfer_lock' => true, 'id_protection' => false]);
    }

    private function member(array $permissions): User
    {
        $member = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->user->client()->id, 'user_id' => $member->id, 'granted_at' => now(), 'permissions' => $permissions]);

        return $member;
    }

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> the registrar's writes (PUT) */
    private function writes(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c[0] === 'PUT'));
    }

    public function test_renewed_together_on_one_invoice_and_only_once(): void
    {
        $a = $this->domain('alpha', ['auto_renew' => false]);
        $b = $this->domain('beta');

        Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$a->id, $b->id])->call('bulkRenew')->assertRedirect();

        $lines = InvoiceItem::query()->where('reference_type', Domain::class)->get();
        $this->assertCount(2, $lines);
        $this->assertCount(1, $lines->pluck('invoice_id')->unique(), 'one invoice for both');
        $this->assertTrue($a->fresh()->auto_renew, 'asking to renew says so');

        Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$a->id, $b->id])->call('bulkRenew')
            ->assertRedirect(route('invoices.show', $lines->first()->invoice_id));
        $this->assertCount(2, InvoiceItem::query()->where('reference_type', Domain::class)->get(), 'their open invoice, not a second one');
    }

    public function test_never_another_accounts_domain_nor_an_ended_one(): void
    {
        $mine = $this->domain('mine');
        $theirs = $this->domain('theirs', [], User::factory()->create());
        $ended = $this->domain('gone', ['status' => DomainStatus::Cancelled]);

        Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$mine->id, $theirs->id, $ended->id])->call('bulkRenew');

        $this->assertSame([$mine->id], InvoiceItem::query()->where('reference_type', Domain::class)->pluck('reference_id')->all());
        Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$theirs->id])->call('bulkAutoRenew', false);
        $this->assertTrue($theirs->fresh()->auto_renew);
    }

    public function test_renewing_needs_the_invoices_too_and_managing_needs_managedomains(): void
    {
        $domain = $this->domain('alpha');
        $manager = $this->member(['domains', 'managedomains']);

        $this->actingAs($manager)->withSession($this->loginUser($manager, [CurrentClient::SESSION_KEY => $this->user->client()->id]));
        Livewire::test(Index::class)->assertDontSeeHtml('wire:click="bulkRenew"')
            ->set('selected', [$domain->id])->call('bulkRenew')->assertStatus(404);
        $this->assertSame(0, InvoiceItem::query()->count());

        $viewer = $this->member(['domains']);
        $this->actingAs($viewer)->withSession($this->loginUser($viewer, [CurrentClient::SESSION_KEY => $this->user->client()->id]));
        Livewire::test(Index::class)->set('selected', [$domain->id])->call('bulkAutoRenew', false)->assertStatus(404); // not told to a login without managedomains
        $this->assertTrue($domain->fresh()->auto_renew);
    }

    public function test_auto_renew_on_and_off_for_the_ticked(): void
    {
        $a = $this->domain('alpha');
        $b = $this->domain('beta');

        Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$a->id, $b->id])->call('bulkAutoRenew', false);

        $this->assertSame([false, false], [$a->fresh()->auto_renew, $b->fresh()->auto_renew]);
    }

    public function test_nameservers_set_at_the_registrar_for_each_and_each_told(): void
    {
        $a = $this->domain('alpha');
        $manual = $this->domain('manual', ['registrar_connection_id' => null]);

        Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$a->id, $manual->id])
            ->set('bulkNameservers', ['NS1.example.net', 'ns2.example.net'])->call('bulkSetNameservers')
            ->assertDispatched('notify', fn ($name, $params) => str_contains((string) ($params[0]['message'] ?? ''), 'alpha.com'))
            ->assertDispatched('notify', fn ($name, $params) => str_contains((string) ($params[0]['message'] ?? ''), 'manual.com'));

        $writes = $this->writes();
        $this->assertCount(1, $writes, 'one registrar change: the manual domain is not asked');
        $this->assertSame(['ns1.example.net', 'ns2.example.net'], array_column($writes[0][2]['name_servers'] ?? [], 'name'), 'sent as typed, lower case');
    }

    public function test_bad_nameservers_change_nothing(): void
    {
        $a = $this->domain('alpha');

        Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$a->id])
            ->set('bulkNameservers', ['only-one.example.net'])->call('bulkSetNameservers')->assertHasErrors('bulkNameservers');

        $this->assertSame([], $this->writes());
    }

    public function test_transfer_lock_for_each_where_the_registrar_can(): void
    {
        $a = $this->domain('alpha', ['transfer_lock' => false]);
        $b = $this->domain('beta', ['transfer_lock' => false, 'meta' => ['registrar' => ['is_lockable' => false]]]);

        Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$a->id, $b->id])->call('bulkTransferLock', true)
            ->assertDispatched('notify', fn ($name, $params) => str_contains((string) ($params[0]['message'] ?? ''), __('domains.portal.bulk.not_lockable')));

        $writes = $this->writes();
        $this->assertCount(1, $writes, 'only the lockable one asked');
        $this->assertTrue((bool) ($writes[0][2]['is_locked'] ?? false));
        $this->assertFalse($b->fresh()->transfer_lock);
    }

    public function test_at_most_bulk_max_and_a_few_bulk_actions_a_quarter_hour(): void
    {
        $ids = [];
        foreach (range(1, Index::BULK_MAX + 3) as $i) {
            $ids[] = $this->domain("d{$i}", ['auto_renew' => true])->id;
        }

        // More than BULK_MAX: refused and told, never cut silently.
        Livewire::actingAs($this->user)->test(Index::class)->set('selected', $ids)->call('bulkAutoRenew', false)
            ->assertDispatched('notify', fn ($name, $params) => str_contains((string) ($params[0]['message'] ?? ''), (string) Index::BULK_MAX));
        $this->assertSame(0, Domain::query()->where('auto_renew', false)->count());
        // Ticking a page never goes past it.
        Livewire::actingAs($this->user)->test(Index::class)->call('togglePage', $ids)->assertCount('selected', Index::BULK_MAX);

        RateLimiter::clear('domain-bulk:' . $this->user->id);
        $page = Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$ids[0]])->set('bulkNameservers', ['ns1.example.net', 'ns2.example.net']);
        foreach (range(1, 7) as $i) {
            $page->call('bulkSetNameservers');
        }
        $this->assertCount(6, $this->writes(), 'the seventh within a quarter-hour is refused');
    }

    public function test_the_page_ticks_its_domains_and_shows_the_actions(): void
    {
        $a = $this->domain('alpha');
        $b = $this->domain('beta');

        Livewire::actingAs($this->user)->test(Index::class)
            ->assertDontSee(__('domains.portal.bulk.renew'))
            ->call('togglePage', [$a->id, $b->id])->assertSet('selected', [(string) $a->id, (string) $b->id])
            ->assertSee(__('domains.portal.bulk.renew'))->assertSee(__('domains.portal.bulk.nameservers'))
            ->call('togglePage', [$a->id, $b->id])->assertSet('selected', []);
    }
}
