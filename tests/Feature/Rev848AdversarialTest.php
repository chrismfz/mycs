<?php

namespace Tests\Feature;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainRecordCorrections;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #848 (a domain's status set by staff from its «Domain» card). Each test_adv_848_N_ fails on
 * the PR's head and shows one finding; test_sound_ ones show behavior that held.
 */
class Rev848AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    private DomainRegistrarConnection $manual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->boot();
        $this->staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $this->actingAs($this->staff, 'staff');
        DomainTld::query()->create(['tld' => 'gr', 'is_active' => true, 'sort' => 1]);
        $this->manual = DomainRegistrarConnection::query()->create(['registrar' => DomainRegistrarConnection::MANUAL, 'label' => 'By hand', 'is_active' => true, 'mode' => 'production']);
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => User::factory()->create()->clients()->firstOrFail()->id, 'sld' => 'example', 'tld' => 'gr',
            'fqdn' => 'example.gr', 'registrar_connection_id' => $this->manual->id, 'recurring_amount' => '19.00']);
    }

    /** @return list<int> open invoices billing the domain's renewal */
    private function openRenewals(Domain $domain): array
    {
        return Invoice::query()->whereIn('status', Invoice::OPEN_STATUSES)
            ->whereHas('items', fn ($line) => $line->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind'))
            ->pluck('id')->all();
    }

    /**
     * ADV-848-1: a .gr registration recalled (the registry cancelled it, its payment for staff to refund) comes back
     * «Active» from the card: shown active to its customer and holding its name (live_fqdn) though the registry released
     * it. Before #848 nothing could bring it back. Expected: refused (only one staff ended here, or transferred away).
     */
    public function test_adv_848_1_a_recalled_registration_is_not_made_active_again(): void
    {
        $domain = $this->domain(['status' => DomainStatus::Cancelled, 'auto_renew' => false, 'registered_at' => now()->subDays(2)->toDateString(),
            'meta' => ['recalled_at' => now()->subDay()->toIso8601String()]]);

        $refused = false;
        try {
            app(DomainRecordCorrections::class)->setStatus($domain, DomainStatus::Active, $this->staff);
        } catch (DisplayException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'recalled at the registry: never «Active» here again');
        $this->assertSame(DomainStatus::Cancelled, $domain->fresh()->status);
        $this->assertNull($domain->fresh()->live_fqdn, 'nor holding a name the registry released');
    }

    /**
     * ADV-848-2: an order never registered (the cron's unpaid_domain_orders or Cancel Order set it cancelled; never sent,
     * no expiry) comes back «Active»: a domain that does not exist at any registrar, active in the client area, holding
     * its name so another customer's paid order is refused (D-25). Expected: refused.
     */
    public function test_adv_848_2_an_order_never_registered_is_not_made_active(): void
    {
        $domain = $this->domain(['status' => DomainStatus::Cancelled, 'auto_renew' => false, 'registered_at' => null, 'expires_at' => null]);

        $refused = false;
        try {
            app(DomainRecordCorrections::class)->setStatus($domain, DomainStatus::Active, $this->staff);
        } catch (DisplayException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'never registered: nothing to make active');
        $this->assertNotSame(DomainStatus::Active, $domain->fresh()->status);
    }

    /**
     * ADV-848-3: back to «Active» while another row holds the name (re-ordered by someone else after it was cancelled,
     * or the row closed by DomainNameHold «name taken»): the unique live_fqdn refuses it in the database, and the
     * UniqueConstraintViolationException escapes setStatus (saveRecord catches only DisplayException): a 500 on the page,
     * not «Not corrected». Expected: a DisplayException that names the holder.
     */
    public function test_adv_848_3_back_to_active_while_another_row_holds_the_name_is_refused_with_a_message(): void
    {
        $ended = $this->domain(['status' => DomainStatus::Cancelled, 'auto_renew' => false,
            'meta' => ['cancelled_by_staff' => ['at' => now()->subWeek()->toIso8601String(), 'staff_id' => $this->staff->id, 'from' => 'active', 'auto_renew' => true]]]);
        $this->domain(['status' => DomainStatus::Active]); // the same name, another customer's, holds it now

        $thrown = null;
        try {
            app(DomainRecordCorrections::class)->setStatus($ended, DomainStatus::Active, $this->staff);
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(DisplayException::class, $thrown, 'got ' . ($thrown === null ? 'nothing' : $thrown::class));
        $this->assertSame(DomainStatus::Cancelled, $ended->fresh()->status);
    }

    /**
     * ADV-848-4: «Transferred away» needed admin.domains.manage before #848 (markTransferredAway, resumeNotAway); from
     * the card it takes admin.domains.update only, as does «Cancelled»: a staff member who may only correct records ends
     * the domain's renewals and billing (the cron cancels its invoices tonight). Low: D-26 says «update»; the owner's call
     * whether ending a domain is a «manage» right, as it was.
     */
    public function test_adv_848_4_ending_a_domain_still_needs_manage(): void
    {
        $domain = $this->domain();
        $editor = Staff::factory()->create(['role_id' => Role::create(['name' => 'Editor', 'permissions' => ['admin.domains.viewAny', 'admin.domains.update']])->id]);
        $this->actingAs($editor, 'staff');

        Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()])->saveRecord(['status' => DomainStatus::TransferredAway->value]);

        $this->assertSame(DomainStatus::Active, $domain->fresh()->status, 'update without manage ended it');
    }

    /** Sound: cancelled, its renewal invoice cancelled tonight, back the next day: billed once again, never twice. */
    public function test_sound_cancel_cron_resume_bills_the_period_once_again(): void
    {
        $domain = $this->domain(['expires_at' => now()->addDays(10)->toDateString()]);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $first = $this->openRenewals($domain);
        $this->assertCount(1, $first, 'billed 15 days before');

        app(DomainRecordCorrections::class)->setStatus($domain, DomainStatus::Cancelled, $this->staff);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertSame([], $this->openRenewals($domain));
        $this->assertSame(Invoice::STATUS_CANCELLED, Invoice::query()->find($first[0])->status);

        $this->travel(1)->days();
        app(DomainRecordCorrections::class)->setStatus($domain->fresh(), DomainStatus::Active, $this->staff);
        $this->assertTrue($domain->fresh()->auto_renew);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $again = $this->openRenewals($domain);
        $this->assertCount(1, $again, 'billed again, once');
        $this->assertNotSame($first, $again);
    }

    /** Sound: a value not offered, forced through Livewire, is refused and nothing changes. */
    public function test_sound_a_forced_status_is_refused(): void
    {
        $domain = $this->domain();
        Livewire::test(ViewDomain::class, ['record' => $domain->getRouteKey()])
            ->saveRecord(['status' => DomainStatus::Deleted->value]);
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);

        $this->expectException(DisplayException::class);
        app(DomainRecordCorrections::class)->setStatus($domain, DomainStatus::PendingRegister, $this->staff);
    }

    /** Sound: a domain whose registrar lock is held (a renewal under way) is not ended meanwhile. */
    public function test_sound_busy_domain_is_not_ended(): void
    {
        $domain = $this->domain();
        $lock = cache()->lock("domain-registrar:{$domain->id}", 60);
        $this->assertTrue($lock->get());
        try {
            app(DomainRecordCorrections::class)->setStatus($domain, DomainStatus::Cancelled, $this->staff);
            $this->fail('ended while busy');
        } catch (DisplayException) {
        } finally {
            $lock->release();
        }
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
    }

    /** Sound: a double submit ends it once, one audit row. */
    public function test_sound_double_submit_ends_it_once(): void
    {
        $domain = $this->domain();
        $corrections = app(DomainRecordCorrections::class);
        $corrections->setStatus($domain, DomainStatus::Cancelled, $this->staff);
        $this->assertNull($corrections->setStatus($domain->fresh(), DomainStatus::Cancelled, $this->staff));
        $this->assertSame(1, Audit::query()->where('event', 'record_status')->count());
        $this->assertFalse($domain->fresh()->auto_renew);
    }
}
