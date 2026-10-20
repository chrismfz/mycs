<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Index as DomainsIndex;
use App\Livewire\Invoices\Index as InvoicesIndex;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CurrentClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P4b of the portal (owner 2026-10-19): the home page says what waits on the customer; the invoices say what they are
 * for, each with its PDF; the lists keep their filter; a domain's auto renew is turned from its row; a ticket's status
 * says who it waits on.
 */
class PortalAttentionAndListsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR']);
    }

    private function workOn(User $user, ?Client $client = null): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => ($client ?? $user->client())->id]));
    }

    /** @param  list<string>  $lines */
    private function invoice(Client $client, string $status, array $lines = ['Hosting'], ?string $due = '2026-10-25'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => $due]);
        foreach ($lines as $line) {
            InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => 10, 'description' => $line]);
        }
        // Created unpaid (InvoiceObserver); the state the list shows, set as it is stored.
        Invoice::query()->whereKey($invoice->id)->update(['status' => $status]);

        return $invoice->fresh();
    }

    private function domain(Client $client, string $fqdn, string $expires, bool $autoRenew, DomainStatus $status = DomainStatus::Active): Domain
    {
        [$sld, $tld] = explode('.', $fqdn, 2);
        $domainTld = DomainTld::query()->firstOrCreate(['tld' => $tld], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);

        return Domain::factory()->create(['client_id' => $client->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn, 'domain_tld_id' => $domainTld->id,
            'status' => $status, 'expires_at' => $expires, 'auto_renew' => $autoRenew]);
    }

    // ===================================================================================  «Needs your attention»

    public function test_nothing_waiting_says_all_good(): void
    {
        $user = User::factory()->create();

        $this->workOn($user)->get(route('dashboard'))->assertOk()
            ->assertSee(__('dashboard.attention.title'))->assertSee(__('dashboard.attention.all_good'));
    }

    public function test_it_lists_what_waits_on_the_customer_and_only_that(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        $this->invoice($client, Invoice::STATUS_UNPAID, due: '2026-10-01');
        $this->invoice($client, Invoice::STATUS_UNPAID, due: '2026-11-01');
        $this->invoice($client, Invoice::STATUS_PAID);
        $this->domain($client, 'ending.gr', '2026-11-05', autoRenew: false);
        $this->domain($client, 'renewing.gr', '2026-11-05', autoRenew: true);
        $this->domain($client, 'later.gr', '2027-01-05', autoRenew: false);
        $this->domain($client, 'gone.gr', '2026-11-05', autoRenew: false, status: DomainStatus::Cancelled);
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway']);
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa 4242',
            'external_reference' => 'pm_1', 'type' => 'card', 'expiry' => '2026-11-30']);
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa 9999',
            'external_reference' => 'pm_2', 'type' => 'card', 'expiry' => '2027-05-31']);
        Ticket::factory()->create(['client_id' => $client->id, 'status' => 'answered', 'subject' => 'Mail bounce']);
        Ticket::factory()->create(['client_id' => $client->id, 'status' => 'open', 'subject' => 'Slow site']);

        $this->workOn($user)->get(route('dashboard'))->assertOk()
            ->assertDontSee(__('dashboard.attention.all_good'))
            ->assertSee(trans_choice('dashboard.attention.unpaid', 2, ['count' => 2, 'amount' => '20,00']), false)
            ->assertSee(__('dashboard.attention.unpaid_overdue', ['date' => '01/10/2026']))
            ->assertSee(route('invoices', ['status' => 'unpaid']), false)
            ->assertSee(__('dashboard.attention.domain_ends', ['domain' => 'ending.gr', 'date' => '05/11/2026']))
            ->assertDontSee('gone.gr')
            ->assertSee(__('dashboard.attention.card_expires', ['name' => 'Visa 4242', 'date' => '11/2026']))
            ->assertDontSee('Visa 9999')
            ->assertSee(trans_choice('dashboard.attention.tickets', 1))->assertSee('Mail bounce');

        // A domain that renews, or ends later, is not in the box (P4d lists the renewing ones under «Upcoming renewals»).
        $html = $this->workOn($user)->get(route('dashboard'))->getContent();
        $box = substr($html, $start = (int) strpos($html, 'id="attention-title"'), (int) strpos($html, '</section>', $start) - $start);
        $this->assertStringNotContainsString('renewing.gr', $box);
        $this->assertStringNotContainsString('later.gr', $box);
    }

    public function test_a_member_sees_only_the_areas_of_their_grant(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $this->invoice($client, Invoice::STATUS_UNPAID);
        $this->domain($client, 'ending.gr', '2026-11-05', autoRenew: false);
        $member = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $member->id, 'permissions' => ['domains'], 'granted_at' => now()]);

        $this->workOn($member, $client)->get(route('dashboard'))->assertOk()
            ->assertSee('ending.gr')
            ->assertDontSee(trans_choice('dashboard.attention.unpaid', 1, ['count' => 1, 'amount' => '10,00']), false);
    }

    public function test_a_recoverable_domain_and_a_recent_card_are_told_an_old_card_is_not(): void
    {
        // Code review of #230: an expired domain still recoverable is the most urgent; a card that ended years ago is not news.
        $user = User::factory()->create();
        $client = $user->client();
        $this->domain($client, 'grace.gr', '2026-10-10', autoRenew: false, status: DomainStatus::Grace);
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway']);
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa 1111',
            'external_reference' => 'pm_1', 'type' => 'card', 'expiry' => '2026-09-30']);
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa 2222',
            'external_reference' => 'pm_2', 'type' => 'card', 'expiry' => '2023-01-31']);
        $this->invoice($client, Invoice::STATUS_UNPAID, due: '2026-10-05');

        $this->workOn($user)->get(route('dashboard'))->assertOk()
            ->assertSee(__('dashboard.attention.domain_ended', ['domain' => 'grace.gr', 'date' => '10/10/2026']))
            ->assertSee(__('dashboard.attention.card_expired', ['name' => 'Visa 1111', 'date' => '09/2026']))
            ->assertDontSee('Visa 2222')
            ->assertSee(__('dashboard.attention.unpaid_overdue_one', ['date' => '05/10/2026']));
    }

    // ===================================================================================  invoices

    public function test_an_invoice_row_says_what_it_is_for_with_its_pdf(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoice($user->client(), Invoice::STATUS_UNPAID, ["Hosting Business20\n(20/10/2026 - 19/11/2026)", 'example.gr · renewal', 'Static IP', 'Static IP'], due: '2026-10-01');

        $this->workOn($user)->get(route('invoices'))->assertOk()
            ->assertSee('Hosting Business20')->assertDontSee('(20/10/2026 - 19/11/2026)')
            ->assertSee('example.gr · renewal')
            ->assertSee(trans_choice('invoices.more_lines', 1, ['count' => 1]))  // Static IP once, beyond the first two
            ->assertSee(__('invoices.overdue_since', ['date' => '01/10/2026']))
            ->assertSee(route('invoices.pdf', $invoice), false);
    }

    public function test_the_pdf_is_the_accounts_own_and_never_a_draft(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoice($user->client(), Invoice::STATUS_PAID);
        $draft = $this->invoice($user->client(), Invoice::STATUS_DRAFT);
        $other = $this->invoice(User::factory()->create()->client(), Invoice::STATUS_PAID);

        $response = $this->workOn($user)->get(route('invoices.pdf', $invoice));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->streamedContent());

        $this->workOn($user)->get(route('invoices.pdf', $other))->assertNotFound();
        $this->workOn($user)->get(route('invoices.pdf', $draft))->assertNotFound();
    }

    public function test_the_invoice_filter_is_kept_unless_the_link_says_another(): void
    {
        $user = User::factory()->create();
        $unpaid = $this->invoice($user->client(), Invoice::STATUS_UNPAID, ['Unpaid thing']);
        $paid = $this->invoice($user->client(), Invoice::STATUS_PAID, ['Paid thing']);
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $user->client()->id]));

        Livewire::test(InvoicesIndex::class)->call('filter', 'paid')
            ->assertSee('Paid thing')->assertDontSee('Unpaid thing');
        $this->assertSame('paid', session('portal.filters.invoices'));

        Livewire::test(InvoicesIndex::class)->assertSet('status', 'paid')->assertDontSee('Unpaid thing');
        Livewire::withQueryParams(['status' => 'unpaid'])->test(InvoicesIndex::class)->assertSet('status', 'unpaid')->assertSee('Unpaid thing');
        Livewire::withQueryParams(['status' => 'bogus'])->test(InvoicesIndex::class)->assertSet('status', '');
        $this->assertNotNull($unpaid->id + $paid->id);
    }

    public function test_a_links_filter_is_kept_and_mass_pay_is_only_where_unpaid_ones_are(): void
    {
        $user = User::factory()->create();
        $this->invoice($user->client(), Invoice::STATUS_UNPAID);
        $this->invoice($user->client(), Invoice::STATUS_UNPAID);
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $user->client()->id]));

        Livewire::withQueryParams(['status' => 'unpaid'])->test(InvoicesIndex::class)->assertSee(__('invoices.mass_pay_hint'));
        $this->assertSame('unpaid', session('portal.filters.invoices'), 'what the list showed last');
        Livewire::test(InvoicesIndex::class)->set('selected', ['1'])->call('filter', 'paid')
            ->assertSet('selected', [])->assertDontSee(__('invoices.mass_pay_hint'));
    }

    // ===================================================================================  domains

    public function test_auto_renew_is_turned_from_the_row(): void
    {
        $user = User::factory()->create();
        $domain = $this->domain($user->client(), 'mine.gr', '2027-01-05', autoRenew: true);
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $user->client()->id]));

        Livewire::test(DomainsIndex::class)->assertSeeHtml('role="switch"')
            ->call('toggleAutoRenew', $domain->id)->assertDispatched('notify');
        $this->assertFalse($domain->fresh()->auto_renew);
    }

    public function test_auto_renew_from_the_row_is_only_the_accounts_own_domain_with_managedomains(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $domain = $this->domain($client, 'mine.gr', '2027-01-05', autoRenew: true);
        $foreign = $this->domain(User::factory()->create()->client(), 'theirs.gr', '2027-01-05', autoRenew: true);
        $ended = $this->domain($client, 'ended.gr', '2026-01-05', autoRenew: true, status: DomainStatus::Cancelled);

        $this->actingAs($owner)->withSession($this->loginUser($owner, [CurrentClient::SESSION_KEY => $client->id]));
        Livewire::test(DomainsIndex::class)->call('toggleAutoRenew', $foreign->id)->assertNotFound();
        Livewire::test(DomainsIndex::class)->call('toggleAutoRenew', $ended->id)->assertNotFound(); // the portal refuses as not found
        $this->assertTrue($foreign->fresh()->auto_renew);
        $this->assertTrue($ended->fresh()->auto_renew);

        $member = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $member->id, 'permissions' => ['domains'], 'granted_at' => now()]);
        $this->actingAs($member)->withSession($this->loginUser($member, [CurrentClient::SESSION_KEY => $client->id]));
        Livewire::test(DomainsIndex::class)->assertDontSeeHtml('role="switch"')
            ->call('toggleAutoRenew', $domain->id)->assertNotFound();
        $this->assertTrue($domain->fresh()->auto_renew);
    }

    public function test_the_domain_filter_is_kept(): void
    {
        $user = User::factory()->create();
        $this->domain($user->client(), 'live.gr', '2027-01-05', autoRenew: true);
        $this->domain($user->client(), 'over.gr', '2026-01-05', autoRenew: true, status: DomainStatus::Cancelled);
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $user->client()->id]));

        Livewire::test(DomainsIndex::class)->call('filter', 'ended')->assertSee('over.gr')->assertDontSee('live.gr');
        Livewire::test(DomainsIndex::class)->assertSet('status', 'ended')->assertDontSee('live.gr');
    }

    // ===================================================================================  tickets

    public function test_a_tickets_status_says_who_it_waits_on(): void
    {
        $user = User::factory()->create();
        Ticket::factory()->create(['client_id' => $user->client()->id, 'status' => 'answered']);
        Ticket::factory()->create(['client_id' => $user->client()->id, 'status' => 'open']);

        $this->workOn($user)->get(route('tickets'))->assertOk()
            ->assertSee(__('ticket.statuses.answered'))->assertSee(__('ticket.statuses.open'));
    }
}
