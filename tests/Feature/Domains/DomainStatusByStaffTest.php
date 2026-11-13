<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainRecordCorrections;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/**
 * Staff set a domain's status in its «Domain» card (owner 2026-11-13, as WHMCS's select, kept to three): a live one
 * cancelled or transferred away, an ended one back. Our record only; the daily cron cancels its open renewal invoices.
 */
class DomainStatusByStaffTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private Domain $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $this->actingAs($this->staff, 'staff');
        DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'sort' => 1]);
        $manual = DomainRegistrarConnection::query()->create(['registrar' => DomainRegistrarConnection::MANUAL, 'label' => 'By hand', 'is_active' => true, 'mode' => 'production']);
        $this->domain = Domain::factory()->create(['client_id' => User::factory()->create()->clients()->firstOrFail()->id, 'sld' => 'example', 'tld' => 'gr',
            'fqdn' => 'example.gr', 'registrar_connection_id' => $manual->id]);
    }

    /** Its renewal, open. */
    private function openRenewal(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->domain->client_id, 'currency_code' => 'USD']);
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => '19.00', 'description' => 'example.gr', 'kind' => InvoiceItem::KIND_RENEWAL,
            'reference_type' => Domain::class, 'reference_id' => $this->domain->id]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_UNPAID]);

        return $invoice->fresh();
    }

    private function statusLocked($page): bool
    {
        return collect($page->instance()->getSchema('recordForm')->getFlatFields(withHidden: true))->first(fn ($field) => $field->getName() === 'status')->isDisabled();
    }

    private function page()
    {
        return Livewire::test(ViewDomain::class, ['record' => $this->domain->getRouteKey()]);
    }

    public function test_only_three_statuses_by_hand_each_from_where_it_is(): void
    {
        $choices = fn (DomainStatus $status) => DomainRecordCorrections::statusChoices(new Domain(['status' => $status]));

        foreach ([DomainStatus::Active, DomainStatus::Expired, DomainStatus::Grace, DomainStatus::Redemption] as $live) {
            $this->assertSame([DomainStatus::Cancelled, DomainStatus::TransferredAway], $choices($live), $live->value);
        }
        $this->assertSame([], $choices(DomainStatus::Cancelled), 'ended elsewhere');
        $this->assertSame([DomainStatus::Active], DomainRecordCorrections::statusChoices(new Domain(['status' => DomainStatus::Cancelled, 'meta' => ['cancelled_by_staff' => ['from' => 'active']]])));
        $this->assertSame([DomainStatus::Active], $choices(DomainStatus::TransferredAway));
        // The registry's and an order's: never by hand.
        foreach ([DomainStatus::Deleted, DomainStatus::PendingRegister, DomainStatus::PendingTransfer] as $fixed) {
            $this->assertSame([], $choices($fixed), $fixed->value);
        }
    }

    public function test_cancelled_from_the_card_ends_it_and_tonights_cron_cancels_its_open_renewal(): void
    {
        $invoice = $this->openRenewal();

        $this->page()->saveRecord(['status' => DomainStatus::Cancelled->value])->assertHasNoFormErrors([], 'recordForm')
            ->assertSet('billing.auto_renew', false); // the billing card shows auto-renew off

        $domain = $this->domain->fresh();
        $this->assertSame([DomainStatus::Cancelled, false], [$domain->status, $domain->auto_renew]);
        $this->assertSame(['active', true, [$invoice->id], $this->staff->id], [$domain->meta['cancelled_by_staff']['from'] ?? null,
            $domain->meta['cancelled_by_staff']['auto_renew'] ?? null, $domain->meta['cancelled_by_staff']['open_invoices'] ?? null,
            $domain->meta['cancelled_by_staff']['staff_id'] ?? null]);
        $audit = Audit::query()->where('event', 'record_status')->sole();
        $this->assertSame(['active', 'cancelled', $this->staff->id], [$audit->old_values['status'], $audit->new_values['status'], $audit->new_values['staff_id']]);

        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status, 'the same night');
    }

    public function test_transferred_away_from_the_card_waits_a_day_before_its_invoice(): void
    {
        $invoice = $this->openRenewal();

        $this->page()->saveRecord(['status' => DomainStatus::TransferredAway->value])->assertHasNoFormErrors([], 'recordForm');

        $domain = $this->domain->fresh();
        $this->assertSame([DomainStatus::TransferredAway, false, 'staff'], [$domain->status, $domain->auto_renew, $domain->meta['transferred_away']['by'] ?? null]);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'not the same day: time to undo a mistake');
    }

    public function test_back_to_active_as_it_was_its_cancelled_invoice_stays_cancelled(): void
    {
        $invoice = $this->openRenewal();
        $this->domain->forceFill(['status' => DomainStatus::Redemption, 'expires_at' => now()->subDays(40)])->save();
        app(DomainRecordCorrections::class)->setStatus($this->domain, DomainStatus::Cancelled, $this->staff);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);

        $this->page()->saveRecord(['status' => DomainStatus::Active->value])->assertHasNoFormErrors([], 'recordForm');

        $domain = $this->domain->fresh();
        // Its own status before (its dates still say so), its auto-renew; staff's note of the end gone, the resume kept.
        $this->assertNotContains($domain->status, [DomainStatus::Cancelled, DomainStatus::TransferredAway]);
        $this->assertTrue($domain->auto_renew);
        $this->assertArrayNotHasKey('cancelled_by_staff', $domain->meta);
        $this->assertSame($this->staff->id, $domain->meta['resumed']['staff_id'] ?? null);
        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status, 'never reopened by itself');
    }

    public function test_one_ended_elsewhere_is_not_brought_back(): void
    {
        // Cancelled by the import or an order: nothing says it exists at a registrar (ADV-848-1, 2).
        $this->domain->forceFill(['status' => DomainStatus::Cancelled, 'auto_renew' => true])->save();

        $this->assertSame([], DomainRecordCorrections::statusChoices($this->domain));
        $this->expectException(DisplayException::class);
        app(DomainRecordCorrections::class)->setStatus($this->domain, DomainStatus::Active, $this->staff);
    }

    public function test_a_status_not_offered_is_refused_and_the_select_is_locked_where_none_is(): void
    {
        $this->domain->forceFill(['status' => DomainStatus::Expired])->save();
        try {
            app(DomainRecordCorrections::class)->setStatus($this->domain, DomainStatus::Active, $this->staff);
            $this->fail('expired → active by hand');
        } catch (DisplayException) {
        }
        $this->assertSame(DomainStatus::Expired, $this->domain->fresh()->status);

        $this->domain->forceFill(['status' => DomainStatus::Deleted])->save();
        $page = $this->page();
        $this->assertTrue($this->statusLocked($page));
        $page->saveRecord(['status' => DomainStatus::Active->value]);
        $this->assertSame(DomainStatus::Deleted, $this->domain->fresh()->status, 'the registry\'s, never by hand');
        $this->assertSame(0, Audit::query()->where('event', 'record_status')->count());
    }

    public function test_staff_without_the_permission_see_it_and_change_nothing(): void
    {
        $viewer = Staff::factory()->create(['role_id' => Role::create(['name' => 'View', 'permissions' => ['admin.domains.viewAny']])->id]);
        $this->actingAs($viewer, 'staff');

        $page = $this->page()->assertActionHidden('nameserversByHand');
        $this->assertTrue($page->instance()->getSchema('recordForm')->isDisabled());
        $this->assertFalse((bool) $viewer->hasPermission('admin.domains.update'));
        $this->assertSame(DomainStatus::Active, $this->domain->fresh()->status);
    }

    /** R848-3: its status moved meanwhile (the cron, a sync): the page's stale status is no error, its name still saves. */
    public function test_a_status_changed_meanwhile_does_not_block_its_name(): void
    {
        $page = $this->page();
        $this->domain->forceFill(['status' => DomainStatus::Expired])->save();

        $page->saveRecord(['fqdn' => 'example2.gr'])->assertHasNoFormErrors([], 'recordForm');

        $this->assertSame(['example2.gr', DomainStatus::Expired], [$this->domain->fresh()->fqdn, $this->domain->fresh()->status]);
    }
}
