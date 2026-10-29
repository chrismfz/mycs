<?php

namespace Tests\Feature;

use App\Contracts\ChangesOwner;
use App\Jobs\Domains\SendDomainOwnerChangeJob;
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
use App\Services\Invoice\RecordPaymentService;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
    public function test_aud6_1_a_holder_transfer_whose_move_failed_is_not_left_silently_done(): void
    {
        Queue::fake();
        Storage::fake(DomainOwnerChanges::DISK);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->storeSettings(['default_currency' => 'EUR', 'domain_gr_owner_change_price' => '24.80']);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $seller = User::factory()->create()->clients()->firstOrFail();
        $buyer = User::factory()->create()->clients()->firstOrFail();
        $domain = Domain::factory()->create(['client_id' => $seller->id, 'sld' => 'pwlhsh', 'tld' => 'gr', 'fqdn' => 'pwlhsh.gr',
            'currency_code' => 'EUR', 'registrant_contact_id' => $seller->ensurePrimaryContact()->id, 'registrant_handle' => 'c60_old']);
        $other = Domain::factory()->create(['client_id' => $seller->id, 'sld' => 'allo', 'tld' => 'gr', 'fqdn' => 'allo.gr', 'currency_code' => 'EUR']);

        // The seller's open invoice for this domain and another one, paid in part: MoveToClient refuses to split it.
        $invoice = Invoice::query()->create(['client_id' => $seller->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => today()->addDays(7), 'currency_code' => 'EUR']);
        foreach ([$domain, $other] as $item) {
            $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $item->id, 'price' => '20.00', 'quantity' => 1,
                'description' => 'Charge ' . $item->fqdn]);
        }
        (new RecordPaymentService)->handle($invoice->refresh(), null, '5.00', transactionId: 'tx-part');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->refresh()->status);

        $mock = Mockery::mock(DomainManagementService::class, [app(DomainRegistrarFactory::class), app(DomainSyncService::class), app(DomainLifecycle::class)])
            ->makePartial();
        $mock->shouldReceive('changesOwner')->andReturn(true);
        $mock->shouldReceive('changeOwner')->andReturn(new DomainSyncResult(details: ['owner_change' => 'change of holder: done', 'owner_contact' => 'c60_new']));
        $this->app->instance(DomainManagementService::class, $mock);

        $holder = $buyer->ensurePrimaryContact();
        $declaration = UploadedFile::fake()->create('d.pdf', 20, 'application/pdf')->store(DomainOwnerChanges::DIRECTORY, DomainOwnerChanges::DISK);
        $change = app(DomainOwnerChanges::class)->request($domain, $holder, ChangesOwner::OWNER, $staff, $declaration, 'd.pdf', moveToHolder: true, sendNow: true);
        foreach (Queue::pushed(SendDomainOwnerChangeJob::class) as $job) {
            app()->call([$job, 'handle']);
        }

        $change->refresh();
        $domain->refresh();
        $this->assertSame($holder->id, (int) $domain->registrant_contact_id, 'the registry holder is the buyer now');
        $moved = (int) $domain->client_id === (int) $buyer->id;
        // What the .gr Registry page puts in front of staff (GrRegistry::getViewData).
        $staffSee = in_array($change->status, [DomainOwnerChange::STATUS_AWAITING_EETT, DomainOwnerChange::STATUS_UNKNOWN,
            DomainOwnerChange::STATUS_REFUSED, DomainOwnerChange::STATUS_SENDING, DomainOwnerChange::STATUS_DUE], true);
        $this->assertTrue($moved || $staffSee, "the buyer holds {$domain->fqdn} at the registry, the seller (#{$seller->id}) still has it here, "
            . "and the change is «{$change->status}» ({$change->result}): no one is told");
    }
}
