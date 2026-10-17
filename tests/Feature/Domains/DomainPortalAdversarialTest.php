<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Index;
use App\Livewire\Domains\Show;
use App\Models\ClientUser;
use App\Models\Currency;
use App\Models\DebugLog;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of PR #176 (D7-2, the customer's domains in the client area). Failing tests prove a problem; passing
 * ones document an attack that is defended.
 */
class DomainPortalAdversarialTest extends TestCase
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

    private function member(array $permissions, ?User $owner = null): User
    {
        $member = User::factory()->create();
        ClientUser::query()->create(['client_id' => ($owner ?? $this->user)->client()->id, 'user_id' => $member->id, 'granted_at' => now(), 'permissions' => $permissions]);

        return $member;
    }

    private function workOn(User $user): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $this->user->client()->id]));
    }

    private function fakeOp(string $epp = 'Epp-Code-77'): void
    {
        Http::fake(function (Request $request) use ($epp) {
            $url = $request->url();

            return match (true) {
                str_contains($url, '/auth/login') => Http::response(['data' => ['token' => 'tok']]),
                str_contains($url, '/authcode') => Http::response(['data' => ['auth_code' => $epp]]),
                $request->method() === 'PUT' => Http::response(['data' => ['success' => true]]),
                default => Http::response(['data' => ['id' => 30391502, 'status' => 'ACT', 'expiration_date' => '2027-11-17 00:00:00',
                    'name_servers' => [['name' => 'ns1.myip.gr'], ['name' => 'ns2.myip.gr']]]]),
            };
        });
    }

    private function lines(Domain $domain): int
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->count();
    }

    // ---------------------------------------------------------------- findings (expected to fail until fixed)

    /**
     * F1. Staff in «Login as client» (AUTH-19: the actor is the staff member, never the customer they look through) change
     * the nameservers / read the EPP code: the registrar log says the customer asked (user_id = customer, staff_id null).
     */
    public function test_f1_login_as_client_is_logged_as_the_staff_member_not_the_customer(): void
    {
        $domain = $this->domain();
        $this->fakeOp();
        // «Login as client» as the browser has it: the staff login and the customer's, in one session (Livewire's test
        // helper keeps one login per call, so the component is called directly here).
        $as = function (Staff $staff) use ($domain): ?string {
            session(['impersonating' => $this->user->id, CurrentClient::SESSION_KEY => $this->user->client()->id]);
            Auth::guard('staff')->setUser($staff);
            Auth::guard('web')->setUser($this->user);
            $component = app(Show::class);
            $component->domain = $domain;
            $component->mount();

            return $component->revealEppCode();
        };

        // Since review R2 of #176 they also need admin.domains.epp, as in the admin: without it, nothing.
        try {
            $as(Staff::factory()->withPermissions(['admin.users.impersonate'])->create());
            $this->fail('read without admin.domains.epp');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $staff = Staff::factory()->withPermissions(['admin.users.impersonate', 'admin.domains.epp'])->create();
        $this->assertSame('Epp-Code-77', $as($staff));

        $log = DomainRegistrarLog::query()->where('action', 'epp_code')->sole();
        $this->assertSame([$staff->id, null], [$log->staff_id, $log->user_id], 'staff in «Login as client» read the EPP code; the log must not blame the customer');
    }

    /** F2. A crafted list URL (?status=anything) is a 500 (FILTERS[$status] undefined), not the unfiltered list. */
    public function test_f2_unknown_status_filter_in_the_url_does_not_error(): void
    {
        $this->domain();

        $this->workOn($this->user)->get(route('domains') . '?status=bogus')->assertOk()->assertSee('nixpal.com');
    }

    /**
     * F3. «Renew» on a domain set not to renew turns auto renew back on even when nothing is billed (a status it cannot be
     * renewed in, here pending transfer; domain billing off): the customer's «do not renew» is lost silently and the
     * cron then bills it once it is active.
     */
    public function test_f3_renew_that_bills_nothing_leaves_do_not_renew_as_it_was(): void
    {
        $pending = $this->domain(['status' => DomainStatus::PendingTransfer, 'auto_renew' => false]);
        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $pending])->call('renew');
        $this->assertSame(0, $this->lines($pending));
        $this->assertFalse($pending->fresh()->auto_renew, 'nothing renewed: «do not renew» stays');

        config(['settings.domain_billing_enabled' => false]);
        $active = $this->domain(['sld' => 'two', 'fqdn' => 'two.com', 'auto_renew' => false]);
        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $active])->call('renew');
        $this->assertSame(0, $this->lines($active));
        $this->assertFalse($active->fresh()->auto_renew, 'nothing renewed: «do not renew» stays');
    }

    /**
     * F4. «Renew» while the period is on a draft (staff preparing it, not the customer's to see): the error notification
     * names the draft invoice's number and status.
     */
    public function test_f4_renew_does_not_disclose_a_draft_invoice(): void
    {
        $domain = $this->domain();
        $draft = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2027-11-01']);
        $draft->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'description' => 'Renewal', 'quantity' => 1, 'price' => '23.33',
            'period_start' => '2027-11-17', 'period_end' => '2028-11-17']);
        DB::table('invoices')->where('id', $draft->id)->update(['status' => Invoice::STATUS_DRAFT]);

        $component = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('renew')->assertNoRedirect();
        $this->assertSame(1, $this->lines($domain), 'not billed twice');
        $this->assertStringNotContainsString('#' . $draft->id, json_encode($component->effects['dispatches'] ?? []), 'a draft is not the customer\'s to see');
    }

    // ---------------------------------------------------------------- defended attacks (pass)

    public function test_another_accounts_domain_cannot_be_opened_or_acted_on(): void
    {
        $other = User::factory()->create();
        $theirs = $this->domain(['sld' => 'theirs', 'fqdn' => 'theirs.com'], $other);
        $this->fakeOp();

        $this->workOn($this->user)->get(route('domains.show', $theirs))->assertNotFound();
        foreach (['toggleAutoRenew', 'toggleTransferLock', 'toggleIdProtection', 'revealEppCode', 'renew', 'saveNameservers'] as $action) {
            $test = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $theirs])->set('nameservers', [['host' => 'ns1.evil.net'], ['host' => 'ns2.evil.net']]);
            $test->call($action)->assertStatus(404);
        }
        Http::assertNothingSent();
        $this->assertSame(0, $this->lines($theirs));
        $this->assertTrue($theirs->fresh()->auto_renew);
    }

    /** Swapping the component's `domain` (its model id) for another account's: Livewire refuses to set a model property. */
    public function test_swapping_the_domain_model_property_is_refused(): void
    {
        $mine = $this->domain();
        $theirs = $this->domain(['sld' => 'theirs', 'fqdn' => 'theirs.com'], User::factory()->create());
        $this->fakeOp();

        // The update is ignored: the component still holds its own domain (the snapshot's, checksummed), and acts on it.
        $test = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $mine])->set('domain', $theirs->id);
        $this->assertSame($mine->id, $test->get('domain')->id);
        $test->call('toggleAutoRenew');
        $this->assertFalse($mine->fresh()->auto_renew);
        try {
            Livewire::actingAs($this->user)->test(Show::class, ['domain' => $mine])->set('domain.client_id', $theirs->client_id);
            $this->fail('a model attribute could be set');
        } catch (Throwable $e) {
            $this->assertStringNotContainsString('a model attribute could be set', $e->getMessage());
        }
        $this->assertTrue($theirs->fresh()->auto_renew);
        Http::assertNothingSent();
    }

    public function test_a_view_only_member_or_a_revoked_one_cannot_manage(): void
    {
        $domain = $this->domain();
        $this->fakeOp();
        $viewer = $this->member(['domains']);
        foreach (['toggleAutoRenew', 'toggleTransferLock', 'toggleIdProtection', 'revealEppCode', 'renew'] as $action) {
            Livewire::actingAs($viewer)->test(Show::class, ['domain' => $domain])->call($action)->assertStatus(404);
        }
        $this->workOn($viewer)->get(route('domains.show', $domain))->assertOk()->assertDontSee('revealEppCode')->assertDontSee('toggleAutoRenew');

        // A manager whose grant is revoked while the page is open.
        $manager = $this->member(['domains', 'managedomains']);
        $page = Livewire::actingAs($manager)->test(Show::class, ['domain' => $domain]);
        ClientUser::query()->where('user_id', $manager->id)->update(['revoked_at' => now()]);
        $page->call('revealEppCode')->assertStatus(404);

        Http::assertNothingSent();
        $this->assertSame(0, $this->lines($domain));
        $this->assertTrue($domain->fresh()->auto_renew);
    }

    public function test_an_ended_domain_gives_no_epp_code_no_invoice_no_changes(): void
    {
        $this->fakeOp();
        foreach ([DomainStatus::Cancelled, DomainStatus::TransferredAway, DomainStatus::Deleted] as $i => $status) {
            $domain = $this->domain(['sld' => "e{$i}", 'fqdn' => "e{$i}.com", 'status' => $status, 'auto_renew' => false]);
            foreach (['revealEppCode', 'renew', 'toggleTransferLock', 'toggleAutoRenew'] as $action) {
                Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call($action)->assertStatus(404);
            }
            $this->assertSame(0, $this->lines($domain));
            $this->assertFalse($domain->fresh()->auto_renew);
        }
        Http::assertNothingSent();
    }

    public function test_the_epp_code_is_not_in_the_html_snapshot_logs_audits_or_mail(): void
    {
        config(['settings.debug' => true]); // the HTTP debug log (RequestListener) is on
        $domain = $this->domain();
        $this->fakeOp('Very-Secret-Epp-9');

        $component = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('revealEppCode');
        $this->assertSame('Very-Secret-Epp-9', $component->effects['returns'][0] ?? null);
        $this->assertStringNotContainsString('Very-Secret-Epp-9', $component->html());
        $this->assertStringNotContainsString('Very-Secret-Epp-9', json_encode($component->snapshot));
        // A later round trip (any other action) does not bring it back.
        $component->call('addNameserver');
        $this->assertStringNotContainsString('Very-Secret-Epp-9', $component->html() . json_encode($component->snapshot));

        $stored = json_encode(DomainRegistrarLog::query()->get()) . json_encode(DebugLog::query()->get())
            . json_encode(DB::table('audits')->get()) . json_encode(EmailLog::query()->get()) . json_encode($domain->fresh());
        $this->assertStringNotContainsString('Very-Secret-Epp-9', $stored);
        $this->assertSame($this->user->id, DomainRegistrarLog::query()->where('action', 'epp_code')->sole()->user_id);
    }

    /** The limit is per login (as documented): impersonation shares the customer's bucket, a reload does not reset it. */
    public function test_the_registrar_limit_survives_a_new_page_and_impersonation(): void
    {
        $domain = $this->domain();
        $this->fakeOp();
        for ($i = 0; $i < 10; $i++) {
            Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('revealEppCode');
        }
        $this->assertNull(Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('revealEppCode')->effects['returns'][0] ?? null);
        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('toggleTransferLock');
        $this->assertTrue($domain->fresh()->transfer_lock, 'refused at the limit');

        $staff = Staff::factory()->withPermissions(['admin.users.impersonate'])->create();
        $this->actingAs($staff, 'staff')->withSession(['impersonating' => $this->user->id]);
        $this->assertNull(Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->call('revealEppCode')->effects['returns'][0] ?? null);
        $this->assertSame(10, DomainRegistrarLog::query()->where('action', 'epp_code')->count());
    }

    public function test_nameserver_injection_is_refused_and_nothing_extra_reaches_the_registrar(): void
    {
        $domain = $this->domain();
        $this->fakeOp();
        $bad = [
            [['host' => 'ns1.ok.net'], ['host' => "ns2.ok.net\nns3.evil.net"]],
            [['host' => 'ns1.ok.net'], ['host' => '<script>x</script>.net']],
            [['host' => 'ns1.ok.net'], ['host' => ['ns2.ok.net']]],
            array_fill(0, 14, ['host' => 'ns.ok.net']),
            [['host' => 'ns1.ok.net'], ['host' => 'ns1.ok.net']],
        ];
        foreach ($bad as $nameservers) {
            Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->set('nameservers', $nameservers)->call('saveNameservers');
        }
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');

        // Extra keys ride along unused: only the hosts reach the registrar.
        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])
            ->set('nameservers', [['host' => 'ns1.ok.net', 'ip' => '6.6.6.6', 'domain_id' => 999], ['host' => 'ns2.ok.net']])->call('saveNameservers');
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && !str_contains(json_encode($r->data()), '6.6.6.6'));
    }

    public function test_a_manual_registrar_domain_cannot_have_its_nameservers_or_epp_changed(): void
    {
        $domain = $this->domain(['registrar_connection_id' => null]);
        DB::table('domain_nameservers')->insert([['domain_id' => $domain->id, 'host' => 'ns1.keep.net', 'sort' => 0], ['domain_id' => $domain->id, 'host' => 'ns2.keep.net', 'sort' => 1]]);

        $test = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain])->assertDontSee('revealEppCode');
        $test->set('nameservers', [['host' => 'ns1.evil.net'], ['host' => 'ns2.evil.net']])->call('saveNameservers');
        $this->assertNull($test->call('revealEppCode')->effects['returns'][0] ?? null);
        Http::assertNothingSent();
        $this->assertSame(['ns1.keep.net', 'ns2.keep.net'], $domain->fresh()->nameservers->pluck('host')->all());
    }

    public function test_renew_twice_two_tabs_bills_once(): void
    {
        $domain = $this->domain();
        $tabA = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain]);
        $tabB = Livewire::actingAs($this->user)->test(Show::class, ['domain' => $domain]);

        $tabA->call('renew')->assertRedirect();
        $tabB->call('renew')->assertRedirect();
        $tabA->call('renew');
        $this->assertSame(1, $this->lines($domain));
        $this->assertSame(1, Invoice::query()->where('client_id', $this->user->client()->id)->count());
    }

    public function test_domain_fields_from_the_registrar_are_escaped(): void
    {
        // The model refuses such a name; written as a broken import or sync could.
        $domain = $this->domain();
        DB::table('domains')->where('id', $domain->id)->update(['fqdn' => '<img src=x onerror=alert(1)>.com']);
        DB::table('domain_nameservers')->insert(['domain_id' => $domain->id, 'host' => '<script>alert(2)</script>', 'sort' => 0]);

        $html = $this->workOn($this->user)->get(route('domains.show', $domain))->assertOk()->getContent();
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringNotContainsString('<script>alert(2)', $html);
        $list = $this->workOn($this->user)->get(route('domains'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<img src=x onerror', $list);
    }

    public function test_the_list_never_shows_another_accounts_domain_whatever_the_search(): void
    {
        $this->domain(['sld' => 'theirs', 'fqdn' => 'theirs.com'], User::factory()->create());
        $this->domain();

        Livewire::actingAs($this->user)->test(Index::class)->set('search', '%')->assertDontSee('theirs.com')
            ->set('search', 'theirs')->assertDontSee('theirs.com')
            ->set('status', 'active')->assertDontSee('theirs.com');
    }
}
