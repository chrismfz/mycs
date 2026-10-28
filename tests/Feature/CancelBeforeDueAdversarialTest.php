<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Admin\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Role;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\InvoiceSelectedItems;
use App\Services\Domains\DomainRenewalService;
use App\Services\Invoice\MergeInvoices;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of ff7aa27 (D7a ADV-4, owner 2026-10-17): a renewal cancelled with nobody choosing gives up only the
 * periods within the cron's days before; farther ones stay billable and the cron bills them at their time. Δ3a must keep
 * working for the cron's own renewals (no re-billing after staff cancel them). Failing tests are findings; tests named
 * test_defended_* document attacks that held.
 */
class CancelBeforeDueAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['EUR', 'string']]);
        config(['settings.cronjob_invoice' => 7, 'settings.domain_invoice_days' => 15, 'settings.domain_billing_enabled' => true]);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '23.33']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    private function service(array $attributes = []): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create($attributes + [
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2027-03-01',
        ]);
    }

    private function domain(array $attributes = []): Domain
    {
        static $n = 0;
        $n++;

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => "adv{$n}", 'tld' => 'com', 'fqdn' => "adv{$n}.com",
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2027-03-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    /** @return list<InvoiceItem> */
    private function lines(Service|Domain $item): array
    {
        return InvoiceItem::query()->where('reference_type', $item::class)->where('reference_id', $item->id)->whereNull('kind')->orderBy('id')->get()->all();
    }

    private function select(array $services = [], array $domains = []): Invoice
    {
        return Invoice::query()->findOrFail((new InvoiceSelectedItems)->handle($services, $domains)['invoices'][0]);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    private function staff(): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Adv' . uniqid(), 'permissions' => ['*']])->id]);
    }

    // ---------------------------------------------------------------- findings

    /**
     * A4-1 (boundary). The cron bills a service whose period starts on today + cronjob_invoice (expires_at at 00:00 is
     * before now()+7d at any time after midnight). Staff cancel that fresh cron renewal the same day (Δ3a: «not
     * renewing»): period_start == billedFrom, so `<` keeps it billable and the next night the cron bills it again.
     */
    public function test_a_cron_renewal_on_the_last_day_of_the_window_cancelled_the_same_day_is_not_billed_again(): void
    {
        $service = $this->service(['expires_at' => '2026-10-27']); // today + 7
        $this->cron();
        $this->assertCount(1, $this->lines($service), 'the cron billed it today');
        $invoice = $this->lines($service)[0]->invoice;

        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'not renewing']);
        $this->assertNotNull($this->lines($service)[0]->fresh()->forgone_at, 'the cron billed it: it is within its days before');

        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();
        $this->assertCount(1, $this->lines($service), 'Δ3a: a cancelled cron renewal is not billed again the next night');
    }

    /** A4-1b: the same edge for a domain (domain_invoice_days = 15). */
    public function test_a_cron_domain_renewal_on_the_last_day_of_its_window_cancelled_the_same_day_is_not_billed_again(): void
    {
        $domain = $this->domain(['expires_at' => '2026-11-04']); // today + 15
        $this->cron();
        $this->assertCount(1, $this->lines($domain), 'the cron billed it today');
        $this->lines($domain)[0]->invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'not renewing']);

        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();
        $this->assertCount(1, $this->lines($domain), 'Δ3a: a cancelled cron renewal is not billed again the next night');
    }

    /** A4-1c: and the Cancel toggle «Bill its period again» starts ON for that fresh cron renewal (billsOnlyPeriodsToCome). */
    public function test_the_cancel_toggle_starts_off_for_the_crons_own_renewal_billed_today(): void
    {
        $service = $this->service(['expires_at' => '2026-10-27']);
        $this->cron();
        $invoice = $this->lines($service)[0]->invoice;

        $this->assertFalse($invoice->billsOnlyPeriodsToCome(), 'the cron billed it, so the cron «would» bill it now: not a period to come');
        $this->livewireAs($this->staff())->test(ListInvoices::class)
            ->mountTableAction('cancel', $invoice)->assertTableActionDataSet(['periods' => 'auto'])
            ->setTableActionData(['cancellation_reason' => 'not renewing'])->callMountedTableAction()->assertHasNoTableActionErrors();
        $this->assertNotNull($this->lines($service)[0]->fresh()->forgone_at, 'the cron\'s own renewal, cancelled as usual: given up');
    }

    /**
     * A4-2 (double billing). A domain renewal invoiced early and cancelled (kept billable) is billed again by the cron at
     * its time; staff then reopen the old invoice. For services InvoiceObserver::billedElsewhere refuses (D3A-10), but it
     * looks at Service lines only: two live invoices now bill the same domain year, and both paid renew it twice.
     */
    public function test_reopening_a_kept_domain_renewal_after_the_cron_billed_it_again_is_refused(): void
    {
        $domain = $this->domain(['expires_at' => '2027-03-01']);
        $old = $this->select([], [$domain]);
        $old->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'customer asked']);
        $this->assertNull($this->lines($domain)[0]->fresh()->forgone_at, 'kept billable (ADV-4)');

        Carbon::setTestNow('2027-02-20 10:00:00');
        $this->cron();
        $this->assertCount(2, $this->lines($domain), 'billed again at its time');

        try {
            $old->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
        } catch (DisplayException) {
        }
        $live = collect($this->lines($domain))->filter(fn (InvoiceItem $l) => $l->invoice->fresh()->status !== Invoice::STATUS_CANCELLED);
        $this->assertCount(1, $live, 'one live invoice per domain period (as billedElsewhere does for services)');
    }

    /**
     * A4-3 (mixed invoice via the UI). A far service and a domain within its window on one invoice: by the rule (nobody
     * chose) the service stays billable and the domain is given up. Through the admin Cancel action the toggle defaults
     * off (billsOnlyPeriodsToCome is all-or-nothing) and always sends a bool, so keep=false gives up the far service too:
     * the «nobody chose» rule is never reached from the UI, and the customer who was invoiced early loses the service.
     */
    public function test_a_mixed_invoice_cancelled_with_the_admin_defaults_keeps_the_far_service_billable(): void
    {
        $service = $this->service(['expires_at' => '2027-03-01']);
        $domain = $this->domain(['expires_at' => '2026-10-30']); // within 15 days
        $invoice = $this->select([$service], [$domain]);
        $this->assertCount(1, $this->lines($service));
        $this->assertSame($invoice->id, $this->lines($domain)[0]->invoice_id, 'one invoice');

        $this->livewireAs($this->staff())->test(ListInvoices::class)
            ->callTableAction('cancel', $invoice, data: ['cancellation_reason' => 'customer asked'])->assertHasNoTableActionErrors();

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);
        $this->assertNotNull($this->lines($domain)[0]->fresh()->forgone_at, 'the domain, within its days, is given up');
        $this->assertNull($this->lines($service)[0]->fresh()->forgone_at, 'the far service stays billable, as with nobody choosing');
    }

    /**
     * A4-4 (D3A-6 for domains). A cancelled invoice that records a given-up domain period can be deleted
     * (Invoice::recordsForgonePeriod looks at Service lines only): deleted, the cron bills the period again.
     */
    public function test_a_cancelled_invoice_recording_a_given_up_domain_period_is_not_deleted(): void
    {
        $domain = $this->domain(['expires_at' => '2026-10-25']);
        $this->cron();
        $invoice = $this->lines($domain)[0]->invoice;
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'not renewing']);
        $this->assertNotNull($this->lines($domain)[0]->fresh()->forgone_at);

        try {
            $invoice->fresh()->delete();
        } catch (DisplayException) {
        }
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();
        $this->assertNotNull(Invoice::query()->find($invoice->id), 'kept: it records the period given up on');
        $this->assertCount(1, $this->lines($domain), 'not billed again');
    }

    /**
     * A4-5 (loose lines). A service line without a period (staff's hand invoice, an import) cancelled early: givePeriodsUp
     * gives it the period it would pay and marks it forgone unconditionally, ignoring onlyDue. A far period is given up and
     * the service is never billed again (falls to suspension), against the owner's rule.
     */
    public function test_a_service_line_without_period_cancelled_long_before_its_period_stays_billable(): void
    {
        $service = $this->service(['expires_at' => '2027-03-01']);
        $invoice = Invoice::query()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'due_at' => now(), 'currency_code' => 'EUR']);
        $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'price' => '10.00',
            'quantity' => 1, 'description' => 'Hosting (by hand)']);

        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'customer asked']);
        $this->assertNull($this->lines($service)[0]->fresh()->forgone_at, 'its period (from 2027-03-01) is far: kept billable');

        Carbon::setTestNow('2027-02-25 10:00:00');
        $this->cron();
        $this->assertCount(2, $this->lines($service), 'billed at its time by the cron');
    }

    // ---------------------------------------------------------------- defended

    /** No loop: a far period kept, billed by the cron at its time, and that cron renewal cancelled is not billed again. */
    public function test_defended_kept_then_billed_by_the_cron_then_cancelled_is_not_billed_a_third_time(): void
    {
        $service = $this->service(['expires_at' => '2027-03-01']);
        $this->select([$service])->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'x']);
        Carbon::setTestNow('2027-02-25 10:00:00');
        $this->cron();
        $this->assertCount(2, $this->lines($service));
        $this->lines($service)[1]->invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'not renewing']);
        foreach (['2027-02-26', '2027-02-27', '2027-03-02'] as $day) {
            Carbon::setTestNow("{$day} 10:00:00");
            $this->cron();
        }
        $this->assertCount(2, $this->lines($service));
    }

    /** The same for a domain. */
    public function test_defended_a_kept_domain_billed_by_the_cron_then_cancelled_is_not_billed_again(): void
    {
        $domain = $this->domain(['expires_at' => '2027-03-01']);
        $this->select([], [$domain])->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'x']);
        Carbon::setTestNow('2027-02-20 10:00:00');
        $this->cron();
        $this->assertCount(2, $this->lines($domain));
        $this->lines($domain)[1]->invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'not renewing']);
        Carbon::setTestNow('2027-02-21 10:00:00');
        $this->cron();
        $this->assertCount(2, $this->lines($domain));
    }

    /** Services: reopening a kept invoice after the cron billed its period again is refused (D3A-10). */
    public function test_defended_reopening_a_kept_service_renewal_after_the_cron_billed_it_again_is_refused(): void
    {
        $service = $this->service(['expires_at' => '2027-03-01']);
        $old = $this->select([$service]);
        $old->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'x']);
        Carbon::setTestNow('2027-02-25 10:00:00');
        $this->cron();
        $this->expectException(DisplayException::class);
        $old->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
    }

    /** Reopened, a given-up domain line is billable by that invoice again (forgone_at cleared for domains too). */
    public function test_defended_reopen_clears_the_domain_mark(): void
    {
        $domain = $this->domain(['expires_at' => '2026-10-25']);
        $this->cron();
        $invoice = $this->lines($domain)[0]->invoice;
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'x']);
        $this->assertNotNull($this->lines($domain)[0]->fresh()->forgone_at);
        $invoice->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
        $this->assertNull($this->lines($domain)[0]->fresh()->forgone_at);
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->cron();
        $this->assertCount(1, $this->lines($domain));
    }

    /** keep_periods_billable = false (staff's «not this period») gives up a far domain and a far service. */
    public function test_defended_staff_off_gives_up_far_periods_of_both_kinds(): void
    {
        $service = $this->service(['expires_at' => '2027-03-01']);
        $domain = $this->domain(['expires_at' => '2027-03-01']);
        $invoice = $this->select([$service], [$domain]);
        $invoice->keep_periods_billable = false;
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'x']);
        Carbon::setTestNow('2027-02-25 10:00:00');
        $this->cron();
        $this->assertSame([1, 1], [count($this->lines($service)), count($this->lines($domain))]);
    }

    /** The domain step uses its own window: a domain 10 days out is within its 15, a service 10 days out is not within 7. */
    public function test_defended_each_kind_uses_its_own_window(): void
    {
        $service = $this->service(['expires_at' => '2026-10-30']);
        $domain = $this->domain(['expires_at' => '2026-10-30']);
        $invoice = $this->select([$service], [$domain]);
        $invoice->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'x']);
        $this->assertNull($this->lines($service)[0]->fresh()->forgone_at);
        $this->assertNotNull($this->lines($domain)[0]->fresh()->forgone_at);
    }

    /** «Renew now» (payable) never takes a kept line on a cancelled invoice as the one to pay. */
    public function test_defended_renew_now_ignores_a_kept_cancelled_line(): void
    {
        $domain = $this->domain(['expires_at' => '2027-03-01']);
        $this->select([], [$domain])->update(['status' => Invoice::STATUS_CANCELLED, 'cancellation_reason' => 'x']);
        $start = Carbon::parse('2027-03-01');
        $this->assertNull(DomainRenewalService::billedLine($domain, $start, payable: true));
        $this->assertNull(DomainRenewalService::billedLine($domain, $start), 'kept: the cron bills it');
    }

    /** A merge cancels its sources with keep = true: the moved lines bill on the target, nothing is billed twice. */
    public function test_defended_merge_does_not_make_the_cron_bill_again(): void
    {
        $domain = $this->domain(['expires_at' => '2027-03-01']);
        $service = $this->service(['expires_at' => '2027-03-01']);
        $a = $this->select([], [$domain]);
        $b = $this->select([$service]);
        (new MergeInvoices)->handle($a->id, [$b->id]);
        Carbon::setTestNow('2027-02-25 10:00:00');
        $this->cron();
        $this->assertSame([1, 1], [count($this->lines($service)), count($this->lines($domain))]);
    }

    /** The ViewInvoice Cancel action, with its defaults: a far-only invoice keeps its period, a near one gives it up. */
    public function test_defended_view_invoice_toggle_default_follows_the_invoice(): void
    {
        $this->settings(['immutable_invoices_enabled' => [true, 'boolean']]);
        $farService = $this->service(['expires_at' => '2027-03-01']);
        $nearService = $this->service(['expires_at' => '2026-10-22']);
        // With immutable invoices on, only a draft can be cancelled (InvoicePolicy::update).
        [$far, $near] = array_map(function (array $period) {
            [$service, $start, $end] = $period;
            $invoice = Invoice::query()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_DRAFT, 'due_at' => now(), 'currency_code' => 'EUR']);
            $invoice->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'price' => '10.00', 'quantity' => 1,
                'description' => 'Hosting', 'period_start' => $start, 'period_end' => $end]);

            return $invoice;
        }, [[$farService, '2027-03-01', '2027-04-01'], [$nearService, '2026-10-22', '2026-11-22']]);
        $staff = $this->staff();
        $this->livewireAs($staff)->test(ViewInvoice::class, ['record' => $far->getRouteKey()])
            ->assertActionVisible('cancel')
            ->callAction('cancel', ['cancellation_reason' => 'x'])->assertHasNoActionErrors();
        $this->livewireAs($staff)->test(ViewInvoice::class, ['record' => $near->getRouteKey()])
            ->callAction('cancel', ['cancellation_reason' => 'x'])->assertHasNoActionErrors();
        $this->assertSame([Invoice::STATUS_CANCELLED, Invoice::STATUS_CANCELLED], [$far->fresh()->status, $near->fresh()->status]);
        $this->assertNull($this->lines($farService)[0]->fresh()->forgone_at);
        $this->assertNotNull($this->lines($nearService)[0]->fresh()->forgone_at);
    }
}
