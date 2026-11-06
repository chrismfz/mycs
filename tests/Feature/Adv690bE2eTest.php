<?php

namespace Tests\Feature;

use App\Livewire\Client\Account;
use App\Livewire\Client\Contacts;
use App\Livewire\Client\Security;
use App\Livewire\Services\Cancel;
use App\Livewire\Tickets\Show as TicketShow;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\License;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Licensing\Licenses;
use App\Services\Licensing\MycsLicenseRepository;
use App\Services\Ops\RollbackReport;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Second adversarial review of #690 (mycs:rollback-report), end to end: a weekend after the cut-over through the real
 * code paths (client area, staff services, the cron), then the report. Each test is something staff would have to
 * re-enter in WHMCS that the report misses or misdescribes.
 */
class Adv690bE2eTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $cutover;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->cutover = Carbon::parse('2026-11-14 22:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{key: string, title: string, todo: string, columns: list<string>, rows: list<list<string>>}> */
    private function sections(): array
    {
        return collect((new RollbackReport($this->cutover))->sections())->keyBy('key')->all();
    }

    private function everything(): string
    {
        return json_encode(array_map(fn ($s) => $s['rows'], $this->sections()), JSON_UNESCAPED_UNICODE);
    }

    /** As the importer leaves it (audit.console off, WHMCS's ids and dates), then the MyCS opens (the web is audited). */
    private function imported(): array
    {
        Carbon::setTestNow('2026-11-14 21:00');
        Config::set('audit.console', false);
        $user = User::factory()->create(['created_at' => '2020-01-01', 'email' => 'old@example.test', 'password' => Hash::make('Right-Pass-1')]);
        $client = $user->client();
        $client->forceFill(['created_at' => '2020-01-01'])->save();
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-12-20', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1, 'created_at' => '2021-01-01']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'created_at' => '2026-11-01', 'due_at' => '2026-11-20']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => 10, 'quantity' => 1, 'description' => 'Hosting', 'created_at' => '2026-11-01']);
        $domain = Domain::factory()->create(['client_id' => $client->id, 'sld' => 'example', 'fqdn' => 'example.test', 'expires_at' => '2027-06-01', 'created_at' => '2019-01-01']);

        // Monday after the cut-over weekend: the web is audited (in the tests the console stands for it).
        Carbon::setTestNow('2026-11-16 10:00');
        Config::set('audit.console', true);

        return [$user, $client, $service, $invoice, $domain];
    }

    /** The customer signed in to the client area, as Adv332ReauthTest does. */
    private function signIn(User $user): void
    {
        $data = $this->loginUser($user, [CurrentClient::SESSION_KEY => $user->client()->id]);
        $this->actingAs($user)->withSession($data);
        request()->attributes->set('user_session', UserSession::query()->where('ulid', $data['user_session'])->sole());
    }

    /**
     * ADV-690b-E-1: a bank transfer that arrived on Friday (before the cut-over), recorded by staff on Monday with the day
     * it arrived (Add payment / Mark paid «paid_at»): the transaction's created_at is Friday, so payments() — which takes
     * the MyCS's rows (created audit) and then filters them by created_at ≥ since — drops it. Paid in part, the invoice
     * stays unpaid: nothing at all in the report, and WHMCS dunned and suspends a customer who paid.
     */
    public function test_a_payment_staff_record_with_the_day_the_money_arrived_is_listed(): void
    {
        [, , , $invoice] = $this->imported();

        (new RecordPaymentService)->recordByStaff($invoice, null, 4.00, 'BANK-FRI-1', paidAt: Carbon::parse('2026-11-13 12:00'));
        $this->assertSame(1, $invoice->transactions()->where('transaction_id', 'BANK-FRI-1')->count());

        $payments = $this->sections()['payments']['rows'];
        $this->assertContains('BANK-FRI-1', array_column($payments, 9), 'recorded in the MyCS on Monday: AddInvoicePayment in WHMCS');
    }

    /**
     * ADV-690b-E-2: the customer asks to cancel a WHMCS service «at the end of the billing period» from the client area.
     * With no open invoice, only a service_cancellations row is written: nothing in the report. Back in WHMCS, its cron
     * bills the next period and never ends the service the customer cancelled (AddCancelRequest).
     */
    public function test_a_cancellation_request_from_the_client_area_is_listed(): void
    {
        [$user, , $service, $invoice] = $this->imported();
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]); // nothing open on it: only the request is written
        Queue::fake();
        $this->signIn($user);

        Livewire::test(Cancel::class, ['service' => $service])->set('type', 'end_of_period')->set('reason', 'Moving to another host')->call('cancelService');
        $this->assertSame(1, $service->cancellation()->count());

        $this->assertStringContainsString('Moving to another host', $this->everything(), 'AddCancelRequest in WHMCS, else it renews the service');
    }

    /**
     * ADV-690b-E-3: the customer changes their address, phone and ΑΦΜ in the client area. That is the primary contact,
     * which the importer made from tblclients with an id of the MyCS's own: the report lists it under «Contacts changed»
     * by the contact's id, with «UpdateContact». In WHMCS these are the client's own fields (UpdateClient on client
     * #id): staff look for a WHMCS contact that does not exist, and the client's billing details stay old.
     */
    public function test_a_profile_change_in_the_client_area_points_at_the_whmcs_client(): void
    {
        [$user, $client] = $this->imported();
        $this->signIn($user);
        $contact = $client->primaryContact;
        $this->assertNotNull($contact);
        $this->assertNotSame((int) $client->id, (int) $contact->id, 'the primary contact has an id of its own');

        Livewire::test(Account::class)->set('contact.address1', 'Odos Neas 12')->set('contact.phone', '+30.2100000000')->set('contact.country', 'GR')->call('submit');
        $this->assertSame('Odos Neas 12', $contact->fresh()->address1);

        $sections = $this->sections();
        $rows = [...$sections['clients_changed']['rows'], ...$sections['contacts_changed']['rows']];
        $row = collect($rows)->first(fn ($r) => str_contains(implode(' ', $r), 'Odos Neas 12'));
        $this->assertNotNull($row, 'the change is listed');
        $this->assertContains((string) $client->id, $row, 'by the WHMCS client it belongs to (UpdateClient), not by a contact id WHMCS never had');
    }

    /** ADV-690b-E-4: a contact the customer adds in the client area (AddContact in WHMCS): no part lists new contacts. */
    public function test_a_contact_added_in_the_client_area_is_listed(): void
    {
        [$user] = $this->imported();
        $this->signIn($user);

        Livewire::test(Contacts::class)->call('create')
            ->set('contact.first_name', 'Billing')->set('contact.last_name', 'Person')->set('contact.email', 'billing@example.test')->set('contact.country', 'GR')
            ->call('save')->assertHasNoErrors();

        $this->assertStringContainsString('billing@example.test', $this->everything(), 'AddContact in WHMCS');
    }

    /**
     * ADV-690b-E-5: the customer changes the login's email (and, separately, the password) in the client area. User
     * changes are in the audit, but the report has no part for them (only «New logins»): back in WHMCS the customer
     * signs in with an address they no longer use, and staff do not know whom to tell to reset the password (runbook §3:
     * «αλλαγμένοι κωδικοί … reset τον κωδικό του»).
     */
    public function test_a_login_email_and_password_change_are_listed(): void
    {
        [$user] = $this->imported();
        $this->signIn($user);

        Livewire::test(Account::class)->set('contact.country', 'GR')->set('email', 'new@example.test')->set('email_password', 'Right-Pass-1')->call('submit');
        $this->assertSame('new@example.test', $user->fresh()->email);
        Livewire::test(Security::class)->set('current_password', 'Right-Pass-1')->set('password', 'Other-Pass-9x')->set('password_confirmation', 'Other-Pass-9x')->call('changePassword');
        $this->assertTrue(Hash::check('Other-Pass-9x', $user->fresh()->password));

        $all = $this->everything();
        $this->assertStringContainsString('new@example.test', $all, 'the login\'s new email: UpdateUser in WHMCS');
        $this->assertStringContainsString('password', $all, 'whose password changed: they reset it in WHMCS');
    }

    /**
     * ADV-690b-E-6: a WHMCS ticket the customer (or staff) closed in the MyCS. Ticket changes are audited but no part
     * lists them: in WHMCS it stays open (or answered) in the queue, and its auto-close / reminders run on it.
     */
    public function test_a_whmcs_ticket_closed_in_the_mycs_is_listed(): void
    {
        [$user, $client] = $this->imported();
        Config::set('audit.console', false);
        $department = TicketDepartment::query()->create(['name' => 'Support', 'email' => 'support@example.test']);
        $ticket = Ticket::query()->create(['client_id' => $client->id, 'user_id' => $user->id, 'department_id' => $department->id, 'subject' => 'Old WHMCS ticket',
            'status' => 'open', 'priority' => 'medium', 'whmcs_id' => 555]);
        $ticket->forceFill(['created_at' => '2026-11-10 09:00'])->save();
        Config::set('audit.console', true);
        $this->signIn($user);

        Livewire::test(TicketShow::class, ['ticket' => $ticket])->call('closeTicket');
        $this->assertSame('closed', $ticket->fresh()->status);

        $this->assertStringContainsString('closed', $this->everything(), 'UpdateTicket status Closed in WHMCS');
    }

    /**
     * ADV-690b-E-7: a nixpal license (zlic) reissued in the MyCS (the customer's «Reissue» or staff's), then bound by its
     * next check to the new server (MycsLicenseRepository::bind, a query: no audit). License has no part in the report:
     * back on WHMCS's licensing addon the key is still Active on the old IP, and the customer's agent on the new server
     * fails its check (zlic «Invalid»). The new binding never appears anywhere.
     */
    public function test_a_license_reissued_and_bound_again_in_the_mycs_is_listed(): void
    {
        [, , $service] = $this->imported();
        Config::set('audit.console', false);
        $license = License::query()->create(['service_id' => $service->id, 'license_key' => 'ZCAgent-0123456789abcdef', 'status' => License::STATUS_ACTIVE,
            'valid_ip' => '192.0.2.1', 'valid_domain' => 'old.example.test', 'reissues' => 0, 'whmcs_id' => 77]);
        $license->forceFill(['created_at' => '2025-01-01'])->saveQuietly(); // as imported: dated before the cut-over
        Config::set('audit.console', true);

        Licenses::reissue($license, '198.51.100.9', byStaff: true);
        $this->assertSame(License::STATUS_REISSUED, $license->fresh()->status);

        $this->assertStringContainsString('ZCAgent-0123456789abcdef', $this->everything(), 'the license reissued here: Reissue it in WHMCS too, or its check fails on the new server');
    }

    /**
     * ADV-690b-E-8: the customer pays the WHMCS renewal invoice of a domain in the MyCS, and the MyCS renews it at the
     * registrar. The report says «AddInvoicePayment on the invoice» — in WHMCS that payment runs its own automation
     * («Auto Renew on Payment» for a domain line, auto-setup / unsuspend for a product line): the domain is renewed at the
     * registrar a second time (a year paid twice), a new order is provisioned twice. Neither the payments part nor the
     * registrar part says to record such a payment without it (AddTransaction / the line already renewed).
     */
    public function test_carrying_back_a_payment_does_not_renew_again_at_the_registrar(): void
    {
        [, $client, , , $domain] = $this->imported();
        Config::set('audit.console', false);
        $renewal = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'created_at' => '2026-11-10', 'due_at' => '2026-11-25']);
        InvoiceItem::factory()->create(['invoice_id' => $renewal->id, 'price' => 12, 'quantity' => 1, 'description' => 'Domain renewal - example.test - 1 year',
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'created_at' => '2026-11-10']);
        Config::set('audit.console', true);

        (new RecordPaymentService)->handle($renewal, null, 12.00, transactionId: 'pi_weekend');
        // What DomainRenewalService writes once the registrar renewed it for that invoice.
        DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_OK, 'invoice_id' => $renewal->id]);

        $sections = $this->sections();
        $this->assertContains('pi_weekend', array_column($sections['payments']['rows'], 9));
        $this->assertMatchesRegularExpression('/registrar|renew|automation|auto.?setup/i', $sections['payments']['todo'],
            'a payment on an invoice whose domain the MyCS already renewed must be recorded in WHMCS without renewing it there again');
    }
}
