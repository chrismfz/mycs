<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Index;
use App\Livewire\Domains\Show;
use App\Models\ClientUser;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
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
use Livewire\Livewire;
use Tests\TestCase;

/**
 * D7-2: the customer's domains in the client area. Seen with `domains`, managed with `managedomains`: auto renew (off =
 * do not renew), nameservers, transfer lock, ID protection and the EPP code at the registrar (logged with the customer's
 * login), and «Renew» (its next period's invoice). Never another account's, never an ended one's.
 */
class DomainPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainRegistrarConnection $op;

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(array $attributes = [], ?User $owner = null): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => ($owner ?? $this->user)->client()->id, 'sld' => 'nixpal', 'tld' => 'com',
            'fqdn' => 'nixpal.com', 'domain_tld_id' => DomainTld::query()->value('id'), 'registrar_connection_id' => $this->op->id,
            'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active, 'expires_at' => '2027-11-17', 'currency_code' => 'EUR',
            'years' => 1, 'auto_renew' => true, 'transfer_lock' => true, 'id_protection' => false]);
    }

    /** A login of the same account with only these permissions. */
    private function member(array $permissions): User
    {
        $member = User::factory()->create();
        ClientUser::query()->create(['client_id' => $this->user->client()->id, 'user_id' => $member->id, 'granted_at' => now(), 'permissions' => $permissions]);

        return $member;
    }

    /** As the client area works: the login, on this account (CurrentClient). */
    private function workOn(User $user): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $this->user->client()->id]));
    }

    /** Openprovider: logs in, takes changes, gives the EPP code. */
    private function fakeOp(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
                str_contains($url, '/authcode') => Http::response(['data' => ['auth_code' => 'Epp-Code-77']]),
                $request->method() === 'PUT' => Http::response(['data' => ['success' => true]]),
                default => Http::response(['data' => ['id' => 30391502, 'status' => 'ACT', 'expiration_date' => '2027-11-17 00:00:00',
                    'name_servers' => [['name' => 'ns1.myip.gr'], ['name' => 'ns2.myip.gr']]]]),
            };
        });
    }

    public function test_the_list_shows_the_accounts_own_domains_by_status(): void
    {
        $this->domain();
        $this->domain(['sld' => 'old', 'fqdn' => 'old.com', 'status' => DomainStatus::Grace, 'expires_at' => '2026-10-01']);
        $this->domain(['sld' => 'other', 'fqdn' => 'other.com'], User::factory()->create());

        Livewire::actingAs($this->user)->test(Index::class)
            ->assertSee('nixpal.com')->assertSee('old.com')->assertDontSee('other.com')
            ->call('filter', 'expired')->assertSee('old.com')->assertDontSee('nixpal.com');

        $this->workOn($this->member(['invoices']))->get(route('domains'))->assertForbidden();
        $this->workOn($this->user)->get(route('domains'))->assertOk();
    }

    public function test_another_accounts_domain_is_not_seen_and_only_managedomains_manages(): void
    {
        $domain = $this->domain();
        // Another account's: not found, as a service's (its existence is not told).
        $this->workOn($this->member(['invoices']))->get(route('domains.show', $domain))->assertNotFound();

        $viewer = $this->member(['domains']);
        $this->workOn($viewer)->get(route('domains.show', $domain))->assertOk()->assertSee('nixpal.com');
        // Refused as the client area refuses: not found (nothing told).
        Livewire::actingAs($viewer)->test(Show::class, ['domain' => $domain])->call('toggleAutoRenew')->assertStatus(404);
        $this->assertTrue($domain->fresh()->auto_renew);

        Livewire::actingAs($this->member(['domains', 'managedomains']))->test(Show::class, ['domain' => $domain])->call('toggleAutoRenew');
        $this->assertFalse($domain->fresh()->auto_renew, 'off: do not renew');
    }

    public function test_an_ended_domain_is_seen_but_not_managed(): void
    {
        $domain = $this->domain(['status' => DomainStatus::TransferredAway]);

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('toggleAutoRenew')->assertStatus(404);
        $this->assertTrue($domain->fresh()->auto_renew);
    }

    public function test_nameservers_change_at_the_registrar_logged_with_the_customers_login(): void
    {
        $domain = $this->domain();
        $this->fakeOp();

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
            ->set('nameservers', [['host' => 'ns1.example.net'], ['host' => 'NS2.example.net']])->call('saveNameservers')->assertHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_contains(json_encode($request->data()), 'ns2.example.net'));
        $log = DomainRegistrarLog::query()->latest('id')->first();
        $this->assertSame([$this->user->id, null], [$log->user_id, $log->staff_id]);

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
            ->set('nameservers', [['host' => 'only-one.example.net']])->call('saveNameservers')->assertHasErrors('nameservers');
    }

    public function test_the_epp_code_is_read_when_asked_never_kept_and_limited(): void
    {
        $domain = $this->domain();
        $this->fakeOp();

        $component = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain]);
        $component->assertDontSee('Epp-Code-77');
        $this->assertSame('Epp-Code-77', $component->instance()->revealEppCode());
        $this->assertStringNotContainsString('Epp-Code-77', json_encode(DomainRegistrarLog::query()->get()) . json_encode($domain->fresh()));

        for ($i = 0; $i < 10; $i++) {
            $component->instance()->revealEppCode();
        }
        $this->assertNull($component->instance()->revealEppCode(), 'at most 10 a minute per login');
    }

    public function test_renew_makes_its_next_periods_invoice_once_and_turns_auto_renew_back_on(): void
    {
        $domain = $this->domain(['auto_renew' => false]);

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('renew')->assertRedirect();

        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->sole();
        $this->assertSame(['2027-11-17', '2028-11-17', Invoice::STATUS_UNPAID], [$line->period_start->toDateString(), $line->period_end->toDateString(), $line->invoice->status]);
        $this->assertTrue($domain->fresh()->auto_renew, 'asking to renew says so');

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain->fresh()])->call('renew')
            ->assertRedirect(route('invoices.show', $line->invoice));
        $this->assertSame(1, InvoiceItem::query()->where('reference_id', $domain->id)->count(), 'its open invoice, not a second one');
    }

    public function test_a_manual_registrar_shows_the_domain_but_offers_no_registrar_changes(): void
    {
        $domain = $this->domain(['registrar_connection_id' => null]);

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
            ->assertSee(__('domains.portal.not_manageable'))
            ->call('toggleTransferLock');
        Http::assertNothingSent();
    }

    public function test_renew_needs_the_invoices_too_and_a_refusal_keeps_do_not_renew(): void
    {
        // Review R3 of #176: «Renew» makes an invoice (and applies credit): only for who sees the invoices it leads to.
        $domain = $this->domain(['auto_renew' => false]);
        $manager = $this->member(['domains', 'managedomains']);
        $this->workOn($manager)->get(route('domains.show', $domain))->assertOk()->assertDontSeeHtml('wire:click="renew"');
        Livewire::actingAs($manager)->test(Show::class, ['domain' => $domain])->call('renew')->assertStatus(404);
        $this->assertSame(0, InvoiceItem::query()->count());

        // R9: nothing billed (domain billing off), its «do not renew» stays.
        config(['settings.domain_billing_enabled' => false]);
        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('renew')
            ->assertNoRedirect();
        $this->assertFalse($domain->fresh()->auto_renew);
    }
}
