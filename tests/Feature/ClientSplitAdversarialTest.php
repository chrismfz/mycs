<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Livewire\Client\PaymentMethods;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Livewire\Tickets\Create as TicketCreate;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Adversarial review of PR 2a (clients split from users, docs/accounts.md). `test_bug_*` fail on the branch as it
 * is (a real issue); `test_holds_*` pin a guarantee that was attacked and held.
 */
class ClientSplitAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private function grant(Client $client, User $user): ClientUser
    {
        return ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $user->id, 'granted_at' => now()]);
    }

    private function agreementOf(Client $client, string $reference): BillingAgreement
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);

        return BillingAgreement::create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'PayPal ' . $reference,
            'external_reference' => $reference, 'type' => 'paypal']);
    }

    private function recurringServiceOf(Client $client, string $status = Service::STATUS_SUSPENDED): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addDays(5)]);
    }

    /** The user works on $client in the client area (as 2b's account picker would switch to it). */
    private function workOn(User $user, Client $client): void
    {
        $this->actingAs($user);
        $this->assertTrue(app(CurrentClient::class)->switchTo($client->id), 'precondition: the user sees that account');
        $this->assertSame($client->id, CurrentClient::id());
    }

    // ---------------------------------------------------------------------------------------------------------------
    // F1: a payment method of one account is attached to (and charged for) another account's service/invoice.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * U owns A and was given access to B (a WHMCS sub-user of B). Working on B, U opens A's service (policy: U owns A)
     * and saves B's card as the service's payment method: CronJob::billRenewal then charges B's card for A's renewals,
     * and ExtensionHelper::charge never checks that the agreement is the invoice's account's. Money moves from the
     * account that trusted U to U's own account.
     */
    public function test_holds_an_account_is_not_set_to_another_accounts_payment_method(): void
    {
        $owner = User::factory()->create();
        $b = $owner->client();
        $u = User::factory()->create();
        $a = $u->client();
        $this->grant($b, $u);
        $cardOfB = $this->agreementOf($b, 'VAULT-B');
        $serviceOfA = $this->recurringServiceOf($a);

        $this->workOn($u, $a);
        $this->assertTrue(Gate::forUser($u)->allows('update', $serviceOfA), 'precondition: U manages A\'s service');

        // The method is the account's since decision 6 (PR C2): chosen on «Payment methods», looked up in the current one.
        try {
            $this->livewireAs($u)->test(PaymentMethods::class)->call('makeDefault', $cardOfB->ulid);
        } catch (\Throwable) {
            // refusing by an exception is fine too: what matters is the account row
        }
        try {
            $a->fresh()->update(['default_billing_agreement_id' => $cardOfB->id]);
        } catch (\Throwable) {
        }

        $this->assertNotSame($cardOfB->id, (int) $a->fresh()->default_billing_agreement_id,
            'account A\'s renewals must never be charged to account B\'s saved payment method');
    }

    /** The same through the invoice page: «set as default» puts B's card on A's recurring services before paying. */
    public function test_bug_paying_an_invoice_with_another_accounts_card_sets_it_on_its_services(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $b = $owner->client();
        $u = User::factory()->create();
        $a = $u->client();
        $this->grant($b, $u);
        $cardOfB = $this->agreementOf($b, 'VAULT-B2');
        $serviceOfA = $this->recurringServiceOf($a, Service::STATUS_ACTIVE);
        $invoiceOfA = Invoice::factory()->create(['client_id' => $a->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoiceOfA->items()->create(['description' => 'Renewal', 'quantity' => 1, 'price' => 10,
            'reference_type' => Service::class, 'reference_id' => $serviceOfA->id]);

        $this->workOn($u, $b);

        try {
            $this->livewireAs($u)->test(InvoiceShow::class, ['invoice' => $invoiceOfA->fresh()])
                ->set('selectedMethod', $cardOfB->ulid)
                ->set('setAsDefault', true)
                ->call('processPayment');
        } catch (\Throwable) {
        }

        $this->assertNotSame($cardOfB->id, (int) $a->fresh()->default_billing_agreement_id,
            'an invoice of account A is paid, and its renewals charged, only with account A\'s payment methods');
    }

    /** Without any grant on the other account its card is out of reach (the lookup is by the current account). */
    public function test_holds_a_foreign_card_is_not_found_from_payment_methods(): void
    {
        $stranger = User::factory()->create()->client();
        $cardOfStranger = $this->agreementOf($stranger, 'VAULT-X');
        $u = User::factory()->create();
        $this->actingAs($u);

        try {
            $this->livewireAs($u)->test(PaymentMethods::class)->call('makeDefault', $cardOfStranger->ulid);
        } catch (\Throwable) {
        }

        $this->assertNull($u->client()->fresh()->default_billing_agreement_id);
    }

    public function test_holds_a_foreign_payment_method_cannot_be_removed(): void
    {
        $stranger = User::factory()->create()->client();
        $cardOfStranger = $this->agreementOf($stranger, 'VAULT-Y');
        $u = User::factory()->create();
        $this->actingAs($u);

        $this->livewireAs($u)->test(PaymentMethods::class)->call('removePaymentMethod', $cardOfStranger->ulid);

        $this->assertNotNull(BillingAgreement::query()->find($cardOfStranger->id));
    }

    // ---------------------------------------------------------------------------------------------------------------
    // F2: deleting a login that is not an owner wipes what it wrote in the account's tickets.
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * A login with a (non-owner) grant may be deleted (UserObserver only stops owners), but ticket_messages.user_id
     * still cascades on users: its replies vanish from the ACCOUNT's tickets, which outlive it (the staff split made
     * staff replies restrict for this reason).
     */
    public function test_bug_deleting_a_non_owner_login_deletes_its_replies_in_the_accounts_tickets(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $member = User::factory()->withoutClient()->create();
        $this->grant($client, $member);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'status' => 'open']);
        $reply = TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => $member->id, 'message' => 'Please renew the domain']);

        try {
            $member->delete();
        } catch (\Throwable) {
            // refusing the delete is a valid fix too
        }

        $this->assertNotNull(TicketMessage::query()->find($reply->id), 'the account\'s ticket history must survive the login');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // F3: a revoked owner grant still counts as the owner (mail, name, «Login as client»).
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Client::ownerGrant ignores revoked_at, unlike every other grant check: an owner whose grant was revoked keeps
     * receiving the account's invoices (PDF attached) and is who «Login as client» opens.
     */
    public function test_bug_a_revoked_owner_still_receives_the_accounts_mail(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
        $owner = User::factory()->create(['email' => 'former-owner@example.test']);
        $client = $owner->client();
        $client->ownerGrant->forceFill(['revoked_at' => now()])->save();
        $this->assertFalse(Gate::forUser($owner)->allows('view', Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID])),
            'precondition: the revoked owner no longer sees the account');
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);

        NotificationHelper::invoiceCreatedNotification($client->fresh(), $invoice->fresh());

        // The account's mail (the login's own, e.g. its welcome mail, is not the account's).
        $this->assertFalse(EmailLog::query()->where('to', 'former-owner@example.test')->where('client_id', $client->id)->exists(),
            'a login whose grant was revoked must not get the account\'s invoices');
        $this->assertNull($client->fresh()->owner());
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Guarantees that held.
    // ---------------------------------------------------------------------------------------------------------------

    /** A revoked grant kept in the session (client_id) is not taken: the user falls back to their own account. */
    public function test_holds_a_revoked_grant_in_the_session_is_not_the_current_account(): void
    {
        $owner = User::factory()->create();
        $shared = $owner->client();
        $sharedInvoice = Invoice::factory()->create(['client_id' => $shared->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $u = User::factory()->create();
        $grant = $this->grant($shared, $u);
        $grant->forceFill(['revoked_at' => now()])->save();

        $this->actingAs($u)->withSession($this->loginUser($u, [CurrentClient::SESSION_KEY => $shared->id]))
            ->get(route('invoices'))->assertOk()->assertDontSee('/invoices/' . $sharedInvoice->id, false);
        $this->assertSame($u->client()->id, CurrentClient::id());
        $this->assertSame($u->client()->id, Session::get(CurrentClient::SESSION_KEY));
        $this->actingAs($u)->get(route('invoices.show', $sharedInvoice))->assertNotFound();
    }

    /** A session id that is a USER id (the old key) of another customer is not taken as an account id. */
    public function test_holds_a_user_id_in_the_session_is_not_an_account(): void
    {
        $victim = User::factory()->create();
        // An account whose id equals the attacker's own user id, owned by the victim (migrated data: ids coincide).
        $u = User::factory()->create();
        $victimsSecond = Client::openFor($victim, $u->id);

        $this->actingAs($u)->withSession($this->loginUser($u, [CurrentClient::SESSION_KEY => $u->id]))->get(route('dashboard'))->assertOk();

        $this->assertNotSame($victimsSecond->id, CurrentClient::id());
        $this->assertSame($u->client()->id, CurrentClient::id());
    }

    /** «Login as client»: the account is the customer's own, never one a session value points at. */
    public function test_holds_impersonation_ignores_the_sessions_account(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create()->client();
        $staff = Staff::factory()->withPermissions(['admin.users.impersonate'])->create();

        $this->actingAs($staff)->withSession(['impersonating' => $customer->id, CurrentClient::SESSION_KEY => $other->id])
            ->get(route('dashboard'))->assertOk();

        $this->assertSame($customer->client()->id, CurrentClient::id());
    }

    public function test_holds_a_ticket_cannot_name_another_accounts_service(): void
    {
        $support = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $foreignService = $this->recurringServiceOf(User::factory()->create()->client());
        $u = User::factory()->create();
        $this->actingAs($u);

        $this->livewireAs($u)->test(TicketCreate::class)
            ->set('department', $support->id)->set('subject', 'x')->set('message', 'y')->set('priority', 'low')
            ->set('service', $foreignService->id)
            ->call('create')
            ->assertHasErrors('service');
        $this->assertSame(0, Ticket::query()->count());
    }

    /** Paying another account's invoice (a granted one) spends THAT account's credit, never the current one's. */
    public function test_holds_credit_pays_from_the_invoices_account(): void
    {
        config(['settings.credits_enabled' => true]);
        $owner = User::factory()->create();
        $b = $owner->client();
        $u = User::factory()->create();
        $a = $u->client();
        $this->grant($b, $u);
        (new CreditLedger)->add($a->id, 'USD', 5000, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'A');
        $invoiceOfB = Invoice::factory()->create(['client_id' => $b->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoiceOfB->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $this->actingAs($u);

        $this->livewireAs($u)->test(InvoiceShow::class, ['invoice' => $invoiceOfB->fresh()])
            ->set('selectedMethod', 'credit')->call('processPayment');

        $this->assertSame(5000, (new CreditLedger)->balanceCents($a->id, 'USD'), 'A\'s credit is untouched');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoiceOfB->fresh()->status);
    }

    /** The journal's key refuses deleting an account with money events even past the model's guard. */
    public function test_holds_the_database_refuses_deleting_an_account_with_money_events(): void
    {
        $client = User::factory()->create()->client();
        (new CreditLedger)->add($client->id, 'USD', 100, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'x');

        $this->expectException(QueryException::class);
        DB::table('clients')->where('id', $client->id)->delete();
    }

    /** The owner of an account is not deleted, even when its grant was revoked (the account would have no owner row). */
    public function test_holds_an_owner_with_a_revoked_grant_is_still_not_deleted(): void
    {
        $owner = User::factory()->create();
        $owner->client()->ownerGrant->forceFill(['revoked_at' => now()])->save();

        try {
            $owner->delete();
        } catch (\Throwable) {
        }

        $this->assertNotNull(User::query()->find($owner->id));
    }
}
