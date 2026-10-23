<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Contracts\ChangesOwner;
use App\Exceptions\DisplayException;
use App\Jobs\Domains\SendDomainOwnerChangeJob;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainOwnerChange;
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
 * Adversarial review of #393 (G8-5b): each test fails on the PR as pushed.
 */
class DomainOwnerChangeAdversarialTest extends TestCase
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

    /** ADV-1: «Send again» checks the status it read, then writes due unconditionally: a second click (or a second
     * staff member) holding the refused change it read sends it to the registry again after it was done. */
    public function test_a_stale_retry_never_asks_the_registry_again_once_done(): void
    {
        $this->mode = 'refuse';
        $change = app(DomainOwnerChanges::class)->request($this->domain, $this->renamed, ChangesOwner::NAME, $this->staff, null, null, false, true);
        $this->sendJobs();
        $this->assertSame(DomainOwnerChange::STATUS_REFUSED, $change->refresh()->status);
        $stale = DomainOwnerChange::query()->findOrFail($change->id); // the second request's read, still refused

        $this->mode = null;
        app(DomainOwnerChanges::class)->retry($change);
        $this->assertSame(DomainOwnerChange::STATUS_DONE, $change->refresh()->status);

        try {
            app(DomainOwnerChanges::class)->retry($stale);
        } catch (DisplayException) {
        }
        $this->assertCount(2, $this->asked, 'done at the registry (and charged): never asked a third time');
        $this->assertSame(DomainOwnerChange::STATUS_DONE, $change->refresh()->status);
    }

    /** ADV-2: «Cancel» checks the status it read, then writes cancelled unconditionally: over a change the job sent (and
     * the registry did) meanwhile, which then reads «cancelled», frees the domain for a second change, and its money
     * looks refundable. */
    public function test_a_stale_cancel_never_overwrites_a_change_sent_meanwhile(): void
    {
        $change = app(DomainOwnerChanges::class)->request($this->domain, $this->renamed, ChangesOwner::NAME, $this->staff, null, null, false, true);
        $stale = DomainOwnerChange::query()->findOrFail($change->id); // read «due» by the cancel's request
        $this->sendJobs();
        $this->assertSame(DomainOwnerChange::STATUS_DONE, $change->refresh()->status);

        try {
            app(DomainOwnerChanges::class)->cancel($stale, 'changed their mind');
        } catch (DisplayException) {
        }
        $this->assertSame(DomainOwnerChange::STATUS_DONE, $change->refresh()->status, 'done at the registry: never «cancelled» here');
        $this->assertNotNull($change->done_at);
    }

    /** ADV-3: a send whose process dies after the claim (the job's timeout of 240s is shorter than the registrar lock's
     * 600s and a .gr change makes many EPP requests; a deploy; complete() throwing) stays «sending» for good: open, so no
     * new change for the domain, and no action at all for staff (no «done there», no cancel), and the cron skips it. */
    public function test_a_change_left_sending_is_not_stuck_for_good(): void
    {
        $change = app(DomainOwnerChanges::class)->request($this->domain, $this->renamed, ChangesOwner::NAME, $this->staff, null, null, false, true);
        DomainOwnerChange::query()->whereKey($change->id)->update(['status' => DomainOwnerChange::STATUS_SENDING,
            'sent_at' => now()->subHours(3), 'updated_at' => now()->subHours(3)]);

        $this->artisan('app:cron-job')->run();
        $this->assertSame([], $this->asked, 'maybe done: never asked again');
        if ($change->refresh()->status === DomainOwnerChange::STATUS_UNKNOWN) {
            return; // the cron handed it to staff: fine
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->actingAs($this->staff, 'staff');
        $page = Livewire::test(ViewDomain::class, ['record' => $this->domain->getRouteKey()]);
        $visible = collect(['ownerChangeDoneThere', 'ownerChangeCancel', 'ownerChangeRetry', 'ownerChangeApproved', 'ownerChange'])
            ->filter(function (string $action) use ($page) {
                try {
                    $page->assertActionVisible($action);

                    return true;
                } catch (\Throwable) {
                    return false;
                }
            });
        $this->assertNotEmpty($visible->all(), 'sending for 3 hours: staff need a way out (look at the registry, then done/cancel)');
    }

    /** ADV-4: a transfer that moves the domain to the buyer's account moves it first (MoveToClient copies every role's
     * contact, the seller's own holder contact included) and only then sets the new holder: the buyer's account keeps a
     * copy of the seller's personal details, shown to the buyer in their client area. */
    public function test_a_transfer_moved_to_the_buyer_leaves_no_copy_of_the_sellers_details_there(): void
    {
        $seller = $this->domain->registrantContact;
        $other = User::factory()->create()->clients()->firstOrFail();
        $buyer = $other->ensurePrimaryContact();
        $change = app(DomainOwnerChanges::class)->request($this->domain, $buyer, ChangesOwner::OWNER, $this->staff, $this->declaration(), 'declaration.pdf', true, true);
        $this->sendJobs();
        $this->assertSame(DomainOwnerChange::STATUS_DONE, $change->refresh()->status);
        $this->assertSame($other->id, $this->domain->refresh()->client_id);

        $copies = Contact::query()->where('client_id', $other->id)->where('email', $seller->email)->whereKeyNot($buyer->id)->count();
        $this->assertSame(0, $copies, 'the seller\'s contact is never copied into the buyer\'s account');
    }
}
