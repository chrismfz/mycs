<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Index;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Second-round adversarial review of the bulk actions of the client-area domain list (PR #354, feat/domain-bulk): renew together on
 * one invoice, auto renew on/off, nameservers, transfer lock; against the fix commit 20c100ee. Each R2-n test fails on
 * that head.
 */
class DomainBulkRound2AdversarialTest extends TestCase
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
        // Told now, or kept for the page it redirects to (notify(..., redirect: true): the session's «notification»).
        return collect(data_get($page->effects, 'dispatches', []))->where('name', 'notify')
            ->map(fn ($d) => (string) ($d['params'][0]['message'] ?? ''))->values()
            ->when(session('notification.message') !== null, fn ($told) => $told->push((string) session('notification.message')))->all();
    }

    private function renewalLines(): int
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->whereNull('kind')->count();
    }

    // ---------------------------------------------------------------- findings (fail on 20c100ee)

    /**
     * R2-1 (high, money; a regression of the CR-1/ADV-2 fix). «Renew together» bills, and InvoiceSelectedItems applies the
     * account's credit at once (CreditAutoApply::forCustomer). When the credit covers it, the invoice is PAID before the
     * re-read; the re-read only counts an UNPAID invoice, so each domain just paid for is told «not renewed», auto renew is
     * set back OFF on those it turned on (next year they are never billed and expire), and there is no redirect. The
     * round-1 code took `billed` from the result and got this right; the single page (Show::renew) still does. Fix: in
     * the re-read count a line on any live invoice (billedLine(..., payable: true) without the UNPAID filter, or the
     * `invoices`/`billed` of the result too), and redirect to a paid one as well.
     */
    public function test_r2_1_a_renewal_paid_by_credit_is_renewed_and_keeps_auto_renew(): void
    {
        // As the admin's setting (the config is loaded from it on each request).
        Setting::query()->updateOrCreate(['key' => 'credits_enabled', 'settingable_type' => null], ['value' => true, 'type' => 'boolean']);
        config(['settings.credits_enabled' => true, 'settings.credits_auto_use' => true]);
        (new CreditLedger)->add($this->user->client()->id, 'EUR', 10000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Test');
        $off = $this->domain('alpha', ['auto_renew' => false]);
        $on = $this->domain('beta');

        $page = Livewire::actingAs($this->user)->test(Index::class)->set('selected', [$off->id, $on->id])->call('bulkRenew');

        $invoice = InvoiceItem::query()->where('reference_type', Domain::class)->whereNull('kind')->with('invoice')->firstOrFail()->invoice;
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status, 'setup: the credit paid the renewal');
        $told = implode(' | ', $this->told($page));
        $this->assertStringNotContainsString('alpha.com', $told, 'paid from credit, and told «not renewed»');
        $this->assertTrue($off->fresh()->auto_renew, 'paid for its next year, and set back to «do not renew»');
        $page->assertRedirect(route('invoices.show', $invoice->id));
    }

    /**
     * R2-2 (low). togglePage() now stops at BULK_MAX, but its «all on this page ticked?» test still reads the page's
     * ids: once the cap cut a page short, the page is never «all ticked», so the header box only re-ticks (a no-op) and
     * never unticks the page again; the customer must untick one by one or «clear». Fix: untick the page when any of its
     * ids is ticked and nothing more fits, or compare against what fits.
     */
    public function test_r2_2_the_page_box_unticks_a_page_the_cap_cut_short(): void
    {
        $others = [];
        foreach (range(1, 15) as $i) {
            $others[] = (string) $this->domain(sprintf('a%02d', $i))->id;
        }
        $pageIds = [];
        foreach (range(1, 10) as $i) {
            $pageIds[] = (string) $this->domain(sprintf('b%02d', $i))->id;
        }

        $page = Livewire::actingAs($this->user)->test(Index::class)->set('selected', $others)
            ->call('togglePage', $pageIds)->call('togglePage', $pageIds);

        $this->assertSame([], array_values(array_intersect(array_map('strval', $page->get('selected')), $pageIds)),
            'the page box clicked twice: the page is still ticked');
    }

    /**
     * R2-3 (low). The BULK_MAX refusal counts the raw ids, before ticked() drops another account's and the ended ones.
     * A domain ticked and then ended (or transferred away) stays in `selected` with no box to untick it (the view draws
     * none for an ended row), the bar says «20 selected», and every action is refused «at most 20: untick some». Fix:
     * count what ticked() would act on (the account's live ones), as tickedCount does.
     */
    public function test_r2_3_an_ended_domain_left_in_the_selection_does_not_push_it_over_the_cap(): void
    {
        $ids = [];
        foreach (range(1, Index::BULK_MAX) as $i) {
            $ids[] = $this->domain(sprintf('d%02d', $i))->id;
        }
        $ended = $this->domain('gone', ['status' => DomainStatus::Cancelled]);

        Livewire::actingAs($this->user)->test(Index::class)->set('selected', [...$ids, $ended->id])->call('bulkAutoRenew', false);

        $this->assertSame(0, Domain::query()->whereKey($ids)->where('auto_renew', true)->count(),
            'BULK_MAX live domains ticked (as the bar says), refused for an ended one nobody can untick');
    }

    /**
     * R2-4 (low). atRegistrarEach() hits the `domain-portal` limiter before it knows there is a registrar call: a domain
     * already locked (no call), one not manageable, one whose registrar cannot lock, each spends one of the login's 10 a
     * minute. Ten already locked and one not: «Lock» sends nothing, yet the eleventh is «not tried (too many changes)»,
     * and the single pages are blocked for a minute too. Fix: hit the limiter only right before an actual registrar call.
     */
    public function test_r2_4_changes_with_no_registrar_call_do_not_spend_the_limit(): void
    {
        $ids = [];
        foreach (range(1, 10) as $i) {
            $ids[] = $this->domain(sprintf('a%02d', $i), ['transfer_lock' => true])->id;
        }
        $open = $this->domain('zzz', ['transfer_lock' => false]);

        $page = Livewire::actingAs($this->user)->test(Index::class)->set('selected', [...$ids, $open->id])->call('bulkTransferLock', true);

        $this->assertStringNotContainsString('zzz.com: ' . __('domains.portal.bulk.not_tried_limit'), implode(' | ', $this->told($page)),
            'nothing was sent to the registrar, and the one to lock is «not tried: too many changes»');
        $this->assertTrue($open->fresh()->transfer_lock);
    }

    /**
     * R2-5 (low). BULK_MAX is 20 but the per-registrar-change `domain-portal` limit is 10 a minute: any bulk of 11 to 20
     * registrar changes, even the first of the day, always leaves the rest «not tried» (and the 6-a-quarter-hour bulk
     * limit is spent by the retry). The bar lets 20 be ticked for an action that can never do 20. Fix: one budget that
     * fits BULK_MAX (e.g. a separate bulk budget for the registrar calls, or BULK_MAX at 10 for the registrar actions),
     * or say before the action that only 10 are done a minute.
     */
    public function test_r2_5_a_first_bulk_of_bulk_max_nameserver_changes_is_done_in_full(): void
    {
        $ids = [];
        foreach (range(1, Index::BULK_MAX) as $i) {
            $ids[] = $this->domain(sprintf('d%02d', $i))->id;
        }

        $page = Livewire::actingAs($this->user)->test(Index::class)->set('selected', $ids)
            ->set('bulkNameservers', ['ns1.example.net', 'ns2.example.net'])->call('bulkSetNameservers');

        $this->assertStringNotContainsString(__('domains.portal.bulk.not_tried_limit'), implode(' | ', $this->told($page)),
            'a first bulk of BULK_MAX, and half of it «not tried»');
    }

    /**
     * R2-6 (low, performance). The bar's `bulk` now loads every domain of the account on each render (and each
     * wire:model.live tick of a box) to find one live one: CurrentClient::model()->domains()->get()->first(...). For
     * myip's resellers (hundreds of domains) every click hydrates them all. Fix: a query with the terminal statuses left
     * out and ->first() in SQL (whereNotIn('status', terminalValues())->first()).
     */
    public function test_r2_6_a_render_does_not_load_every_domain_of_the_account(): void
    {
        config(['settings.pagination' => 10]);
        foreach (range(1, 60) as $i) {
            $this->domain(sprintf('d%02d', $i));
        }
        $loaded = 0;
        Domain::retrieved(function () use (&$loaded) {
            $loaded++;
        });

        Livewire::actingAs($this->user)->test(Index::class);

        $this->assertLessThan(60, $loaded, "one render loaded {$loaded} domains for a page of 10");
    }
}
