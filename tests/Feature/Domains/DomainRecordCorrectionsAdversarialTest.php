<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainNotFoundAtRegistrar;
use App\Services\Domains\DomainRecordCorrections;
use App\Services\Domains\Registrars\OpenproviderRegistrar;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of #403: correcting a domain's record, its renewal price by hand. */
class DomainRecordCorrectionsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private Domain $domain;

    private DomainTld $gr;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $this->actingAs($this->staff, 'staff');
        $this->gr = DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'sort' => 1]);
        $manual = DomainRegistrarConnection::query()->create(['registrar' => DomainRegistrarConnection::MANUAL, 'label' => 'By hand', 'is_active' => true, 'mode' => 'production']);
        $this->domain = Domain::factory()->create(['client_id' => User::factory()->create()->clients()->firstOrFail()->id, 'sld' => 'exmaple', 'tld' => 'gr',
            'fqdn' => 'exmaple.gr', 'domain_tld_id' => $this->gr->id, 'registrar_connection_id' => $manual->id]);
        $this->domain->nameservers()->createMany([['host' => 'ns1.old.gr', 'sort' => 0], ['host' => 'ns2.old.gr', 'sort' => 1]]);
    }

    /** ADV-1: renamed to a TLD we do not sell, it keeps the old TLD: renewed at .gr's price and by .gr's registrar. */
    public function test_renamed_to_a_tld_we_do_not_sell_it_is_no_longer_priced_as_the_old_tld(): void
    {
        app(DomainRecordCorrections::class)->rename($this->domain, 'example.com', $this->staff);

        $this->domain->refresh();
        $this->assertSame(['example', 'com'], [$this->domain->sld, $this->domain->tld]);
        $this->assertNotSame($this->gr->id, $this->domain->domain_tld_id, 'example.com still on the .gr TLD: priced, routed and staged as .gr');
    }

    /** ADV-2: a host under a TLD we sell is taken as a name on the TLD «example.gr». */
    public function test_a_subdomain_of_a_tld_we_sell_is_not_a_domain_name(): void
    {
        $this->expectException(DisplayException::class);
        try {
            app(DomainRecordCorrections::class)->rename($this->domain, 'www.example.gr', $this->staff);
        } finally {
            $this->domain->refresh();
            $this->assertSame('exmaple.gr', $this->domain->fqdn, 'renamed to sld «' . $this->domain->sld . '» on tld «' . $this->domain->tld . '»');
        }
    }

    /** ADV-3: a registration sent to the registry under the old name: our record renamed loses it (the sync reads the new one). */
    public function test_a_registration_already_sent_is_not_renamed(): void
    {
        $this->domain->forceFill(['status' => DomainStatus::PendingRegister, 'meta' => ['sent_at' => now()->toIso8601String()]])->saveQuietly();

        $this->expectException(DisplayException::class);
        try {
            app(DomainRecordCorrections::class)->rename($this->domain->refresh(), 'example.gr', $this->staff);
        } finally {
            $this->assertSame('exmaple.gr', $this->domain->refresh()->fqdn);
        }
    }

    /** ADV-4: renamed while a registrar write for it is under way (its lock held): moveToRegistrar waits for it, rename does not. */
    public function test_never_renamed_while_a_registrar_write_is_under_way(): void
    {
        $lock = Cache::lock("domain-registrar:{$this->domain->id}", 60);
        $this->assertTrue($lock->get());

        try {
            app(DomainRecordCorrections::class)->rename($this->domain, 'example.gr', $this->staff);
        } catch (DisplayException) {
        } finally {
            $lock->release();
        }
        $this->assertSame('exmaple.gr', $this->domain->refresh()->fqdn);
    }

    /** ADV-5: moved by hand to a registrar with an API, the form still sends its nameservers: «Not corrected» though it was. */
    public function test_moved_to_a_registrar_with_an_api_the_form_says_corrected(): void
    {
        config(['domains.registrars.fakeop' => NotThereRegistrar::class]);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'fakeop', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);

        Livewire::test(ViewDomain::class, ['record' => $this->domain->getRouteKey()])
            ->callAction('correctRecord', ['fqdn' => 'exmaple.gr', 'registrar_connection_id' => $op->id, 'nameservers' => "ns1.old.gr\nns2.old.gr"])
            ->assertHasNoActionErrors();

        $this->assertSame($op->id, $this->domain->refresh()->registrar_connection_id, 'moved');
        $this->assertSame($op->id, $this->domain->registrar_connection_id);
        Notification::assertNotNotified(__('Not corrected'));
        Notification::assertNotified(__('Corrected.'));
    }

    /** ADV-6: the price by hand through a float: the column's largest is not kept (amounts never float, CLAUDE.md). */
    public function test_the_renewal_price_by_hand_is_kept_to_the_cent(): void
    {
        Livewire::test(ViewDomain::class, ['record' => $this->domain->getRouteKey()])
            ->callAction('edit', ['auto_renew' => true, 'years' => 1, 'recurring_amount' => '999999999999999.99', 'notes' => '']);

        $this->assertContains((string) $this->domain->refresh()->recurring_amount, ['999999999999999.99', ''],
            'kept as typed, or refused; not rounded through a float to one the column cannot hold');
        $this->assertNotSame('1000000000000000.00', (string) $this->domain->recurring_amount);
    }

    /** ADV-6b: a price the column cannot hold (decimal 17,2) is refused, not a database error on MariaDB. */
    public function test_a_renewal_price_too_large_is_refused(): void
    {
        Livewire::test(ViewDomain::class, ['record' => $this->domain->getRouteKey()])
            ->callAction('edit', ['auto_renew' => true, 'years' => 1, 'recurring_amount' => '100000000000000000', 'notes' => ''])
            ->assertHasActionErrors(['recurring_amount']);
    }
}

/** A registrar with an API whose account does not have the domain. */
class NotThereRegistrar extends OpenproviderRegistrar
{
    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        throw new DomainNotFoundAtRegistrar("{$domain->fqdn} is not in the account.");
    }
}
