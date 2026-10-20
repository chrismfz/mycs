<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Livewire\Client\Account;
use App\Livewire\Client\Contacts;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #247 (P1b-1, contact identity lock), written without the author's context. Failing tests are
 * findings; test_holds_… pin behaviour that held.
 */
class ContactIdentityLockAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->contact = $this->user->client()->contacts()->create(['type' => 'individual', 'first_name' => 'Maria', 'last_name' => 'Papadopoulou',
            'email' => 'maria@example.test', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10431', 'country' => 'GR']);
    }

    private function useIn(string $fqdn, string $role, ?Contact $contact = null, DomainStatus $status = DomainStatus::Active, ?int $clientId = null): Domain
    {
        [$sld, $tld] = explode('.', $fqdn, 2);
        $domainTld = DomainTld::query()->firstOrCreate(['tld' => $tld], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);

        return Domain::factory()->create(['client_id' => $clientId ?? $this->user->client()->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn,
            'domain_tld_id' => $domainTld->id, 'status' => $status, "{$role}_contact_id" => ($contact ?? $this->contact)->id,
            // Author's note (findings of #247): the lock is the registrar's holding (the role's handle, F1); one never sent
            // (pending registration) has none.
            "{$role}_handle" => $status === DomainStatus::PendingRegister ? null : 'H-' . strtoupper($sld)]);
    }

    private function portal(): void
    {
        $this->actingAs($this->user)->withSession($this->loginUser($this->user, [CurrentClient::SESSION_KEY => $this->user->client()->id]));
    }

    // ---- portal: crafted Livewire calls ----------------------------------------------------------------------------

    /** Edit a free contact (unlocked form), then point `editing` (not #[Locked]) at the locked one. */
    public function test_holds_swapping_editing_to_a_locked_contact_does_not_rename_it(): void
    {
        $free = $this->user->client()->contacts()->create(['type' => 'individual', 'first_name' => 'Free', 'last_name' => 'One']);
        $this->useIn('example.gr', 'registrant');
        $this->portal();

        // Author's note (findings of #247): `editing` is #[Locked] now (F4): the swap itself is refused.
        try {
            Livewire::test(Contacts::class)->call('edit', $free->id)->assertSet('identityLocked', false)
                ->set('editing', $this->contact->id)
                ->set('contact.first_name', 'Hacked')->set('contact.last_name', 'Hacker')->set('contact.type', 'business')
                ->call('save');
        } catch (CannotUpdateLockedPropertyException) {
            // refused: fine
        }

        $this->assertSame(['individual', 'Maria', 'Papadopoulou'],
            [$this->contact->fresh()->type, $this->contact->fresh()->first_name, $this->contact->fresh()->last_name]);
    }

    /** Edit the locked contact (identityLocked = true), then `editing` = null: save() creates a new contact. */
    public function test_a_new_contact_saved_after_editing_a_locked_one_keeps_its_name_and_does_not_500(): void
    {
        $this->useIn('example.gr', 'registrant');
        $this->portal();
        $before = Contact::query()->count();

        try {
            Livewire::test(Contacts::class)->call('edit', $this->contact->id)->assertSet('identityLocked', true)
                ->set('editing', null)
                ->set('contact.first_name', 'Nikos')->set('contact.last_name', 'Neos')->set('contact.type', 'individual')
                ->call('save');
        } catch (CannotUpdateLockedPropertyException) {
            // Author's note (findings of #247): `editing` is #[Locked] (F4): refused (a 4xx in a browser, not a 500),
            // and save() takes the lock from the contact it saves.
        } catch (Throwable $e) {
            $this->fail('the portal threw (a 500): ' . $e::class . ': ' . $e->getMessage());
        }

        // A new contact is not a domain's: what was sent for it is its name, or nothing is created.
        $new = Contact::query()->where('id', '>', $this->contact->id)->latest('id')->first();
        if ($new !== null) {
            $this->assertSame(['Nikos', 'Neos'], [$new->first_name, $new->last_name], 'a new contact was created with its name stripped');
        } else {
            $this->assertSame($before, Contact::query()->count());
        }
    }

    /** The form was opened before a domain took the contact: save hits the model's net, shown as an error. */
    public function test_holds_a_contact_taken_by_a_domain_after_the_form_opened_is_refused_without_a_500(): void
    {
        $this->portal();
        $page = Livewire::test(Contacts::class)->call('edit', $this->contact->id)->assertSet('identityLocked', false);
        $this->useIn('example.gr', 'tech');

        $page->set('contact.last_name', 'Georgiou')->call('save');

        $this->assertSame('Papadopoulou', $this->contact->fresh()->last_name);
    }

    public function test_holds_the_account_page_lock_cannot_be_lifted_from_the_browser(): void
    {
        $primary = $this->user->client()->ensurePrimaryContact();
        $this->useIn('example.gr', 'admin', $primary);
        $this->portal();

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Account::class)->assertSet('identityLocked', true)->set('identityLocked', false);
    }

    public function test_holds_the_account_page_ignores_a_crafted_name_for_a_locked_primary_contact(): void
    {
        $primary = $this->user->client()->ensurePrimaryContact();
        $primary->update(['type' => 'individual', 'first_name' => 'Owner', 'last_name' => 'Real', 'country' => 'GR']);
        $this->useIn('example.gr', 'billing', $primary);
        $this->portal();

        Livewire::test(Account::class)
            ->set('contact.first_name', 'Fake')->set('contact.company_name', 'Fake IKE')->set('contact.type', 'business')
            ->set('contact.address1', 'Odos 5')
            ->call('submit')->assertHasNoErrors();

        $this->assertSame(['individual', 'Owner', null, 'Odos 5'],
            [$primary->fresh()->type, $primary->fresh()->first_name, $primary->fresh()->company_name, $primary->fresh()->address1]);
    }

    // ---- «Used by» and who locks ------------------------------------------------------------------------------------

    /** A domain of another account pointing at this contact (bad data, an import): neither shown nor locking here. */
    public function test_another_accounts_domain_is_not_shown_in_used_by_nor_locks_the_contact(): void
    {
        $other = User::factory()->create()->client();
        $this->useIn('secret-of-another.gr', 'registrant', clientId: $other->id);
        $this->portal();

        Livewire::test(Contacts::class)->assertDontSee('secret-of-another.gr')
            ->call('edit', $this->contact->id)->assertSet('identityLocked', false);
    }

    /** DomainStatus::isTerminal() counts `deleted` as no longer ours; the lock (and «Used by») still count it, forever. */
    public function test_a_deleted_domain_does_not_lock_the_contact_forever(): void
    {
        $this->useIn('long-gone.gr', 'registrant', status: DomainStatus::Deleted);

        $this->contact->update(['last_name' => 'Georgiou']);

        $this->assertSame('Georgiou', $this->contact->fresh()->last_name);
    }

    /**
     * Every domain order puts the account's primary contact (its billing name) on admin/tech/billing at once, as
     * pending_register: an unpaid order, never sent to a registrar, locks the account's name before anything holds it.
     */
    public function test_a_domain_never_registered_does_not_lock_the_contact(): void
    {
        $this->useIn('not-yet.gr', 'admin', status: DomainStatus::PendingRegister);

        $this->contact->update(['last_name' => 'Georgiou']);

        $this->assertSame('Georgiou', $this->contact->fresh()->last_name);
    }

    // ---- admin ------------------------------------------------------------------------------------------------------

    /** The client page's «Details» section is the primary contact (relationship): locked there too, and no 500. */
    public function test_holds_staff_on_the_client_page_cannot_rename_a_locked_primary_contact_and_get_no_500(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $client = $this->user->client();
        $primary = $client->ensurePrimaryContact();
        $primary->update(['first_name' => 'Owner', 'last_name' => 'Real']);
        $this->useIn('example.gr', 'admin', $primary);
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view', 'admin.clients.update'])->create();

        try {
            $this->livewireAs($staff)->test(EditClient::class, ['record' => $client->getRouteKey()])
                ->fillForm(['primaryContact.last_name' => 'Forged', 'primaryContact.city' => 'Patra'])
                ->call('save');
        } catch (DisplayException $e) {
            $this->fail('the admin client page threw the model\'s DisplayException (a 500): ' . $e->getMessage());
        }

        $this->assertSame('Real', $primary->fresh()->last_name);
    }

    public function test_holds_staff_relation_manager_edit_cannot_rename_a_locked_contact(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->useIn('example.gr', 'tech');
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view', 'admin.clients.update'])->create();

        try {
            $this->livewireAs($staff)->test(ContactsRelationManager::class, ['ownerRecord' => $this->user->client()->fresh(), 'pageClass' => EditClient::class])
                ->callTableAction('edit', $this->contact, data: ['first_name' => 'Forged', 'type' => 'business', 'city' => 'Patra']);
        } catch (DisplayException $e) {
            $this->fail('the relation manager threw the model\'s DisplayException (a 500): ' . $e->getMessage());
        }

        $this->assertSame(['Maria', 'individual'], [$this->contact->fresh()->first_name, $this->contact->fresh()->type]);
    }

    // ---- model ------------------------------------------------------------------------------------------------------

    public function test_holds_the_model_refuses_forcefill_and_case_only_changes(): void
    {
        $this->useIn('example.gr', 'billing');

        foreach ([['last_name' => 'PAPADOPOULOU'], ['type' => 'business'], ['company_name' => 'X IKE']] as $change) {
            try {
                $this->contact->fresh()->forceFill($change)->save();
                $this->fail('changed: ' . json_encode($change));
            } catch (DisplayException) {
            }
        }

        $this->assertSame(['Papadopoulou', 'individual', null],
            [$this->contact->fresh()->last_name, $this->contact->fresh()->type, $this->contact->fresh()->company_name]);
    }
}
