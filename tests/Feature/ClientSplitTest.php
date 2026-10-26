<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\CreateClient;
use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\ApiKey;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Money\CreditLedger;
use App\Services\Money\MoneyTrail;
use App\Support\CurrentClient;
use App\Support\StaffLink;
use Database\Seeders\EmailTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * The customer account apart from the login (docs/accounts.md, PR 2a): services, invoices and credit are the account's;
 * a login sees the accounts it has a live grant on; the client area works on the current one; the account's mail goes
 * to its owner.
 */
class ClientSplitTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceOf(Client $client): Invoice
    {
        return Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
    }

    public function test_a_new_user_owns_one_account_with_another_id(): void
    {
        $user = User::factory()->create();
        $client = $user->client();

        $this->assertNotNull($client);
        $this->assertNotSame($user->id, $client->id, 'tests keep the two numberings apart');
        $this->assertTrue($client->owner()->is($user));
        $this->assertSame([$client->id], $user->clients()->pluck('clients.id')->all());
    }

    public function test_an_account_has_one_owner_at_most(): void
    {
        $client = User::factory()->create()->client();
        $other = User::factory()->withoutClient()->create();

        $this->expectException(UniqueConstraintViolationException::class);
        ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $other->id, 'owner' => true, 'owner_of_client_id' => $client->id]);
    }

    public function test_a_login_sees_the_accounts_it_has_a_live_grant_on(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $invoice = $this->invoiceOf($client);
        $guest = User::factory()->create();

        $this->assertTrue(Gate::forUser($owner)->allows('view', $invoice));
        $this->assertFalse(Gate::forUser($guest)->allows('view', $invoice));

        $grant = ClientUser::query()->create(['client_id' => $client->id, 'user_id' => $guest->id, 'granted_at' => now()]);
        $this->assertTrue(Gate::forUser($guest)->allows('view', $invoice));

        // Revoked, not deleted: the grant stays as history and gives nothing.
        $grant->forceFill(['revoked_at' => now()])->save();
        $this->assertFalse(Gate::forUser($guest)->allows('view', $invoice));
        $this->assertSame(1, $client->grants()->whereNotNull('revoked_at')->count());
    }

    public function test_a_user_id_is_never_taken_for_an_account_id(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        // An account whose id is the stranger's user id: the coincidence must not give it to them.
        $client = Client::openFor($owner, $stranger->id);
        $invoice = $this->invoiceOf($client);

        $this->assertFalse(Gate::forUser($stranger)->allows('view', $invoice));
        $this->assertTrue(Gate::forUser($owner)->allows('view', $invoice));
    }

    public function test_the_client_area_works_on_the_current_account_and_never_a_foreign_one(): void
    {
        $user = User::factory()->create();
        $mine = $user->client();
        $foreign = User::factory()->create()->client();
        $foreignInvoice = $this->invoiceOf($foreign);

        // A session pointing at somebody else's account is not taken: the user's own is.
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $foreign->id]))
            ->get(route('invoices.show', $foreignInvoice))->assertNotFound();
        $this->assertSame($mine->id, CurrentClient::id());
        $this->assertSame($mine->id, Session::get(CurrentClient::SESSION_KEY));
    }

    public function test_a_login_without_an_account_gets_its_own_in_the_client_area(): void
    {
        $user = User::factory()->withoutClient()->create();
        $this->assertNull($user->client());

        $this->actingAs($user)->withSession($this->loginUser($user))->get(route('dashboard'))->assertOk();

        $this->assertTrue($user->fresh()->client()?->owner()?->is($user));
    }

    public function test_the_accounts_mail_goes_to_its_owner_and_is_logged_on_the_account(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        (new EmailTemplateSeeder)->run();
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $client = $owner->client();
        $invoice = $this->invoiceOf($client);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);

        NotificationHelper::invoiceCreatedNotification($client, $invoice->fresh());

        $log = EmailLog::query()->latest('id')->firstOrFail();
        $this->assertSame('owner@example.test', $log->to);
        $this->assertSame($client->id, $log->client_id);
    }

    public function test_money_locks_and_moves_on_the_account(): void
    {
        $client = User::factory()->create()->client();

        (new CreditLedger)->add($client->id, 'USD', 500, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Goodwill');

        $this->assertSame(500, (new CreditLedger)->balanceCents($client->id, 'USD'));
        $this->assertSame($client->id, (int) $client->moneyEvents()->value('client_id'));
        $this->assertTrue($client->hasMoneyHistory());
    }

    public function test_an_account_with_money_history_or_its_owner_are_not_deleted(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        (new CreditLedger)->add($client->id, 'USD', 100, MoneyTrail::CAUSE_STAFF_ADJUSTMENT, MoneyTrail::SOURCE_STAFF, note: 'Goodwill');

        try {
            $client->delete();
            $this->fail('an account with money history was deleted');
        } catch (DisplayException) {
        }
        try {
            $owner->delete();
            $this->fail('the owner of an account was deleted');
        } catch (DisplayException) {
        }
        $this->assertNotNull(Client::query()->find($client->id));
        $this->assertNotNull(User::query()->find($owner->id));
    }

    public function test_staff_create_an_account_with_its_owner(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->withPermissions(['admin.clients.create', 'admin.clients.viewAny', 'admin.clients.update'])->create();

        $this->livewireAs($staff)->test(CreateClient::class)
            ->fillForm(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'password' => 'secret-pass-1', 'credit_auto_apply' => false])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'ada@example.test')->sole();
        $client = $user->client();
        $this->assertTrue($client->owner()->is($user));
        $this->assertFalse($client->credit_auto_apply);
        $this->assertSame($staff->id, (int) $client->ownerGrant->granted_by);
    }

    public function test_login_as_client_from_the_account_opens_it_as_its_owner(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = User::factory()->create();
        $client = $owner->client();
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.update', 'admin.users.impersonate'])->create();

        // A link into a new tab (owner 2026-10-26), whose signed route starts it.
        $this->livewireAs($staff)->test(EditClient::class, ['record' => $client->getRouteKey()])->assertActionShouldOpenUrlInNewTab('impersonate');
        $this->actingAs($staff, 'staff')->get(StaffLink::to('filament.admin.login-as.client', ['client' => $client->id]))->assertRedirect('/dashboard');

        $this->assertSame($owner->id, Session::get('impersonating'));
    }

    public function test_login_as_client_opens_the_account_staff_chose(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = User::factory()->create();
        $second = Client::openFor($owner, null);
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.update', 'admin.users.impersonate'])->create();
        $this->assertNotSame(CurrentClient::defaultFor($owner)?->id, $second->id, 'precondition: not the default account');

        $this->actingAs($staff, 'staff')->get(StaffLink::to('filament.admin.login-as.client', ['client' => $second->id]))->assertRedirect('/dashboard');
        $this->actingAs($staff)->withSession(Session::all())->get(route('dashboard'))->assertOk();

        $this->assertSame($second->id, CurrentClient::id());
    }

    public function test_an_invoice_is_charged_only_to_a_payment_method_of_its_account(): void
    {
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $foreign = BillingAgreement::create(['client_id' => User::factory()->create()->client()->id, 'gateway_id' => $gateway->id,
            'name' => 'PayPal', 'external_reference' => 'VAULT-F', 'type' => 'paypal']);
        $invoice = $this->invoiceOf(User::factory()->create()->client());

        $this->expectException(DisplayException::class);
        ExtensionHelper::charge($gateway, $invoice, $foreign);
    }

    public function test_an_account_is_charged_only_to_its_own_card(): void
    {
        // Decision 6 (PR C2): the method is the account's; a service moved to another account is charged to that one's.
        $from = User::factory()->create()->client();
        $to = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway']);
        $card = BillingAgreement::create(['client_id' => $from->id, 'gateway_id' => $gateway->id,
            'name' => 'PayPal', 'external_reference' => 'VAULT-M', 'type' => 'paypal']);

        $this->expectException(DisplayException::class);
        $to->update(['default_billing_agreement_id' => $card->id]);
    }

    public function test_the_api_opens_and_reads_accounts(): void
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['name' => 'k', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'staff_id' => Staff::factory()->admin()->create()->id, 'permissions' => ['admin.clients.create', 'admin.clients.view', 'admin.services.view']]);
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];

        $id = $this->postJson('/api/v1/admin/clients', ['first_name' => 'Bo', 'last_name' => 'B', 'email' => 'bo@example.test', 'password' => 'secret-pass-1'], $headers)
            ->assertSuccessful()->json('data.id');
        $client = Client::query()->findOrFail($id);
        $this->assertSame('bo@example.test', $client->email);
        Service::factory()->create(['client_id' => $client->id]);

        $this->getJson("/api/v1/admin/clients/{$id}?include=services", $headers)->assertOk()
            ->assertJsonPath('data.attributes.email', 'bo@example.test')
            ->assertJsonCount(1, 'data.relationships.services.data');
    }
}
