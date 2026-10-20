<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Livewire\Client\Contacts;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P1b-1 (owner 2026-10-20): who a contact is (type, name, company) stays as its registrars hold it while a domain uses
 * it in any role; its address, phone and email change. It shows where it is used; one in use is not archived.
 */
class ContactIdentityLockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $client = $this->user->client();
        $this->contact = $client->contacts()->create(['type' => 'individual', 'first_name' => 'Maria', 'last_name' => 'Papadopoulou',
            'email' => 'maria@example.test', 'phone' => '+30.2101234567', 'address1' => 'Odos 1', 'city' => 'Athina', 'postcode' => '10431', 'country' => 'GR']);
    }

    private function useIn(string $fqdn, string $role, DomainStatus $status = DomainStatus::Active): Domain
    {
        [$sld, $tld] = explode('.', $fqdn, 2);
        $domainTld = DomainTld::query()->firstOrCreate(['tld' => $tld], ['grace_days' => 15, 'redemption_days' => 0, 'is_active' => true]);

        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => $tld, 'fqdn' => $fqdn,
            'domain_tld_id' => $domainTld->id, 'status' => $status, "{$role}_contact_id" => $this->contact->id,
            "{$role}_handle" => 'H-' . strtoupper($sld)]); // held by its registrar: what locks (findings of #247)
    }

    public function test_a_contact_in_use_keeps_who_it_is_and_changes_its_details(): void
    {
        $this->useIn('example.gr', 'admin');

        try {
            $this->contact->update(['last_name' => 'Georgiou']);
            $this->fail('the name changed');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('example.gr', $e->getMessage());
        }
        $this->contact->refresh()->update(['address1' => 'Odos 2', 'phone' => '+30.2107654321', 'email' => 'new@example.test']);

        $this->assertSame(['Papadopoulou', 'Odos 2', 'new@example.test'], [$this->contact->fresh()->last_name, $this->contact->fresh()->address1, $this->contact->fresh()->email]);
    }

    public function test_a_contact_no_domain_uses_changes_freely(): void
    {
        $this->useIn('gone.gr', 'registrant', DomainStatus::TransferredAway);

        $this->contact->update(['first_name' => 'Marianna', 'company_name' => 'Example IKE', 'type' => 'business']);

        $this->assertSame('Marianna', $this->contact->fresh()->first_name);
    }

    public function test_the_portal_shows_where_it_is_used_and_locks_who_it_is(): void
    {
        $this->useIn('example.gr', 'registrant');
        $this->useIn('shop.gr', 'tech');
        $this->actingAs($this->user)->withSession($this->loginUser($this->user, [CurrentClient::SESSION_KEY => $this->user->client()->id]));

        $page = Livewire::test(Contacts::class)
            ->assertSee(__('domains.contact_used_by'))->assertSee('example.gr (' . __('domains.role.registrant') . ')')->assertSee('shop.gr (' . __('domains.role.tech') . ')')
            ->call('edit', $this->contact->id)
            ->assertSet('identityLocked', true)->assertSee(__('domains.contact_identity_locked'));

        // Sent anyway (a crafted request): not taken; the details are.
        $page->set('contact.last_name', 'Georgiou')->set('contact.address1', 'Odos 3')->call('save')->assertHasNoErrors();
        $this->assertSame(['Papadopoulou', 'Odos 3'], [$this->contact->fresh()->last_name, $this->contact->fresh()->address1]);
    }

    public function test_the_lock_cannot_be_lifted_from_the_browser(): void
    {
        $this->useIn('example.gr', 'registrant');
        $this->actingAs($this->user)->withSession($this->loginUser($this->user, [CurrentClient::SESSION_KEY => $this->user->client()->id]));

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Contacts::class)->call('edit', $this->contact->id)->set('identityLocked', false);
    }

    public function test_a_contact_in_use_is_not_archived(): void
    {
        $this->useIn('example.gr', 'billing');

        $this->expectException(DisplayException::class);
        $this->contact->archive();
    }
}
