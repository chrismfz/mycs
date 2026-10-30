<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Console\Commands\ImportFromWhmcs;
use App\Events\Invoice\Finalized as InvoiceFinalized;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Listeners\SendMailListener;
use App\Livewire\Client\Contacts;
use App\Models\Client;
use App\Models\Contact;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketCc;
use App\Models\TicketDepartment;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Mail\MailAudit;
use App\Services\Order\AcceptOrder;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Console\OutputStyle;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

/**
 * Adversarial review of #545 (the primary contact's email preferences gate the owner's mails). test_bug_* fail on
 * the PR head (cb809bc6); test_holds_* are guarantees attacked that held.
 *
 * The spec (owner 2026-10-30): only the owner is gated, by the primary contact's choices; suspended, terminated,
 * overdue and expired always go, as do login and guest ticket mails; anything staff send by hand always goes; a mass
 * mail is gated by its target's kind; only the mail is skipped, never the in-app notification; null shows ticked.
 */
class EmailPreferencesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        config(['settings.mail_disable' => false]);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        (new EmailTemplateSeeder)->run();
        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->client = $this->owner->client();
        $this->clean();
    }

    private function clean(): void
    {
        EmailLog::query()->delete();
        Notification::query()->delete();
        Mail::fake();
    }

    /** @param  array<int, string>|null  $kinds */
    private function takes(?array $kinds): void
    {
        $this->client->primaryContact->forceFill(['email_preferences' => $kinds])->save();
        $this->client = $this->client->fresh();
    }

    /** @return list<string> */
    private function mailed(): array
    {
        return EmailLog::query()->where('to', 'owner@example.test')->orderBy('id')->pluck('template_key')->all();
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->admin()->create();
    }

    private function service(string $status = Service::STATUS_ACTIVE): Service
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->client->id, 'product_id' => $product->product->id, 'plan_id' => $product->plan->id,
            'status' => $status, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
        $this->clean();

        return $service;
    }

    // ---- ADV-545-1..4: staff's «Send email» ticks that are gated as if automatic -------------------------------------

    /**
     * ADV-545-1. Staff «Unsuspend server» with «Send Notification» ticked (EditService::changeStatus): staff send the
     * «unsuspended» mail by hand, yet it is gated as product (serverUnsuspendedNotification has no $byStaff). Nothing
     * tells staff it was not sent.
     */
    public function test_bug_staff_unsuspend_with_send_notification_is_gated(): void
    {
        $service = $this->service();
        $staff = $this->staff();
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', ['action' => 'suspend', 'sendNotification' => true]);
        $this->assertSame(Service::STATUS_SUSPENDED, $service->fresh()->status, 'precondition: suspended');
        $this->assertSame(['server_suspended'], $this->mailed(), 'precondition: told (suspended always goes)');

        $this->takes(['invoice']);
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction('changeStatus', ['action' => 'unsuspend', 'sendNotification' => true]);

        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'precondition: unsuspended');
        $this->assertContains('server_unsuspended', $this->mailed(), 'staff ticked «Send Notification»: sent by hand, it must go');
    }

    /**
     * ADV-545-2. A refund with WHMCS's «Send Email» toggle on (TransactionsRelationManager → ExtensionHelper::refund*
     * $notify): staff ask for the mail by hand, but invoiceRefundedNotification is gated as invoice.
     */
    public function test_bug_staff_refund_with_send_email_is_gated(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20]);
        (new RecordPaymentService)->handle($invoice->fresh(), null, (float) $invoice->fresh()->total, transactionId: 'bank-1');
        $this->takes(['general']);
        $this->clean();

        ExtensionHelper::refundLocally($invoice->fresh()->transactions()->sole(), 5.00, notify: true);

        $this->assertSame(['invoice_refunded'], $this->mailed(), 'staff left «Send Email» on for the refund: sent by hand, it must go');
    }

    /**
     * ADV-545-3. Staff «Accept Order» with «Send welcome email» ticked (EditOrder → AcceptOrder): the same welcome mail as
     * the service's «Create» with «Send notification» (spec), but sent ungated only from EditService; here it is gated.
     */
    public function test_bug_staff_accept_order_with_send_welcome_email_is_gated(): void
    {
        $product = $this->createProduct();
        $product->product->update(['autosetup' => Product::AUTOSETUP_NEVER, 'server_id' => null]);
        $fresh = $product->product->fresh();
        $order = (new PlaceOrderService)->place($this->client, 'USD', [new OrderLine($fresh, $fresh->plans()->first(), price: '10.00')])->order->fresh();
        $this->takes(['invoice']);
        $this->clean();

        AcceptOrder::handle($order, $this->staff(), runCreate: false, sendEmail: true);

        $this->assertSame(Service::STATUS_ACTIVE, $order->services()->sole()->status, 'precondition: accepted, built by hand');
        $this->assertSame(['new_server_created'], $this->mailed(), 'staff ticked «Send welcome email»: sent by hand, it must go');
    }

    /**
     * ADV-545-4. A new invoice made by staff in the admin with «Send Email» on (CreateInvoice → send_create_email): the
     * mail is gated as invoice, exactly like the cron's. Staff cannot reach a customer who unticked invoices except by
     * a second «Send Email» afterwards.
     */
    public function test_bug_staff_created_invoice_with_send_email_is_gated(): void
    {
        $this->takes(['general']);
        $undo = Repeater::fake();
        $this->livewireAs($this->staff())->test(CreateInvoice::class)->fillForm([
            'client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'send_email' => true,
            'due_at' => now()->addWeek()->toDateString(), 'items' => [['price' => '10.00', 'quantity' => 1, 'description' => 'Hosting']],
        ])->call('create')->assertHasNoFormErrors();
        $undo();
        // The worker: SendMailListener is queued (Queue is faked here).
        Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === SendMailListener::class && $job->data[0] instanceof InvoiceFinalized)
            ->each(fn (CallQueuedListener $job) => app(SendMailListener::class)->handle(...$job->data));

        $this->assertSame(1, Invoice::query()->where('client_id', $this->client->id)->count(), 'precondition: made');
        $this->assertSame(['new_invoice_created'], $this->mailed(), 'staff left «Send Email» on: sent by hand, it must go');
    }

    // ---- ADV-545-5: a staff reply to the customer's own ticket ------------------------------------------------------

    /**
     * ADV-545-5 (question for owner). A staff reply to a ticket the owner opened is a message staff write by hand, but
     * new_ticket_message is gated as support: an owner who unticked «Support» never hears back by mail, while an
     * address staff copied on the same ticket does (ticket_cc_message is ungated).
     */
    public function test_bug_staff_reply_to_the_owners_ticket_is_gated_while_its_cc_gets_it(): void
    {
        $this->takes(['invoice']);
        $department = TicketDepartment::query()->firstOrCreate(['name' => 'Support']);
        $ticket = Ticket::query()->create(['client_id' => $this->client->id, 'subject' => 'Site down', 'status' => Ticket::STATUS_OPEN,
            'priority' => 'medium', 'department_id' => $department->id]);
        TicketCc::query()->create(['ticket_id' => $ticket->id, 'email' => 'colleague@example.test']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'user_id' => $this->owner->id, 'message' => 'Help']);
        $this->clean();

        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'staff_id' => Staff::factory()->admin()->create()->id, 'message' => 'Fixed it.']);

        $this->assertSame(1, EmailLog::query()->where('to', 'colleague@example.test')->count(), 'precondition: the copied address got the reply');
        $this->assertSame(['new_ticket_message'], $this->mailed(), 'the owner who opened the ticket gets no reply by mail');
    }

    // ---- ADV-545-6: «Make primary» silently mutes the owner --------------------------------------------------------

    /**
     * ADV-545-6 (question for owner). Every new other contact starts with nothing ticked (client area «Contacts» and
     * the admin's relation manager: ContactForm default [], EditsContact [] for a new one), which was harmless while
     * other contacts got nothing. Staff «Make primary» on it (to change the account's details) now turns the owner's
     * mails off but for the ALWAYS ones, with no word on the modal; the summary only shows «None» afterwards.
     */
    public function test_bug_make_primary_on_a_new_contact_turns_the_owners_mails_off(): void
    {
        $this->client->primaryContact->update(['country' => 'GR']);
        $this->takes(null);
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->client->id]));
        Livewire::test(Contacts::class)->call('create')
            ->set('contact.first_name', 'New')->set('contact.last_name', 'Company')->set('contact.email', 'new@example.test')->set('contact.country', 'GR')
            ->call('save')->assertHasNoErrors();
        $contact = Contact::query()->where('email', 'new@example.test')->sole();
        $this->assertSame([], $contact->email_preferences, 'precondition: a new contact is saved with nothing ticked');

        $contact->makePrimary(); // ContactsRelationManager «Make primary»
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $this->clean();

        NotificationHelper::invoiceCreatedNotification($this->client->fresh(), $invoice->fresh());

        $this->assertSame(['new_invoice_created'], $this->mailed(), 'the owner took every kind before staff changed the account\'s details');
    }

    // ---- ADV-545-7: WHMCS's own choices are not imported ------------------------------------------------------------

    /**
     * ADV-545-7 (question for owner; the importer is outside the diff). WHMCS keeps the client's choices in
     * tblclients.email_preferences (JSON, e.g. {"product":0}); the importer never reads it, so every imported primary
     * contact is null («every kind»). At cut-over a customer who opted out of product mails in WHMCS starts getting them.
     */
    public function test_bug_whmcs_email_preferences_are_not_imported(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $whmcs->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT,
            second_factor TEXT, second_factor_config TEXT, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT,
            tax_id TEXT, phonenumber TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT,
            taxexempt INT, separateinvoices INT, disableautocc INT, credit REAL, currency INT, created_at TEXT, updated_at TEXT, email_preferences TEXT)');
        $whmcs->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT,
            last_login TEXT, created_at TEXT, updated_at TEXT)');
        $whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT)');
        $whmcs->prepare('INSERT INTO tblusers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([901, 'First', 'Last', 'login901@example.test', Hash::make('Whmcs-Pass-1'), '', null, null, '2024-01-01 00:00:00', '2025-01-01 00:00:00']);
        $whmcs->prepare("INSERT INTO tblclients VALUES (901, 'First', 'Last', '', 'billing901@example.test', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GR',
            0, 0, 0, 0, 1, '2024-01-01 00:00:00', '2025-01-01 00:00:00', ?)")
            ->execute(['{"general":1,"invoice":1,"support":1,"product":0,"domain":1,"affiliate":1}']);
        $whmcs->exec('INSERT INTO tblusers_clients (auth_user_id, client_id, owner) VALUES (901, 901, 1)');

        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));
        (new ReflectionMethod($command, 'importUsers'))->invoke($command);

        $contact = Contact::query()->where('primary_of_client_id', 901)->sole();
        $this->assertTrue($contact->takesEmail('invoice'), 'precondition: imported');
        $this->assertFalse($contact->takesEmail('product'), 'the customer opted out of product mails in WHMCS');
    }

    // ---- what held ----------------------------------------------------------------------------------------------------

    /** Resend from the email log (MailAudit::resend) goes whatever the contact takes now. */
    public function test_holds_resend_from_the_email_log_is_never_gated(): void
    {
        $this->takes(null);
        NotificationHelper::serviceCancelledNotification($this->client, $this->service());
        $log = EmailLog::query()->where('template_key', 'service_cancelled')->sole();
        $this->takes([]);

        MailAudit::resend($log, $log->to, staff: Staff::factory()->admin()->create());

        $this->assertSame(['service_cancelled', 'service_cancelled'], $this->mailed());
    }

    /** The ALWAYS mails keep their in-app notification, and a gated kind keeps it too (spec 5), with [] or a partial list. */
    public function test_holds_in_app_goes_for_gated_and_always_kinds(): void
    {
        $this->takes([]);
        $service = $this->service();

        NotificationHelper::serverUnsuspendedNotification($this->client, $service);
        NotificationHelper::serverSuspendedNotification($this->client, $service);

        $this->assertSame(['server_suspended'], $this->mailed());
        $this->assertSame(2, Notification::query()->where('user_id', $this->owner->id)->count());
    }

    /** Only the owner is gated: a non-owner login's own mails (verification, password reset) and an invite go anyway. */
    public function test_holds_login_mails_of_another_user_of_the_account_are_not_gated(): void
    {
        $this->takes([]);
        $other = User::factory()->create(['email' => 'other@example.test']);
        $this->clean();

        NotificationHelper::passwordResetNotification($other, ['url' => 'https://example.test/reset']);
        NotificationHelper::emailVerificationNotification($other);

        $this->assertSame(['password_reset', 'email_verification'],
            EmailLog::query()->where('to', 'other@example.test')->orderBy('id')->pluck('template_key')->all());
    }

    /** An account with no primary contact (legacy row) takes every kind, as null. */
    public function test_holds_an_account_without_a_primary_contact_takes_every_kind(): void
    {
        $this->client->primaryContact->forceFill(['primary_of_client_id' => null])->save();
        $client = Client::query()->find($this->client->id);
        $this->assertNull($client->primaryContact, 'precondition: none');

        NotificationHelper::serviceCancelledNotification($client, $this->service());

        $this->assertSame(['service_cancelled'], $this->mailed());
    }

    /** The client area's other contacts never edit the primary's choices (Contacts::contactOf excludes it). */
    public function test_holds_the_client_contacts_page_cannot_edit_the_primary(): void
    {
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->client->id]));

        Livewire::test(Contacts::class)->call('edit', $this->client->primaryContact->id)->assertStatus(404)->assertSet('showForm', false);
    }
}
