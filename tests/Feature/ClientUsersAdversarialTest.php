<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\RelationManagers\InvitesRelationManager;
use App\Admin\Resources\ClientResource\RelationManagers\UsersRelationManager;
use App\Classes\Cart;
use App\Exceptions\DisplayException;
use App\Livewire\Cart as CartPage;
use App\Livewire\Client\AcceptInvite;
use App\Livewire\Client\Account;
use App\Livewire\Client\Accounts;
use App\Livewire\Client\Users;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Livewire\Invoices\Widget as InvoicesWidget;
use App\Livewire\Services\Show as ServiceShow;
use App\Livewire\Services\Widget as ServicesWidget;
use App\Livewire\Tickets\Create as TicketCreate;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientInvite;
use App\Models\ClientUser;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Server;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use App\Support\CurrentClient;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

/**
 * Adversarial review of PR 2b (several logins per account, WHMCS permissions, invitations; docs/accounts.md).
 * `test_bug_*` fail on the branch as reviewed (326ce86) and describe a real issue; `test_holds_*` pin guarantees that
 * held under attack, so a later change cannot quietly lose them.
 */
class ClientUsersAdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int, string>|null  $permissions */
    private function member(Client $client, ?array $permissions): User
    {
        $user = User::factory()->withoutClient()->create();
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $user->id, 'permissions' => $permissions, 'granted_at' => now()]);

        return $user;
    }

    private function grantOf(Client $client, User $user): ClientUser
    {
        return ClientUser::query()->where('client_id', $client->id)->where('user_id', $user->id)->sole();
    }

    /** The login works on $client in the client area. */
    private function workOn(User $user, Client $client): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $client->id]));
    }

    private function serviceOf(Client $client, array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(array_merge(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth()], $attributes));
    }

    /** An active service on a cPanel server, provisioned (it has its cPanel user): its page offers «Access cPanel». */
    private function cpanelServiceOf(Client $client): Service
    {
        $service = $this->serviceOf($client);
        $server = Server::query()->create(['name' => 'cp', 'type' => 'server', 'extension' => 'CPanel', 'enabled' => true]);
        $service->product->forceFill(['server_id' => $server->id])->save();
        $service->properties()->updateOrCreate(['key' => 'cpanel_username'], ['value' => 'acct1']);

        return $service->fresh();
    }

    /** The snapshot of a component, by its Livewire name, from a rendered page. */
    private function snapshotOf(string $html, string $name): string
    {
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $m);
        $names = [];
        foreach ($m[1] as $raw) {
            $decoded = html_entity_decode($raw, ENT_QUOTES);
            $names[] = $found = json_decode($decoded, true)['memo']['name'] ?? '?';
            if ($found === $name) {
                return $decoded;
            }
        }
        $this->fail("no {$name} component on the page; found: " . implode(', ', $names));
    }

    /** A real Livewire update request (through the persistent middleware, unlike Livewire::test). */
    private function livewireUpdate(string $snapshot, array $updates = [], array $calls = [])
    {
        return $this->withHeaders(['X-Livewire' => '1'])->postJson(app(HandleRequests::class)->getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => $updates ?: new \stdClass, 'calls' => $calls]],
        ]);
    }

    private function mailOn(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Findings (fail on 326ce86)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * F1: `productsso` is a dead permission and `manageproducts` silently includes single sign-on. Services\Show gates
     * a button on `productsso` only when the extension marks it `'sso' => true`, and no server extension does: the
     * panel logins (cPanel getLoginUrl, DirectAdmin ssoLink, Enhance, Plesk, Virtualizor…) are plain buttons. A member
     * given «manage products» but explicitly not «single sign-on» gets a full cPanel session.
     */
    public function test_bug_manageproducts_without_productsso_still_gets_the_panel_login(): void
    {
        $client = User::factory()->create()->client();
        $service = $this->cpanelServiceOf($client);
        $manager = $this->member($client, ['products', 'manageproducts']);

        $this->workOn($manager, $client);
        $buttons = $this->livewireAs($manager)->test(ServiceShow::class, ['service' => $service])->get('buttons');

        $this->assertNotContains('getLoginUrl', array_column($buttons, 'function'),
            'a member without `productsso` is offered «Access cPanel» (single sign-on into the hosting panel)');
    }

    /** F1 (other half): a member given only `productsso` (+ products to see the service) gets no panel login at all. */
    public function test_bug_productsso_alone_does_not_give_the_panel_login(): void
    {
        $client = User::factory()->create()->client();
        $service = $this->cpanelServiceOf($client);
        $sso = $this->member($client, ['products', 'productsso']);

        $this->workOn($sso, $client);
        $buttons = $this->livewireAs($sso)->test(ServiceShow::class, ['service' => $service])->get('buttons');

        $this->assertContains('getLoginUrl', array_column($buttons, 'function'), '`productsso` grants nothing: the SSO button needs `manageproducts`');
    }

    /**
     * F2: /account/users is outside MustVerfiyEmail: with «must verify email» on, a just-registered login that never
     * proved its address (one per registration, each owning an account) opens the page and mails invitations, whose
     * subject and body carry its own name (account.name = owner's name, invite.by = inviter's name), to any address,
     * from the operator's mail server. The rest of the client area redirects it to verification.notice.
     */
    public function test_bug_unverified_owner_can_mail_invitations_when_verification_is_required(): void
    {
        $this->mailOn();
        config(['settings.mail_must_verify' => true, 'settings.default_currency' => 'USD']);
        $owner = User::factory()->create(['email_verified_at' => null, 'first_name' => 'Claim your prize at', 'last_name' => 'evil.example']);
        $client = $owner->client();

        $this->workOn($owner, $client)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $status = $this->workOn($owner, $client)->get(route('account.users'))->status();

        $this->livewireAs($owner)->test(Users::class)->set('email', 'victim@example.test')->call('invite');

        $this->assertSame(302, $status, 'the users page is open to an unverified login');
        $this->assertSame(0, EmailLog::query()->where('to', 'victim@example.test')->count(),
            'an unverified login sent an invitation mail carrying its own text to an arbitrary address');
    }

    /**
     * F3: the only brake on invitation mail is 5 a minute per account (RateLimiter, 60 s): 300 an hour, 7 200 a day
     * per account, to arbitrary addresses, with attacker-chosen text in the subject. Expect an hourly/daily cap (or
     * a per-login one and a check that the address is not already invited).
     */
    public function test_bug_invitation_mail_volume_is_bounded_only_per_minute(): void
    {
        $this->mailOn();
        $owner = User::factory()->create();
        $client = $owner->client();
        $this->workOn($owner, $client);

        $sent = 0;
        for ($minute = 0; $minute < 6; $minute++) {
            for ($i = 0; $i < 5; $i++) {
                $this->livewireAs($owner)->test(Users::class)->set('email', "target{$minute}-{$i}@example.test")->call('invite');
            }
            $this->travel(61)->seconds();
        }
        $sent = EmailLog::query()->where('to', 'like', 'target%@example.test')->count();

        $this->assertLessThanOrEqual(20, $sent, "one account mailed {$sent} arbitrary addresses in 6 minutes");
    }

    /**
     * F4: the gateways' «save a payment method» return routes (PayPal, Stripe setup-agreement) are `web, auth` only,
     * without `client.can:invoices`: a tickets-only member adds a payment method to the account (e.g. with a setup
     * token from a flow started on another account they own). PaymentMethods itself is gated; its return leg is not.
     */
    public function test_bug_member_without_invoices_saves_a_payment_method_through_the_gateway_return_route(): void
    {
        $client = User::factory()->create()->client();
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $member = $this->member($client, ['tickets']);
        Http::fake(['*' => Http::response([
            'access_token' => 'token', 'expires_in' => 3600, 'id' => 'VAULT-1',
            'payment_source' => ['paypal' => ['email_address' => 'attacker@example.test']],
        ])]);

        config(['settings.default_currency' => 'USD']);
        // The gateway's own route file, as the enabled extension registers it (the row is made after boot here).
        Route::group([], base_path('extensions/Gateways/PayPal/routes.php'));
        app('router')->getRoutes()->refreshNameLookups();

        $this->workOn($member, $client)->get(route('account.payment-methods'))->assertForbidden();
        $this->workOn($member, $client)->get(route('extensions.gateways.paypal.setup-agreement', ['approval_token_id' => 'SETUP-1']));

        $this->assertSame(0, BillingAgreement::query()->where('client_id', $client->id)->count(),
            'a member without `invoices` saved a payment method on the account');
    }

    /**
     * F5 (nit): ticket creation lists every service of the account (product, label, status, due date) to a member
     * without `products`, who is refused the services list and every service page.
     */
    public function test_bug_tickets_only_member_sees_the_services_in_the_new_ticket_form(): void
    {
        $client = User::factory()->create()->client();
        $this->serviceOf($client, ['label' => 'secret-box-label']);
        $member = $this->member($client, ['tickets']);

        $this->workOn($member, $client)->get(route('services'))->assertForbidden();
        $this->workOn($member, $client);
        $this->livewireAs($member)->test(TicketCreate::class)->assertDontSee('secret-box-label');
    }

    /**
     * F6 (nit): paying an invoice with «set as default» re-points the recurring services' payment method, which the
     * PR documents as `manageproducts` (Services\Show::updateBillingAgreement is gated on it): an `invoices`-only
     * member changes it anyway.
     */
    public function test_bug_invoices_only_member_changes_the_services_payment_method_while_paying(): void
    {
        $client = User::factory()->create()->client();
        $service = $this->serviceOf($client);
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $agreement = BillingAgreement::create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'PayPal x',
            'external_reference' => 'B-1', 'type' => 'paypal']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'price' => 10, 'quantity' => 1, 'description' => 'x']);
        $billing = $this->member($client, ['invoices']);
        $this->assertFalse(Gate::forUser($billing)->allows('update', $service), 'precondition: the member may not manage the service');

        $this->workOn($billing, $client);
        try {
            $this->livewireAs($billing)->test(InvoiceShow::class, ['invoice' => $invoice])
                ->set('selectedMethod', $agreement->ulid)->set('setAsDefault', true)->call('processPayment');
        } catch (\Throwable) {
            // the charge itself may fail without a configured gateway: the change happens before it
        }

        $this->assertNull($service->fresh()->billing_agreement_id, 'the service\'s payment method changed without `manageproducts`');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Guarantees that held
    // ---------------------------------------------------------------------------------------------------------------

    public function test_holds_a_limited_member_cannot_reach_other_areas_by_url(): void
    {
        $client = User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $service = $this->serviceOf($client);
        $member = $this->member($client, ['tickets']);
        config(['settings.credits_enabled' => true, 'settings.default_currency' => 'USD']);

        $this->workOn($member, $client)->get(route('invoices'))->assertForbidden();
        $this->workOn($member, $client)->get(route('invoices.show', $invoice))->assertNotFound();
        $this->workOn($member, $client)->get(route('account.credits'))->assertForbidden();
        $this->workOn($member, $client)->get(route('account.payment-methods'))->assertForbidden();
        $this->workOn($member, $client)->get(route('services'))->assertForbidden();
        $this->workOn($member, $client)->get(route('services.show', $service))->assertNotFound();
        $this->workOn($member, $client)->get(route('services.upgrade', $service))->assertNotFound();
        $this->workOn($member, $client)->get(route('account.users'))->assertForbidden();
        $this->workOn($member, $client)->get(route('tickets'))->assertOk();
    }

    public function test_holds_dashboard_widgets_called_on_their_own_show_nothing_without_the_permission(): void
    {
        $client = User::factory()->create()->client();
        Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'number' => 'INV-SECRET-77']);
        $this->serviceOf($client, ['label' => 'secret-box-label']);
        $member = $this->member($client, ['tickets']);

        $this->workOn($member, $client);
        $this->livewireAs($member)->test(InvoicesWidget::class)->assertDontSee('INV-SECRET-77');
        $this->livewireAs($member)->test(ServicesWidget::class)->assertDontSee('secret-box-label');
    }

    public function test_holds_viewing_a_service_does_not_manage_it_even_by_direct_calls(): void
    {
        $client = User::factory()->create()->client();
        $service = $this->serviceOf($client, ['label' => 'before']);
        $viewer = $this->member($client, ['products']);

        $this->workOn($viewer, $client);
        $page = $this->livewireAs($viewer)->test(ServiceShow::class, ['service' => $service]);
        foreach ([fn () => $page->set('label', 'hacked')->call('updateLabel'), fn () => $page->call('clearBillingAgreement'),
            fn () => $page->set('showCancel', true)] as $attempt) {
            try {
                $attempt();
            } catch (\Throwable) {
            }
        }

        $this->assertSame('before', $service->fresh()->label);
        $this->assertFalse((bool) $page->get('showCancel'));
    }

    public function test_holds_a_member_without_orders_cannot_check_out_for_the_account(): void
    {
        $client = User::factory()->create()->client();
        $member = $this->member($client, ['tickets']);
        $product = $this->createProduct();
        $this->workOn($member, $client);
        Cart::add($product->product, $product->plan, [], [], 1);

        $this->livewireAs($member)->test(CartPage::class)->call('checkout');

        $this->assertSame(0, Service::query()->where('client_id', $client->id)->count());
    }

    public function test_holds_account_details_are_not_saved_without_profile(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $client->properties()->updateOrCreate(['key' => 'company_name'], ['value' => 'Original Ltd']);
        $member = $this->member($client, ['tickets']);

        $this->workOn($member, $client);
        try {
            $this->livewireAs($member)->test(Account::class)->set('properties', ['company_name' => 'Hijacked Ltd'])->call('submit');
        } catch (\Throwable) {
        }

        $this->assertSame('Original Ltd', $client->properties()->where('key', 'company_name')->value('value'));
    }

    public function test_holds_a_member_cannot_manage_users_or_raise_their_own_permissions(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $member = $this->member($client, null); // every permission, still not the owner
        $other = $this->member($client, ['tickets']);
        $own = $this->grantOf($client, $member);
        $otherGrant = $this->grantOf($client, $other);

        $this->workOn($member, $client);
        foreach ([
            fn () => $this->livewireAs($member)->test(Users::class)->set('email', 'friend@example.test')->call('invite'),
            fn () => $this->livewireAs($member)->test(Users::class)->call('edit', $otherGrant->id)->set('editAll', true)->call('savePermissions'),
            fn () => $this->livewireAs($member)->test(Users::class)->call('revoke', $otherGrant->id),
        ] as $attempt) {
            try {
                $attempt();
            } catch (\Throwable) {
            }
        }

        $this->assertSame(0, ClientInvite::query()->count());
        $this->assertSame(['tickets'], $otherGrant->fresh()->permissions);
        $this->assertNull($otherGrant->fresh()->revoked_at);
        $this->assertFalse($own->fresh()->owner);
        // The owner cannot edit or revoke their own grant either (grant() excludes it).
        $this->workOn($owner, $client);
        $page = $this->livewireAs($owner)->test(Users::class);
        try {
            $page->call('edit', $client->ownerGrant->id);
        } catch (\Throwable) {
        }
        $this->assertNull($page->get('editing'), 'the owner opened their own grant for editing');
        try {
            $page->call('revoke', $client->ownerGrant->id);
        } catch (\Throwable) {
        }
        $this->assertNull($client->ownerGrant->fresh()->revoked_at);
    }

    public function test_holds_an_owner_cannot_touch_another_accounts_users_or_invitations(): void
    {
        $ownerA = User::factory()->create();
        $a = $ownerA->client();
        $memberA = $this->member($a, ['tickets']);
        [$inviteA] = ClientInvite::issue($a, 'someone@example.test', null, $ownerA);
        $ownerB = User::factory()->create();
        $b = $ownerB->client();

        $this->workOn($ownerB, $b);
        $page = $this->livewireAs($ownerB)->test(Users::class);
        $page->call('cancelInvite', $inviteA->id);
        foreach ([fn () => $page->call('revoke', $this->grantOf($a, $memberA)->id),
            fn () => $page->call('edit', $this->grantOf($a, $memberA)->id)] as $attempt) {
            try {
                $attempt();
            } catch (\Throwable) {
            }
        }

        $this->assertTrue($inviteA->fresh()->isPending());
        $this->assertNull($this->grantOf($a, $memberA)->revoked_at);
    }

    public function test_holds_a_limited_member_accepting_a_wider_invitation_is_not_raised(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $member = $this->member($client, ['tickets']);
        [, $token] = ClientInvite::issue($client, 'newcolleague@example.test', null, $owner);

        $this->actingAs($member)->withSession($this->loginUser($member, [CurrentClient::SESSION_KEY => $client->id]));
        $this->livewireAs($member)->test(AcceptInvite::class, ['token' => $token])->call('accept');

        $this->assertSame(['tickets'], $this->grantOf($client, $member)->permissions);
    }

    public function test_holds_an_invitation_is_single_use_and_dies_on_cancel_or_expiry(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        [$invite, $token] = ClientInvite::issue($client, 'x@example.test', ['tickets'], $owner);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $invite->accept($first);
        try {
            ClientInvite::findByToken($token)->accept($second);
            $this->fail('accepted twice');
        } catch (DisplayException) {
        }
        // A stale in-memory copy (a second click raced in) is re-read under the lock.
        [$raced, $racedToken] = ClientInvite::issue($client, 'y@example.test', ['tickets'], $owner);
        $copy = ClientInvite::findByToken($racedToken);
        $raced->accept($first);
        try {
            $copy->accept($second);
            $this->fail('the stale copy accepted again');
        } catch (DisplayException) {
        }
        // Cancelled by the owner, and expired.
        [$cancelled, $cancelledToken] = ClientInvite::issue($client, 'z@example.test', null, $owner);
        $this->workOn($owner, $client);
        $this->livewireAs($owner)->test(Users::class)->call('cancelInvite', $cancelled->id);
        [$expired, $expiredToken] = ClientInvite::issue($client, 'w@example.test', null, $owner);
        $this->travel(ClientInvite::VALID_DAYS)->days();
        $this->travel(1)->minutes();
        $third = User::factory()->create();
        $this->actingAs($third)->withSession($this->loginUser($third));
        foreach ([$cancelledToken, $expiredToken, strtoupper($racedToken)] as $t) {
            $this->livewireAs($third)->test(AcceptInvite::class, ['token' => $t])->call('accept');
        }

        $this->assertFalse($second->clients()->whereKey($client->id)->exists());
        $this->assertFalse($third->clients()->whereKey($client->id)->exists());
        $this->assertSame(64, strlen((string) ClientInvite::query()->whereKey($invite->id)->value('token_hash')), 'only a sha256 is stored');
    }

    public function test_holds_staff_logged_in_as_client_cannot_accept_invitations_as_the_customer(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        [, $token] = ClientInvite::issue($client, 'x@example.test', null, $owner);
        $customer = User::factory()->create();
        $staff = Staff::factory()->withPermissions(['admin.users.viewAny', 'admin.users.impersonate'])->create();

        $this->actingAs($customer)->withSession($this->loginUser($customer, ['impersonating' => $customer->id]));
        $this->actingAs($staff, 'staff');
        $this->livewireAs($customer)->test(AcceptInvite::class, ['token' => $token])->call('accept');

        $this->assertFalse($customer->clients()->whereKey($client->id)->exists());
    }

    public function test_holds_a_revoked_member_cannot_switch_to_or_open_the_account(): void
    {
        $client = User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $member = User::factory()->create(); // owns their own account too
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $member->id, 'granted_at' => now()]);
        $client->revoke($this->grantOf($client, $member));

        $this->workOn($member, $member->client());
        $this->livewireAs($member)->test(Accounts::class)->call('switch', $client->id);
        $this->assertNotSame($client->id, Session::get(CurrentClient::SESSION_KEY));

        // A session that still names the account (chosen before the revocation) falls back to the member's own.
        $this->workOn($member, $client)->get(route('invoices.show', $invoice))->assertNotFound();
        $this->assertNotSame($client->id, CurrentClient::id());
    }

    /** Real Livewire requests: a page opened while allowed stops working once the permission is taken away. */
    public function test_holds_an_open_page_stops_working_once_the_permission_is_taken_away(): void
    {
        config(['settings.default_currency' => 'USD']);
        $client = User::factory()->create()->client();
        $member = $this->member($client, ['tickets']);

        $html = $this->workOn($member, $client)->get(route('tickets.create'))->assertOk()->getContent();
        $snapshot = $this->snapshotOf($html, 'tickets.create');
        $form = ['subject' => 'Help', 'message' => 'Text', 'priority' => 'low',
            'department' => array_values((array) config('settings.ticket_departments'))[0] ?? ''];
        $create = [['path' => '', 'method' => 'create', 'params' => []]];

        // Control: while allowed, the real request creates the ticket (the harness reaches the component).
        $this->livewireUpdate($snapshot, $form, $create)->assertOk();
        $this->assertSame(1, Ticket::query()->where('client_id', $client->id)->count());

        $this->grantOf($client, $member)->forceFill(['permissions' => ['invoices']])->save();
        // A later request is a new process in production; within one test the scoped CurrentClient (its grant cache)
        // and Livewire's «middleware already applied for this route» memo survive, so reset them as a new request would.
        app(CurrentClient::class)->forget();
        Livewire::flushState();
        $this->travel(31)->seconds(); // past the ticket rate limit
        $status = $this->livewireUpdate($snapshot, $form, $create)->status();

        $this->assertSame(403, $status);
        $this->assertSame(1, Ticket::query()->where('client_id', $client->id)->count());
    }

    public function test_holds_a_revoked_members_open_invoice_page_stops_answering(): void
    {
        $client = User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $member = $this->member($client, ['invoices']);

        $html = $this->workOn($member, $client)->get(route('invoices.show', $invoice))->assertOk()->getContent();
        $snapshot = $this->snapshotOf($html, 'invoices.show');
        $client->revoke($this->grantOf($client, $member));

        $response = $this->livewireUpdate($snapshot, [], [['path' => '', 'method' => 'downloadPDF', 'params' => []]]);

        $this->assertNotSame(200, $response->status(), 'the revoked member still downloads the invoice');
    }

    public function test_holds_ownership_stays_single_and_the_owner_is_never_revoked(): void
    {
        $old = User::factory()->create();
        $client = $old->client();
        $a = $this->member($client, ['tickets']);
        $b = $this->member($client, ['invoices']);
        $client->revoke($this->grantOf($client, $b));
        $staff = Staff::factory()->admin()->create();

        $client->transferOwnership($a, $staff);
        $client->transferOwnership($b, $staff); // a revoked login comes back as the owner
        $client->transferOwnership($b, $staff); // idempotent

        $this->assertSame(1, ClientUser::query()->where('client_id', $client->id)->whereNotNull('owner_of_client_id')->count());
        $this->assertTrue($client->fresh()->owner()->is($b));
        $this->assertNull($this->grantOf($client, $b)->revoked_at);
        $this->assertNull($this->grantOf($client, $a)->permissions, 'a former owner keeps all');
        $this->expectException(DisplayException::class);
        $client->revoke($this->grantOf($client, $b));
    }

    public function test_holds_staff_without_clients_update_cannot_run_the_user_actions(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = User::factory()->create();
        $client = $owner->client();
        $member = $this->member($client, ['tickets']);
        $other = User::factory()->withoutClient()->create();
        [$invite] = ClientInvite::issue($client, 'p@example.test', null, $owner);
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view'])->create();
        $grant = $this->grantOf($client, $member);

        $page = fn () => $this->livewireAs($staff)->test(UsersRelationManager::class, ['ownerRecord' => $client->fresh(), 'pageClass' => EditClient::class]);
        foreach ([
            fn () => $page()->callTableAction('add', data: ['user_id' => $other->id, 'all' => true]),
            fn () => $page()->callTableAction('invite', data: ['email' => 'q@example.test', 'all' => true]),
            fn () => $page()->callTableAction('permissions', $grant, data: ['all' => true]),
            fn () => $page()->callTableAction('owner', $grant),
            fn () => $page()->callTableAction('revoke', $grant),
            fn () => $this->livewireAs($staff)->test(InvitesRelationManager::class, ['ownerRecord' => $client->fresh(), 'pageClass' => EditClient::class])
                ->callTableAction('cancel', $invite),
        ] as $attempt) {
            try {
                $attempt();
            } catch (\Throwable) {
            }
        }

        $this->assertFalse($other->clients()->whereKey($client->id)->exists());
        $this->assertSame(1, ClientInvite::query()->count());
        $this->assertTrue($invite->fresh()->isPending());
        $this->assertSame(['tickets'], $grant->fresh()->permissions);
        $this->assertNull($grant->fresh()->revoked_at);
        $this->assertTrue($client->fresh()->owner()->is($owner));
    }
}
