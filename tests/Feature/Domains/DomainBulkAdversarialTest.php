<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Index;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\InvoiceItem;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\InvoiceSelectedItems;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Adversarial review of the bulk actions of the client-area domain list (PR #354, feat/domain-bulk): renew together on
 * one invoice, auto renew on/off, nameservers, transfer lock. Each ADV-n test fails on the reviewed code; the others are
 * regressions that pass (attacks tried and refused).
 */
class DomainBulkAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainRegistrarConnection $op;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
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

    /** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private function writes(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c[0] === 'PUT'));
    }

    /** @return list<string> every notify message the last call dispatched */
    private function told(Testable $page): array
    {
        return collect(data_get($page->effects, 'dispatches', []))->where('name', 'notify')
            ->map(fn ($d) => (string) ($d['params'][0]['message'] ?? ''))->values()->all();
    }

    private function renewalLines(): int
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->whereNull('kind')->count();
    }

    // ---------------------------------------------------------------- findings (fail on the reviewed code)

    /**
     * ADV-1 (medium, money). More than BULK_MAX ticked (the selection survives paging and filters: three pages of 10 is
     * 30) and «Renew together»: ticked() keeps the first BULK_MAX ids silently, so some domains are not billed and the
     * customer is told nothing (no notify, a redirect to the invoice as if all were on it). They believe them renewed and
     * the domains expire. Fix: refuse a selection over BULK_MAX with a message (or name those left out); never drop
     * silently. (The other actions say «for 20 domain(s)» with 23 ticked: the same silent cut.)
     */
    public function test_adv1_renewing_more_than_bulk_max_never_drops_domains_silently(): void
    {
        $ids = [];
        foreach (range(1, Index::BULK_MAX + 3) as $i) {
            $ids[] = $this->domain(sprintf('d%02d', $i))->id;
        }

        $page = Livewire::actingAs($this->user)->test(Index::class)->set('selected', $ids)->call('bulkRenew');

        $billed = InvoiceItem::query()->where('reference_type', Domain::class)->whereNull('kind')->pluck('reference_id')->all();
        $left = Domain::query()->whereKey(array_diff($ids, $billed))->pluck('fqdn')->all();
        $told = implode(' | ', $this->told($page));
        $unmentioned = array_values(array_filter($left, fn (string $fqdn) => !str_contains($told, $fqdn)));
        $this->assertTrue($left === [] || $unmentioned === [] || str_contains($told, (string) Index::BULK_MAX),
            'ticked, not billed, and not told: ' . implode(', ', $unmentioned));
    }

    /**
     * ADV-2 (medium, money). Two clicks at once (a double click, two tabs): both read the domain with auto renew off, both
     * turn it on; the first bills it, the second finds it billed (not in its `billed`) and «restores» auto renew OFF. The
     * domain stays on an unpaid renewal invoice with auto renew off, and CreditAutoApply / AutoCharge skip invoices of a
     * domain with auto renew off: the credit or the saved card never pay it, and it expires. The interleaving is replayed
     * deterministically: the other request runs while this one turns auto renew on. Fix: decide the restore from the
     * database under the client's selecting lock (re-read the domain; restore only when billedLine() finds no live line),
     * not from the labels InvoiceSelectedItems returned.
     */
    public function test_adv2_a_concurrent_click_does_not_leave_a_billed_domain_with_auto_renew_off(): void
    {
        $x = $this->domain('alpha', ['auto_renew' => false]);
        $fired = false;
        Domain::updating(function (Domain $domain) use (&$fired, $x) {
            if ($fired || $domain->id !== $x->id || !$domain->isDirty('auto_renew') || !$domain->auto_renew) {
                return;
            }
            $fired = true;
            // The other request, a moment ahead: turned it on and billed it (as bulkRenew does).
            Domain::query()->whereKey($x->id)->update(['auto_renew' => true]);
            (new InvoiceSelectedItems)->handle([], [Domain::query()->findOrFail($x->id)]);
        });

        Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$x->id])->call('bulkRenew');

        $this->assertTrue($fired);
        $this->assertSame(1, $this->renewalLines(), 'billed once');
        $this->assertTrue($x->fresh()->auto_renew, 'on an unpaid renewal invoice with auto renew off: credit and auto charge skip it');
    }

    /**
     * ADV-3 (low). The bulk registrar actions do not count against the single page's per-login `domain-portal` limiter
     * (10 a minute): a login that has used it up on the domain pages still sends up to BULK_MAX x 6 registrar writes a
     * quarter-hour through the list, on top. Fix: hit (and check) `domain-portal` once per registrar call in
     * atRegistrarEach too, or document the separate budget.
     */
    public function test_adv3_bulk_registrar_writes_respect_the_single_pages_limiter(): void
    {
        $a = $this->domain('alpha');
        for ($i = 0; $i < 10; $i++) {
            RateLimiter::hit('domain-portal:' . $this->user->id, 60);
        }

        Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$a->id])
            ->set('bulkNameservers', ['ns1.example.net', 'ns2.example.net'])->call('bulkSetNameservers');

        $this->assertSame([], $this->writes(), 'the login is at its registrar limit: the list must not be a way around it');
    }

    /**
     * ADV-4 (low). A domain ticked with no expiry yet (being registered or transferred in) is dropped from «Renew
     * together» before anything else, without a word, while the others are billed and the page goes to their invoice: the
     * customer thinks it is renewed with them. Fix: name it in `not_renewed` as any other not billed.
     */
    public function test_adv4_a_ticked_domain_without_expiry_is_told_not_renewed(): void
    {
        $a = $this->domain('alpha');
        $pending = $this->domain('pending', ['status' => DomainStatus::PendingTransfer, 'expires_at' => null]);

        $page = Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$a->id, $pending->id])->call('bulkRenew');

        $this->assertSame(1, $this->renewalLines());
        $this->assertStringContainsString('pending.com', implode(' | ', $this->told($page)), 'not renewed and not told');
    }

    /**
     * ADV-5 (low). `selected` is a client-writable array of anything: a nested array (`[[1]]`) makes every render a 500
     * (the view's array_map('strval', $selected): «Array to string conversion»), and togglePage() too; in ticked(),
     * intval([x]) is 1, so it acts on domain id 1. Fix: normalise `selected` in an updatedSelected() hook (scalars only,
     * positive ints, as strings) or type it with a #[Locked]-free cast; never strval() what the browser sent.
     */
    public function test_adv5_a_crafted_selection_does_not_break_the_page(): void
    {
        $this->domain('alpha');

        Livewire::actingAs($this->user)->test(Index::class)->set('selected', [['1'], 'x'])->assertOk()
            ->call('togglePage', ['1'])->assertOk();
    }

    // ---------------------------------------------------------------- regressions (attacks tried, refused)

    /** Another account's ids through «select page» (togglePage) are never acted on. */
    public function test_toggle_page_with_another_accounts_ids_acts_on_nothing_of_theirs(): void
    {
        $theirs = $this->domain('theirs', ['auto_renew' => true, 'transfer_lock' => true], User::factory()->create());

        Livewire::actingAs($this->user)->test(Index::class)->call('togglePage', [$theirs->id, -5, PHP_INT_MAX, '9e9'])
            ->call('bulkAutoRenew', false)->call('bulkTransferLock', false)->call('bulkRenew');

        $this->assertTrue($theirs->fresh()->auto_renew);
        $this->assertSame([], $this->writes());
        $this->assertSame(0, InvoiceItem::query()->count());
    }

    /** Staff in «Login as client» need admin.invoices.create to renew, admin.domains.manage for the registrar. */
    public function test_impersonating_staff_need_the_admin_permissions(): void
    {
        $a = $this->domain('alpha', ['transfer_lock' => true]);
        session(['impersonating' => $this->user->id, CurrentClient::SESSION_KEY => $this->user->client()->id, CurrentClient::IMPERSONATING_KEY => $this->user->client()->id]);
        Auth::guard('staff')->setUser(Staff::factory()->withPermissions(['admin.users.impersonate', 'admin.domains.update'])->create());
        Auth::guard('web')->setUser($this->user);

        foreach (['bulkRenew' => [], 'bulkTransferLock' => [false], 'bulkSetNameservers' => []] as $method => $args) {
            $component = app(Index::class);
            $component->selected = [(string) $a->id];
            $component->bulkNameservers = ['ns1.example.net', 'ns2.example.net'];
            try {
                $component->{$method}(...$args);
                $this->fail("{$method} without its admin permission");
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }

        $this->assertSame(0, InvoiceItem::query()->count());
        $this->assertSame([], $this->writes());
    }

    /** Odd nameserver shapes never reach the registrar. */
    public function test_odd_nameservers_are_refused(): void
    {
        $a = $this->domain('alpha');
        $bad = [
            ['ns1.ok.net', "ns2.ok.net\nns3.evil.net"],
            ['ns1.ok.net', '<script>x</script>.net'],
            ['ns1.ok.net', ['ns2.ok.net']],
            array_fill(0, 14, 'ns.ok.net'),
            ['ns1.ok.net', 'NS1.OK.NET'],
            ['ns1.ok.net', 'νς.παράδειγμα.gr'],
            ['ns1.ok.net', ''],
        ];
        foreach ($bad as $i => $nameservers) {
            RateLimiter::clear('domain-bulk:' . $this->user->id);
            Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$a->id])->set('bulkNameservers', $nameservers)->call('bulkSetNameservers');
            $this->assertSame([], $this->writes(), "shape {$i}");
        }
    }

    /** Two currencies: one invoice each, never mixed. */
    public function test_two_currencies_two_invoices(): void
    {
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'suffix' => 'USD', 'format' => '1,000.00']);
        DomainTldPrice::query()->create(['domain_tld_id' => DomainTld::query()->value('id'), 'operation' => 'renew', 'years' => 1, 'currency_code' => 'USD', 'price' => '25.00']);
        $a = $this->domain('alpha');
        $b = $this->domain('beta', ['currency_code' => 'USD']);

        Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$a->id, $b->id])->call('bulkRenew');

        $lines = InvoiceItem::query()->where('reference_type', Domain::class)->whereNull('kind')->with('invoice')->get();
        $this->assertCount(2, $lines);
        $this->assertCount(2, $lines->pluck('invoice_id')->unique());
        foreach ($lines as $line) {
            $this->assertSame($line->reference_id === $a->id ? 'EUR' : 'USD', $line->invoice->currency_code);
        }
    }
}
