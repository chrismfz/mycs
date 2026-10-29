<?php

namespace Tests\Feature;

use App\Contracts\ChangesOwner;
use App\Exceptions\DisplayException;
use App\Jobs\Domains\SendDomainOwnerChangeJob;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainOwnerChange;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainLifecycle;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainOwnerChanges;
use App\Services\Domains\DomainRegistrarFactory;
use App\Services\Domains\DomainSyncService;
use App\Services\Invoice\RecordPaymentService;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Pre-launch audit, flow 6 (domains and registrars), round 1: failing tests of what the round found.
 */
class AuditFlow6Test extends TestCase
{
    use RefreshDatabase;

    /**
     * AUD6-1 (Medium): a .gr transfer of holder «and move it to the new holder's account» is done at the registry, but the
     * move refuses (the seller has an unpaid invoice with a payment on it that bills this domain and another item). The
     * change ends «done», the domain stays on the seller's account (its portal: EPP code, nameservers, contacts) with the
     * buyer's contact as its holder, and nothing puts it in front of staff: the .gr Registry page lists only open changes,
     * no mail is sent. Either the domain is the buyer's, or the change is left where staff look.
     */
    /** The seller, the buyer, the domain, the mocked registry (done at once, or awaiting the EETT), the declaration. */
    private function scene(string $answer = 'change of holder: done'): array
    {
        Queue::fake();
        Mail::fake();
        Storage::fake(DomainOwnerChanges::DISK);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->storeSettings(['default_currency' => 'EUR', 'domain_gr_owner_change_price' => '24.80', 'system_email_address' => 'staff@example.test',
            'mail_disable' => false]);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $seller = User::factory()->create()->clients()->firstOrFail();
        $buyer = User::factory()->create()->clients()->firstOrFail();
        $domain = Domain::factory()->create(['client_id' => $seller->id, 'sld' => 'pwlhsh', 'tld' => 'gr', 'fqdn' => 'pwlhsh.gr',
            'currency_code' => 'EUR', 'registrant_contact_id' => $seller->ensurePrimaryContact()->id, 'registrant_handle' => 'c60_old']);
        $mock = Mockery::mock(DomainManagementService::class, [app(DomainRegistrarFactory::class), app(DomainSyncService::class), app(DomainLifecycle::class)])
            ->makePartial();
        $mock->shouldReceive('changesOwner')->andReturn(true);
        $mock->shouldReceive('changeOwner')->andReturn(new DomainSyncResult(details: ['owner_change' => $answer, 'owner_contact' => 'c60_new']));
        $this->app->instance(DomainManagementService::class, $mock);
        $declaration = UploadedFile::fake()->create('d.pdf', 20, 'application/pdf')->store(DomainOwnerChanges::DIRECTORY, DomainOwnerChanges::DISK);

        return [$staff, $seller, $buyer, $domain, $buyer->ensurePrimaryContact(), $declaration, $mock];
    }

    /** The seller's open invoice for this domain and another, paid in part: MoveToClient refuses to split it. */
    private function blockTheMove($seller, Domain $domain): void
    {
        $other = Domain::factory()->create(['client_id' => $seller->id, 'sld' => 'allo', 'tld' => 'gr', 'fqdn' => 'allo.gr', 'currency_code' => 'EUR']);
        $invoice = Invoice::query()->create(['client_id' => $seller->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => today()->addDays(7), 'currency_code' => 'EUR']);
        foreach ([$domain, $other] as $item) {
            $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $item->id, 'price' => '20.00', 'quantity' => 1,
                'description' => 'Charge ' . $item->fqdn]);
        }
        (new RecordPaymentService)->handle($invoice->refresh(), null, '5.00', transactionId: 'tx-part');
    }

    private function sendQueued(): void
    {
        foreach (Queue::pushed(SendDomainOwnerChangeJob::class) as $job) {
            app()->call([$job, 'handle']);
        }
    }

    /**
     * AUD6-1 (Medium): a .gr transfer of holder «and move it to the new holder's account» was done at the registry while the
     * move refused (the seller has an unpaid invoice with a payment on it that bills this domain and another item): the
     * change ended «done», the domain on the seller's account (its portal: EPP code, nameservers, contacts) with the
     * buyer's contact as its holder, and nothing put it in front of staff. Fixed: the move is checked first.
     */
    public function test_aud6_1_a_transfer_whose_move_would_be_refused_is_refused_when_asked(): void
    {
        [$staff, $seller, , $domain, $holder, $declaration, $mock] = $this->scene();
        $this->blockTheMove($seller, $domain);

        try {
            app(DomainOwnerChanges::class)->request($domain, $holder, ChangesOwner::OWNER, $staff, $declaration, 'd.pdf', moveToHolder: true, sendNow: true);
            $this->fail('asked with a move that is refused');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('cannot move', $e->getMessage());
        }

        $this->assertSame(0, DomainOwnerChange::query()->count());
        $this->assertSame((int) $seller->ensurePrimaryContact()->id, (int) $domain->fresh()->registrant_contact_id);
        $mock->shouldNotHaveReceived('changeOwner');
    }

    /** Asked while it could move; by the time it is sent the move is refused: not sent, refused for staff to see. */
    public function test_aud6_1_a_transfer_whose_move_is_refused_by_then_is_not_sent(): void
    {
        [$staff, $seller, , $domain, $holder, $declaration, $mock] = $this->scene();
        $change = app(DomainOwnerChanges::class)->request($domain, $holder, ChangesOwner::OWNER, $staff, $declaration, 'd.pdf', moveToHolder: true, sendNow: true);
        $this->blockTheMove($seller, $domain);

        $this->sendQueued();

        $change->refresh();
        $this->assertSame(DomainOwnerChange::STATUS_REFUSED, $change->status, (string) $change->result);
        $this->assertStringContainsString('Not sent to the registry', (string) $change->result);
        $this->assertSame((int) $seller->ensurePrimaryContact()->id, (int) $domain->fresh()->registrant_contact_id, 'the holder unchanged');
        $mock->shouldNotHaveReceived('changeOwner');
    }

    /** Awaiting the EETT when sent; the move is refused by the time staff mark it approved: done there, staff mailed. */
    public function test_aud6_1_a_move_refused_after_the_registry_did_it_is_mailed_to_staff(): void
    {
        [$staff, $seller, , $domain, $holder, $declaration] = $this->scene('change of holder: awaiting the EETT');
        $change = app(DomainOwnerChanges::class)->request($domain, $holder, ChangesOwner::OWNER, $staff, $declaration, 'd.pdf', moveToHolder: true, sendNow: true);
        $this->sendQueued();
        $this->assertSame(DomainOwnerChange::STATUS_AWAITING_EETT, $change->fresh()->status);
        $this->blockTheMove($seller, $domain);

        app(DomainOwnerChanges::class)->approved($change->fresh());

        $this->assertSame(DomainOwnerChange::STATUS_DONE, $change->fresh()->status);
        $this->assertSame((int) $seller->id, (int) $domain->fresh()->client_id, 'not moved');
        $this->assertTrue(EmailLog::query()->where('subject', 'Domain holder changed, domain not moved')->exists(), 'staff not told');
    }
}
