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
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CurrentClient;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Adversarial review of #230 (P4b): «needs your attention», the invoice PDF route, remembered filters, auto renew
 * from the domain's row. Tests named test_holds_… pass and pin what held.
 */
class PortalP4bAdversarialTest extends TestCase
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
    private function invoice(Client $client, string $status, array $lines = ['Hosting'], ?string $due = '2026-10-25', string $currency = 'EUR'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => $currency, 'due_at' => $due]);
        foreach ($lines as $line) {
            InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => 10, 'description' => $line]);
        }
        Invoice::query()->whereKey($invoice->id)->update(['status' => $status, 'due_at' => $due]);

        return $invoice->fresh();
    }

    private function domain(Client $client, string $fqdn, string $expires = '2027-01-05', bool $autoRenew = true, DomainStatus $status = DomainStatus::Active): Domain
    {
        [$sld, $tld] = explode('.', $fqdn, 2);
        $domainTld = DomainTld::query()->firstOrCreate(['tld' => $tld], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);

        return Domain::factory()->create(['client_id' => $client->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn, 'domain_tld_id' => $domainTld->id,
            'status' => $status, 'expires_at' => $expires, 'auto_renew' => $autoRenew]);
    }

    private function member(Client $client, array $permissions): User
    {
        $member = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $member->id, 'permissions' => $permissions, 'granted_at' => now()]);

        return $member;
    }

    private function card(Client $client, string $name, string $expiry, ?Gateway $gateway = null): BillingAgreement
    {
        $gateway ??= Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway']);

        return BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => $name,
            'external_reference' => 'pm_' . $name, 'type' => 'card', 'expiry' => $expiry]);
    }

    /** Staff in «Login as client» on the owner's account, with these admin permissions. */
    private function impersonate(User $owner, array $permissions): void
    {
        session(['impersonating' => $owner->id, CurrentClient::SESSION_KEY => $owner->client()->id, CurrentClient::IMPERSONATING_KEY => $owner->client()->id]);
        Auth::guard('staff')->setUser(Staff::factory()->withPermissions(array_merge(['admin.users.impersonate'], $permissions))->create());
        Auth::guard('web')->setUser($owner);
    }

    // ==========================================================================================  «Needs your attention»

    /** A login without any account (Attention needs a Client): EnsureClientAccount keeps it off the page. */
    public function test_holds_the_home_page_of_a_login_without_any_account_still_renders(): void
    {
        $user = User::factory()->withoutClient()->create();

        $this->actingAs($user)->withSession($this->loginUser($user))->get(route('dashboard'))->assertOk();
    }

    /** Same, for a member whose grant was revoked while the session still names the account. */
    public function test_holds_the_home_page_after_the_only_grant_was_revoked_still_renders(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $member = $this->member($client, ['invoices']);
        ClientUser::query()->where('user_id', $member->id)->update(['revoked_at' => now()]);

        $this->workOn($member, $client)->get(route('dashboard'))->assertOk();
    }

    /**
     * FINDING: an unpaid invoice without a due date sorts first (NULLs first) and becomes «the oldest»: the detail is
     * empty and the item is not urgent, though another unpaid invoice is overdue since 01/10.
     */
    public function test_an_invoice_without_a_due_date_does_not_hide_an_overdue_one(): void
    {
        $user = User::factory()->create();
        $this->invoice($user->client(), Invoice::STATUS_UNPAID, due: null);
        $this->invoice($user->client(), Invoice::STATUS_UNPAID, due: '2026-10-01');

        $this->workOn($user)->get(route('dashboard'))->assertOk()
            ->assertSee(__('dashboard.attention.unpaid_overdue', ['date' => '01/10/2026']));
    }

    public function test_holds_the_amount_is_what_remains_after_partial_payments_and_refunds(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoice($user->client(), Invoice::STATUS_UNPAID, ['A', 'B', 'C']); // 30,00
        $invoice->transactions()->create(['amount' => 10, 'refunded_amount' => 2]);          // 8,00 kept
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_UNPAID]);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);

        $this->workOn($user)->get(route('dashboard'))->assertOk()
            ->assertSee(trans_choice('dashboard.attention.unpaid', 1, ['count' => 1, 'amount' => '22,00']), false);
    }

    public function test_holds_mixed_currencies_are_summed_apart(): void
    {
        Currency::query()->firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $user = User::factory()->create();
        $this->invoice($user->client(), Invoice::STATUS_UNPAID);
        $this->invoice($user->client(), Invoice::STATUS_UNPAID, ['X', 'Y'], currency: 'USD');

        $page = $this->workOn($user)->get(route('dashboard'))->assertOk();
        $page->assertSee('10,00')->assertSee('20.00')->assertDontSee('30,00')->assertDontSee('30.00');
    }

    public function test_holds_a_member_with_only_tickets_sees_no_invoice_total_card_or_domain(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $this->invoice($client, Invoice::STATUS_UNPAID, ['Secret line'], due: '2026-10-01');
        $this->card($client, 'Visa 4242', '2026-10-31');
        $this->domain($client, 'ending.gr', '2026-11-05', autoRenew: false);
        Ticket::factory()->create(['client_id' => $client->id, 'status' => 'replied', 'subject' => 'Mail bounce']);
        $member = $this->member($client, ['tickets']);

        $this->workOn($member, $client)->get(route('dashboard'))->assertOk()
            ->assertSee('Mail bounce')
            ->assertDontSee('Visa 4242')->assertDontSee('ending.gr')->assertDontSee('10,00')
            ->assertDontSee(route('invoices', ['status' => 'unpaid']), false);
    }

    public function test_holds_tickets_are_not_told_while_tickets_are_disabled(): void
    {
        config(['settings.tickets_disabled' => true]);
        $user = User::factory()->create();
        Ticket::factory()->create(['client_id' => $user->client()->id, 'status' => 'replied', 'subject' => 'Mail bounce']);

        $this->workOn($user)->get(route('dashboard'))->assertOk()->assertDontSee('Mail bounce')
            ->assertSee(__('dashboard.attention.all_good'));
    }

    public function test_holds_a_removed_card_or_a_removed_gateway_is_not_told(): void
    {
        $user = User::factory()->create();
        $this->card($user->client(), 'Visa 1111', '2026-10-31')->delete(); // soft-deleted
        $gone = Gateway::query()->create(['extension' => 'GonePay', 'name' => 'GonePay', 'type' => 'gateway']);
        $this->card($user->client(), 'Visa 2222', '2026-10-31', $gone);
        DB::table('extensions')->where('id', $gone->id)->delete();
        $this->card(User::factory()->create()->client(), 'Visa 3333', '2026-10-31'); // another account's

        $this->workOn($user)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Visa 1111')->assertDontSee('Visa 2222')->assertDontSee('Visa 3333');
    }

    public function test_holds_a_ticket_subject_is_escaped(): void
    {
        $user = User::factory()->create();
        Ticket::factory()->create(['client_id' => $user->client()->id, 'status' => 'replied', 'subject' => '<script>alert(1)</script>']);

        $this->workOn($user)->get(route('dashboard'))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_holds_drafts_and_other_accounts_are_not_in_the_total(): void
    {
        $user = User::factory()->create();
        $this->invoice($user->client(), Invoice::STATUS_DRAFT, ['Draft']);
        $this->invoice(User::factory()->create()->client(), Invoice::STATUS_UNPAID, ['Theirs']);

        $this->workOn($user)->get(route('dashboard'))->assertOk()->assertSee(__('dashboard.attention.all_good'));
    }

    // ==========================================================================================  the invoice PDF

    /**
     * FINDING: an invoice number with «/» (a format such as INV/{YEAR}/{NUMBER}; Invoice::numberIsKey expects them and
     * falls back to the id in the URL) makes the download a 500: Symfony's Content-Disposition refuses «/» in a filename.
     */
    public function test_the_pdf_of_an_invoice_numbered_with_a_slash_downloads(): void
    {
        $user = User::factory()->create();
        $invoice = $this->invoice($user->client(), Invoice::STATUS_PAID);
        DB::table('invoices')->where('id', $invoice->id)->update(['number' => 'INV/2026/15']);

        $response = $this->workOn($user)->get(route('invoices.pdf', $invoice->fresh()));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->streamedContent());
    }

    public function test_holds_the_pdf_by_number_and_never_another_accounts_by_its_number(): void
    {
        $user = User::factory()->create();
        $mine = $this->invoice($user->client(), Invoice::STATUS_PAID);
        DB::table('invoices')->where('id', $mine->id)->update(['number' => '2026-15']);
        $theirs = $this->invoice(User::factory()->create()->client(), Invoice::STATUS_PAID);
        DB::table('invoices')->where('id', $theirs->id)->update(['number' => '2026-16']);

        $this->workOn($user)->get('/invoices/2026-15/pdf')->assertOk();
        $this->workOn($user)->get('/invoices/2026-16/pdf')->assertNotFound();
        $this->workOn($user)->get('/invoices/' . $theirs->id . '/pdf')->assertNotFound();
        $this->workOn($user)->get('/invoices/' . $mine->id . 'abc/pdf')->assertNotFound();
    }

    public function test_holds_the_pdf_needs_the_invoices_grant_a_live_grant_and_a_login(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $invoice = $this->invoice($client, Invoice::STATUS_UNPAID);

        $this->get(route('invoices.pdf', $invoice))->assertRedirect(route('login'));

        $noInvoices = $this->member($client, ['tickets', 'domains']);
        $this->workOn($noInvoices, $client)->get(route('invoices.pdf', $invoice))->assertNotFound();

        $revoked = $this->member($client, ['invoices']);
        ClientUser::query()->where('user_id', $revoked->id)->update(['revoked_at' => now()]);
        $this->workOn($revoked, $client)->get(route('invoices.pdf', $invoice))->assertNotFound();
    }

    public function test_holds_staff_logged_in_only_to_the_admin_do_not_get_the_portal_pdf(): void
    {
        $owner = User::factory()->create();
        $invoice = $this->invoice($owner->client(), Invoice::STATUS_PAID);
        $staff = Staff::factory()->withPermissions(['admin.invoices.viewAny'])->create();

        $this->actingAs($staff, 'staff')->get(route('invoices.pdf', $invoice))->assertRedirect(route('login'));
    }

    // ==========================================================================================  invoices list

    public function test_holds_drafts_and_other_accounts_are_neither_listed_nor_counted(): void
    {
        $user = User::factory()->create();
        $this->invoice($user->client(), Invoice::STATUS_UNPAID, ['Mine unpaid']);
        $this->invoice($user->client(), Invoice::STATUS_DRAFT, ['Mine draft']);
        $this->invoice(User::factory()->create()->client(), Invoice::STATUS_UNPAID, ['Theirs unpaid']);
        $this->workOn($user);
        session([CurrentClient::SESSION_KEY => $user->client()->id]);

        $component = Livewire::actingAs($user)->test(InvoicesIndex::class)->assertSee('Mine unpaid')
            ->assertDontSee('Mine draft')->assertDontSee('Theirs unpaid');
        $counts = $component->viewData('counts');
        $this->assertSame(1, $counts['']);
        $this->assertSame(1, $counts['unpaid']);
    }

    public function test_holds_the_mass_pay_checkbox_needs_two_issued_unpaid_and_only_on_unpaid_rows(): void
    {
        config(['settings.mass_pay_enabled' => true]);
        $user = User::factory()->create();
        $a = $this->invoice($user->client(), Invoice::STATUS_UNPAID);
        $this->invoice($user->client(), Invoice::STATUS_DRAFT);
        $paid = $this->invoice($user->client(), Invoice::STATUS_PAID);
        session([CurrentClient::SESSION_KEY => $user->client()->id]);

        Livewire::actingAs($user)->test(InvoicesIndex::class)->assertViewHas('massPay', false)
            ->assertDontSeeHtml('wire:model.live="selected"');
        $this->assertNotNull($a->id + $paid->id);
    }

    /** A crafted list filter (?status=draft) never lists drafts. */
    public function test_holds_a_crafted_status_never_lists_drafts(): void
    {
        $user = User::factory()->create();
        $this->invoice($user->client(), Invoice::STATUS_DRAFT, ['Mine draft']);
        session([CurrentClient::SESSION_KEY => $user->client()->id]);

        Livewire::actingAs($user)->withQueryParams(['status' => 'draft'])->test(InvoicesIndex::class)
            ->assertSet('status', '')->assertDontSee('Mine draft');
        Livewire::actingAs($user)->test(InvoicesIndex::class)->call('filter', 'draft')
            ->assertSet('status', '')->assertDontSee('Mine draft');
    }

    /**
     * FINDING (low): `status` is a public, unlocked property; a crafted Livewire update setting it to anything outside
     * FILTERS ('draft', 'x') is «Undefined array key» in render(), a 500. Drafts are not listed either way ($issued).
     */
    public function test_a_crafted_status_property_update_is_not_a_500(): void
    {
        $user = User::factory()->create();
        $this->invoice($user->client(), Invoice::STATUS_DRAFT, ['Mine draft']);
        session([CurrentClient::SESSION_KEY => $user->client()->id]);

        Livewire::actingAs($user)->test(InvoicesIndex::class)->set('status', 'draft')->assertOk()->assertDontSee('Mine draft');
    }

    // ==========================================================================================  remembered filters

    /**
     * FINDING (low, defensive): recallFilter casts whatever the session holds with (string); an array there (another
     * writer of the key, an older shape) is «Array to string conversion», a 500 on the list. An unknown string is
     * already dropped by mount(); a non-string should be too.
     */
    public function test_a_non_string_remembered_filter_is_dropped_not_a_500(): void
    {
        $user = User::factory()->create();
        session([CurrentClient::SESSION_KEY => $user->client()->id, 'portal.filters.invoices' => ['paid']]);

        Livewire::actingAs($user)->test(InvoicesIndex::class)->assertSet('status', '');
    }

    public function test_holds_an_unknown_remembered_filter_shows_them_all(): void
    {
        $user = User::factory()->create();
        $this->invoice($user->client(), Invoice::STATUS_PAID, ['Paid thing']);
        session([CurrentClient::SESSION_KEY => $user->client()->id, 'portal.filters.invoices' => 'draft', 'portal.filters.domains' => 'bogus']);

        Livewire::actingAs($user)->test(InvoicesIndex::class)->assertSet('status', '')->assertSee('Paid thing');
        Livewire::actingAs($user)->test(DomainsIndex::class)->assertSet('status', '');
    }

    /** An explicit «?status=» (All) in the link wins over the remembered filter. */
    public function test_holds_an_empty_status_in_the_link_wins(): void
    {
        $user = User::factory()->create();
        $this->invoice($user->client(), Invoice::STATUS_UNPAID, ['Unpaid thing']);
        session([CurrentClient::SESSION_KEY => $user->client()->id, 'portal.filters.invoices' => 'paid']);

        Livewire::actingAs($user)->withQueryParams(['status' => ''])->test(InvoicesIndex::class)->assertSet('status', '')->assertSee('Unpaid thing');
    }

    // ==========================================================================================  auto renew from the row

    public function test_holds_staff_in_login_as_client_without_admin_domains_update_may_not(): void
    {
        $owner = User::factory()->create();
        $domain = $this->domain($owner->client(), 'mine.gr');
        $this->impersonate($owner, ['admin.domains.viewAny']);

        $component = app(DomainsIndex::class);
        try {
            $component->toggleAutoRenew($domain->id);
            $this->fail('turned without admin.domains.update');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertTrue($domain->fresh()->auto_renew);
        $this->assertSame([], $component->render()->getData()['toggleable']);
    }

    public function test_holds_staff_in_login_as_client_with_admin_domains_update_may(): void
    {
        $owner = User::factory()->create();
        $domain = $this->domain($owner->client(), 'mine.gr');
        $this->impersonate($owner, ['admin.domains.update']);

        $component = app(DomainsIndex::class);
        $component->toggleAutoRenew($domain->id);
        $this->assertFalse($domain->fresh()->auto_renew);
    }

    public function test_holds_each_terminal_status_is_refused(): void
    {
        $user = User::factory()->create();
        session([CurrentClient::SESSION_KEY => $user->client()->id]);
        foreach (DomainStatus::cases() as $i => $status) {
            if (!$status->isTerminal()) {
                continue;
            }
            $domain = $this->domain($user->client(), "t{$i}.gr", status: $status);
            Livewire::actingAs($user)->test(DomainsIndex::class)->call('toggleAutoRenew', $domain->id)->assertNotFound();
            $this->assertTrue($domain->fresh()->auto_renew, $status->value);
        }
    }

    public function test_holds_a_member_with_managedomains_but_a_revoked_grant_may_not(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $domain = $this->domain($client, 'mine.gr');
        $member = $this->member($client, ['domains', 'managedomains']);
        session([CurrentClient::SESSION_KEY => $client->id]);
        $component = Livewire::actingAs($member)->test(DomainsIndex::class)->assertSeeHtml('role="switch"');

        ClientUser::query()->where('user_id', $member->id)->update(['revoked_at' => now()]);
        $component->call('toggleAutoRenew', $domain->id)->assertStatus(404);
        $this->assertTrue($domain->fresh()->auto_renew);
    }

    public function test_holds_a_crafted_id_is_refused(): void
    {
        $user = User::factory()->create();
        $domain = $this->domain($user->client(), 'mine.gr');
        session([CurrentClient::SESSION_KEY => $user->client()->id]);

        Livewire::actingAs($user)->test(DomainsIndex::class)->call('toggleAutoRenew', 0)->assertNotFound();
        Livewire::actingAs($user)->test(DomainsIndex::class)->call('toggleAutoRenew', -1)->assertNotFound();
        try {
            Livewire::actingAs($user)->test(DomainsIndex::class)->call('toggleAutoRenew', ['id' => $domain->id]);
        } catch (\TypeError) {
            // a tampered request: a 500, nothing changed
        }
        $this->assertTrue($domain->fresh()->auto_renew);
    }

    /** D-14: auto renew off from the row is «do not renew», as on its page: an invoice already sent stays payable. */
    public function test_holds_turning_off_with_an_open_renewal_invoice_leaves_it_payable_as_on_the_page(): void
    {
        $user = User::factory()->create();
        $domain = $this->domain($user->client(), 'mine.gr');
        $renewal = $this->invoice($user->client(), Invoice::STATUS_UNPAID, ['mine.gr · renewal']);
        session([CurrentClient::SESSION_KEY => $user->client()->id]);

        Livewire::actingAs($user)->test(DomainsIndex::class)->call('toggleAutoRenew', $domain->id);
        $this->assertFalse($domain->fresh()->auto_renew);
        $this->assertSame(Invoice::STATUS_UNPAID, $renewal->fresh()->status);
    }
}
