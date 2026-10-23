<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Contracts\ChangesOwner;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Jobs\Domains\SendDomainOwnerChangeJob;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainOwnerChange;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainOwnerChanges;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\RegistrarOutcomeUnknown;
use App\Services\Domains\RegistrarRefused;
use App\Services\Invoice\RecordPaymentService;
use App\Support\Domains\DomainSyncResult;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A .gr change of holder (G8-5b, owner 2026-10-23): staff ask it, one price on an invoice of its own, sent to the registry
 * once paid (or at once without an invoice), never twice; a refusal or a lost answer goes back to staff.
 */
class DomainOwnerChangeFlowTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private Client $client;

    private Domain $domain;

    private Contact $renamed;

    /** @var list<array{0: int, 1: string}> what the registry was asked: [contact id, kind] */
    private array $asked = [];

    /** null: done; 'awaiting': 1001; 'refuse'; 'lost' */
    private ?string $mode = null;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake(DomainOwnerChanges::DISK);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->storeSettings(['default_currency' => 'EUR', 'domain_gr_owner_change_price' => '24.80']);
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $owner = User::factory()->create();
        $this->client = $owner->clients()->firstOrFail();
        $holder = $this->client->ensurePrimaryContact();
        $this->renamed = $this->client->contacts()->create(['first_name' => 'Maria', 'last_name' => 'Pappa', 'company_name' => 'Pappa IKE',
            'email' => 'maria@example.test', 'country' => 'GR']);
        $this->domain = Domain::factory()->create(['client_id' => $this->client->id, 'sld' => 'pappa', 'tld' => 'gr', 'fqdn' => 'pappa.gr',
            'currency_code' => 'EUR', 'registrant_contact_id' => $holder->id, 'registrant_handle' => 'c60_old']);
        // The registry's side is GreppOwnerChangeTest's: here what we ask of it, and when. The rest of the service as it is.
        $mock = Mockery::mock(DomainManagementService::class, [app(DomainRegistrarFactory::class), app(DomainSyncService::class), app(DomainLifecycle::class)])
            ->makePartial();
        $this->app->instance(DomainManagementService::class, $mock);
        (function (MockInterface $mock) {
            $mock->shouldReceive('changesOwner')->andReturn(true);
            $mock->shouldReceive('changeOwner')->andReturnUsing(function (Domain $domain, Contact $holder, string $kind) {
                $this->asked[] = [$holder->id, $kind];

                return match ($this->mode) {
                    'refuse' => throw new RegistrarRefused('2305 Object association prohibits operation'),
                    'lost' => throw new RegistrarOutcomeUnknown('no answer'),
                    'awaiting' => new DomainSyncResult(details: ['owner_change' => 'change of holder: awaiting the EETT', 'owner_contact' => 'c60_mow1']),
                    default => new DomainSyncResult(details: ['owner_change' => 'name change: done', 'owner_contact' => 'c60_mow1']),
                };
            });
        })($mock);
    }

    private function declaration(): string
    {
        return UploadedFile::fake()->create('declaration.pdf', 20, 'application/pdf')->store(DomainOwnerChanges::DIRECTORY, DomainOwnerChanges::DISK);
    }

    private function sendJobs(): void
    {
        foreach (Queue::pushed(SendDomainOwnerChangeJob::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    public function test_a_name_change_is_invoiced_at_its_price_and_sent_once_when_paid(): void
    {
        $change = app(DomainOwnerChanges::class)->request($this->domain, $this->renamed, ChangesOwner::NAME, $this->staff, null, null, false, false);

        $this->assertSame(DomainOwnerChange::STATUS_AWAITING_PAYMENT, $change->status);
        $line = $change->line()->firstOrFail();
        $this->assertSame($this->client->id, $line->invoice->client_id);
        $this->assertSame('24.80', number_format((float) $line->invoice->total, 2, '.', ''), 'the price as entered, with its tax');
        Queue::assertNotPushed(SendDomainOwnerChangeJob::class);
        $this->assertSame([], $this->asked, 'never before it is paid');

        $invoice = $line->invoice;
        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-oc');
        $this->sendJobs();
        $this->sendJobs(); // again: claimed once

        $this->assertSame([[$this->renamed->id, ChangesOwner::NAME]], $this->asked);
        $change->refresh();
        $this->assertSame(DomainOwnerChange::STATUS_DONE, $change->status);
        $this->assertNotNull($change->line->applied_at);
        $this->domain->refresh();
        $this->assertSame([$this->renamed->id, 'c60_mow1'], [$this->domain->registrant_contact_id, $this->domain->registrant_handle]);
    }

    public function test_a_transfer_needs_its_declaration_and_moves_to_the_new_holders_account_when_asked(): void
    {
        $other = User::factory()->create()->clients()->firstOrFail();
        $buyer = $other->ensurePrimaryContact();

        try {
            app(DomainOwnerChanges::class)->request($this->domain, $buyer, ChangesOwner::OWNER, $this->staff, null, null, true, true);
            $this->fail('a transfer without its declaration');
        } catch (DisplayException $e) {
            $this->assertSame(__('domains.owner_change.declaration_needed'), $e->getMessage());
        }

        $change = app(DomainOwnerChanges::class)->request($this->domain, $buyer, ChangesOwner::OWNER, $this->staff, $this->declaration(), 'declaration.pdf', true, true);
        $this->assertNull($change->line()->first(), 'sent now: no invoice');
        $this->sendJobs();

        $this->assertSame(DomainOwnerChange::STATUS_DONE, $change->refresh()->status);
        $this->domain->refresh();
        $this->assertSame($other->id, $this->domain->client_id, 'moved to the new holder\'s account');
        $this->assertSame($buyer->id, $this->domain->registrant_contact_id);
    }

    public function test_a_name_change_is_to_a_contact_of_the_same_account_and_one_change_at_a_time(): void
    {
        $stranger = User::factory()->create()->clients()->firstOrFail()->ensurePrimaryContact();
        $this->expectExceptionMessage(__('domains.owner_change.name_same_account'));
        try {
            app(DomainOwnerChanges::class)->request($this->domain, $stranger, ChangesOwner::NAME, $this->staff, null, null, false, false);
        } finally {
            app(DomainOwnerChanges::class)->request($this->domain, $this->renamed, ChangesOwner::NAME, $this->staff, null, null, false, false);
            try {
                app(DomainOwnerChanges::class)->request($this->domain, $this->renamed, ChangesOwner::NAME, $this->staff, null, null, false, true);
                $this->fail('a second one open');
            } catch (DisplayException $e) {
                $this->assertSame(__('domains.owner_change.one_open', ['domain' => 'pappa.gr']), $e->getMessage());
            }
        }
    }

    public function test_a_refusal_goes_to_staff_who_may_send_it_again(): void
    {
        $this->mode = 'refuse';
        $change = app(DomainOwnerChanges::class)->request($this->domain, $this->renamed, ChangesOwner::NAME, $this->staff, null, null, false, true);
        $this->sendJobs();
        $this->assertSame(DomainOwnerChange::STATUS_REFUSED, $change->refresh()->status);
        $this->assertStringContainsString('2305', (string) $change->result);

        $this->mode = null;
        app(DomainOwnerChanges::class)->retry($change);
        $this->assertSame(DomainOwnerChange::STATUS_DONE, $change->refresh()->status);
        $this->assertCount(2, $this->asked);
    }

    public function test_a_lost_answer_is_never_sent_again_by_itself(): void
    {
        $this->mode = 'lost';
        $change = app(DomainOwnerChanges::class)->request($this->domain, $this->renamed, ChangesOwner::NAME, $this->staff, null, null, false, true);
        $this->sendJobs();
        $this->assertSame(DomainOwnerChange::STATUS_UNKNOWN, $change->refresh()->status);

        $this->artisan('app:cron-job')->run();
        app(DomainOwnerChanges::class)->send($change->id);
        $this->assertCount(1, $this->asked, 'maybe done and charged: never asked twice');

        try {
            app(DomainOwnerChanges::class)->retry($change);
            $this->fail('no answer is not a refusal');
        } catch (DisplayException) {
        }
        app(DomainOwnerChanges::class)->doneThere($change);
        $this->assertSame(DomainOwnerChange::STATUS_DONE, $change->refresh()->status);
    }

    public function test_awaiting_the_eett_the_holder_stays_until_staff_say_it_was_approved(): void
    {
        $this->mode = 'awaiting';
        $change = app(DomainOwnerChanges::class)->request($this->domain, $this->renamed, ChangesOwner::NAME, $this->staff, null, null, false, true);
        $this->sendJobs();

        $this->assertSame(DomainOwnerChange::STATUS_AWAITING_EETT, $change->refresh()->status);
        $this->assertSame('c60_old', $this->domain->refresh()->registrant_handle, 'the registry still holds the old one');

        app(DomainOwnerChanges::class)->approved($change);
        $this->domain->refresh();
        $this->assertSame([$this->renamed->id, 'c60_mow1'], [$this->domain->registrant_contact_id, $this->domain->registrant_handle]);
    }

    public function test_cancelled_before_it_is_paid_its_invoice_goes_with_it(): void
    {
        $change = app(DomainOwnerChanges::class)->request($this->domain, $this->renamed, ChangesOwner::NAME, $this->staff, null, null, false, false);
        app(DomainOwnerChanges::class)->cancel($change, 'the customer changed their mind');

        $this->assertSame(DomainOwnerChange::STATUS_CANCELLED, $change->refresh()->status);
        $this->assertSame(Invoice::STATUS_CANCELLED, $change->line->invoice->refresh()->status);
        $this->assertSame([], $this->asked);
    }

    public function test_no_price_set_only_without_an_invoice_and_not_for_a_domain_that_ended(): void
    {
        $this->storeSettings(['domain_gr_owner_change_price' => '0']);
        try {
            app(DomainOwnerChanges::class)->request($this->domain, $this->renamed, ChangesOwner::NAME, $this->staff, null, null, false, false);
            $this->fail('no price');
        } catch (DisplayException $e) {
            $this->assertSame(__('domains.owner_change.no_price'), $e->getMessage());
        }

        $this->domain->forceFill(['status' => DomainStatus::TransferredAway])->save();
        $this->expectExceptionMessage(__('domains.owner_change.not_now', ['domain' => 'pappa.gr']));
        app(DomainOwnerChanges::class)->request($this->domain, $this->renamed, ChangesOwner::NAME, $this->staff, null, null, false, true);
    }

    public function test_staff_ask_it_from_the_domains_page_typing_its_name(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->actingAs($this->staff, 'staff');
        $page = Livewire::test(ViewDomain::class, ['record' => $this->domain->getRouteKey()])->assertActionVisible('ownerChange');

        $page->callAction('ownerChange', ['kind' => ChangesOwner::NAME, 'contact_id' => $this->renamed->id, 'send_now' => false, 'confirm' => 'nope.gr'])
            ->assertHasActionErrors(['confirm']);
        $this->assertSame(0, DomainOwnerChange::query()->count());

        Livewire::test(ViewDomain::class, ['record' => $this->domain->getRouteKey()])
            ->callAction('ownerChange', ['kind' => ChangesOwner::NAME, 'contact_id' => $this->renamed->id, 'send_now' => false, 'confirm' => 'PAPPA.gr'])
            ->assertHasNoActionErrors();
        $this->assertSame(DomainOwnerChange::STATUS_AWAITING_PAYMENT, DomainOwnerChange::query()->sole()->status);

        $this->staff->role->update(['permissions' => ['admin.domains.viewAny', 'admin.domains.manage']]);
        Livewire::test(ViewDomain::class, ['record' => $this->domain->getRouteKey()])->assertActionHidden('ownerChange')->assertActionHidden('ownerChangeCancel');
    }
}
