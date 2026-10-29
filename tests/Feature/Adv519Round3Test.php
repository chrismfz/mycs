<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\RelationManagers\UsersRelationManager;
use App\Livewire\Domains\Show as DomainShow;
use App\Livewire\Invoices\Show as InvoiceShow;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Ssl\SslVault;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial review of #519, round 3: for each permission a login can be given, what it lets the login do, against
 * what GRANT_PERMISSION_AS asks of the staff giving it. Each test: staff give a login of their own the permission on the
 * Users tab, then use it as that login (not impersonating); what «Login as client» refuses them must not happen.
 */
class Adv519Round3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @param  list<string>  $permissions */
    private function staff(array $permissions = []): Staff
    {
        return Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view', 'admin.clients.update', ...$permissions])->create();
    }

    /** @param  list<string>  $permissions */
    private function give(Staff $staff, Client $client, User $login, array $permissions): ?ClientUser
    {
        $this->livewireAs($staff)->test(UsersRelationManager::class, ['ownerRecord' => $client->fresh(), 'pageClass' => EditClient::class])
            ->callTableAction('add', data: ['user_id' => $login->id, 'all' => false, 'permissions' => $permissions]);
        auth('staff')->logout();

        return ClientUser::query()->where('client_id', $client->id)->where('user_id', $login->id)->whereNull('revoked_at')->first();
    }

    /**
     * ADV-519-R3-1: `invoices` takes only admin.invoices.viewAny, but a login with it pays with the account's balance (and
     * its saved cards, auto-charge, Credits, Pay together): what «Login as client» gates behind `pay`, admin.invoices.update.
     * A read-only billing role pays the customer's invoice with the customer's credit.
     */
    public function test_adv_519_r3_1_invoices_lets_a_view_only_role_spend_the_customers_credit(): void
    {
        config(['settings.credits_enabled' => true]);
        $client = User::factory()->create()->client();
        $client->credits()->create(['currency_code' => 'USD', 'amount' => 50.00]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20.00]);
        $staff = $this->staff(['admin.invoices.viewAny']);
        $ownLogin = User::factory()->withoutClient()->create();

        $grant = $this->give($staff, $client, $ownLogin, ['invoices']);
        if ($grant !== null && $grant->allows('invoices')) {
            $this->actingAs($ownLogin);
            $this->livewireAs($ownLogin)->test(InvoiceShow::class, ['invoice' => $invoice->fresh()])
                ->set('selectedMethod', 'credit')->call('processPayment');
        }

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'a role without admin.invoices.update paid with the customer\'s credit');
        $this->assertEquals(50.00, $client->credits()->value('amount'));
    }

    /**
     * ADV-519-R3-2: `manageproducts` takes admin.services.update; the SSL private key (and .pfx) need, while impersonating,
     * admin.services.ssl_keys too (SslVault::customerMayReadKey). On a login of their own that check is skipped.
     */
    public function test_adv_519_r3_2_manageproducts_hands_the_ssl_private_key_to_a_role_without_ssl_keys(): void
    {
        $client = User::factory()->create()->client();
        $service = Service::factory()->create(['client_id' => $client->id, 'status' => 'active']);
        $staff = $this->staff(['admin.services.viewAny', 'admin.services.update']);
        $ownLogin = User::factory()->withoutClient()->create();

        $grant = $this->give($staff, $client, $ownLogin, ['products', 'manageproducts']);
        $this->actingAs($ownLogin);

        $this->assertFalse($grant !== null && SslVault::customerMayReadKey($service),
            'a role without admin.services.ssl_keys reads the SSL private key through its own login');
    }

    /**
     * ADV-519-R3-3: `managedomains` takes viewAny+manage+epp, but its auto renew and renewal years take, while
     * impersonating, admin.domains.update (Domains\Show::toggleAutoRenew, setRenewalYears). Auto renew off is «do not
     * renew»: the domain lapses; on, the customer is billed.
     */
    public function test_adv_519_r3_3_managedomains_toggles_auto_renew_without_domains_update(): void
    {
        $client = User::factory()->create()->client();
        $domain = Domain::factory()->create(['client_id' => $client->id, 'auto_renew' => true]);
        $staff = $this->staff(['admin.domains.viewAny', 'admin.domains.manage', 'admin.domains.epp']);
        $ownLogin = User::factory()->withoutClient()->create();

        $grant = $this->give($staff, $client, $ownLogin, ['domains', 'managedomains']);
        if ($grant !== null && $grant->allows('managedomains')) {
            $this->actingAs($ownLogin);
            try {
                $this->livewireAs($ownLogin)->test(DomainShow::class, ['domain' => $domain->fresh()])->call('toggleAutoRenew');
            } catch (\Throwable) {
                // refused: fine
            }
        }

        $this->assertTrue((bool) $domain->fresh()->auto_renew, 'a role without admin.domains.update turned the domain\'s auto renew off');
    }
}
