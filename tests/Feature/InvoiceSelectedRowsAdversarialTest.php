<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ClientSummaryPage;
use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Billing\RenewalBilling;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of PR #449: the client Summary's «With selected» → «Invoice selected items»
 * (ClientSummaryPage::$selectedServices / $selectedDomains, InvoiceSelectedItemsAction::forSummary()).
 *
 * Tests marked «regression guard» pass on the PR as it stands and stay to keep it so; the others fail and are findings.
 */
class InvoiceSelectedRowsAdversarialTest extends TestCase
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
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['EUR', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
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
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2027-08-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function lines(Service|Domain $item): int
    {
        return InvoiceItem::query()->where('reference_type', $item::class)->where('reference_id', $item->id)->count();
    }

    private function summary(?Staff $staff = null)
    {
        return $this->livewireAs($staff ?? Staff::factory()->admin()->create())
            ->test(ClientSummaryPage::class, ['record' => $this->user->client()->getRouteKey()]);
    }

    /** @return list<array<string, mixed>> the notifications sent in this request */
    private function notifications(): array
    {
        app(NotificationMailbox::class)->flush();
        $component = new Notifications;
        $component->mount();
        $component->pullNotificationsFromSession();

        return $component->notifications->map(fn (Notification $n) => ['title' => $n->getTitle(), 'body' => $n->getBody()])->values()->all();
    }

    // ---------------------------------------------------------------- findings (fail on the PR)

    /**
     * ADV449-1: a value that is not an id is coerced with intval(): a nested array becomes 1 (intval([...]) === 1),
     * true becomes 1, «1abc» becomes 1. Something staff never ticked is billed: service #1, if it is this client's.
     */
    public function test_a_nested_array_or_junk_value_is_not_turned_into_service_one(): void
    {
        $one = $this->service(['id' => 1]);
        $this->assertSame(1, $one->id, 'precondition');

        $this->summary()->set('selectedServices', [['999'], '1abc', '1.9', ' 1'])->callAction('invoiceSelectedRows');

        $this->assertSame(0, $this->lines($one), 'nothing in the state names service #1; it must not be billed');
    }

    /**
     * ADV449-2: a ticked row that is no longer billable when the action runs (terminated, moved to another client,
     * a free plan since the page was drawn) is dropped silently by billableServices()->whereKey(). Staff are told
     * «Nothing selected» (or only what was billed) and never learn that a row they ticked was not invoiced. The modal
     * action and InvoiceSelectedItems report every skipped item with why; this path should too.
     */
    public function test_a_ticked_row_that_stopped_being_billable_is_reported_not_dropped_silently(): void
    {
        $service = $this->service();
        $page = $this->summary()->set('selectedServices', [(string) $service->id]);
        $service->update(['status' => Service::STATUS_TERMINATED]); // e.g. another staff member, or the cron, meanwhile

        $page->callAction('invoiceSelectedRows');

        $this->assertSame(0, $this->lines($service));
        $notifications = $this->notifications();
        $this->assertNotEmpty($notifications);
        $text = json_encode($notifications, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(__('invoices.selected.none'), $text, 'one row was ticked: «Nothing selected» is wrong');
        $this->assertStringContainsString('#' . $service->id, $text, 'the ticked row should come back as skipped, with why');
    }

    /**
     * Holds the client's «selecting» lock as another billing run would, on a store the Livewire request shares, in real
     * time: Lock::block() times out by the clock, and a lock taken at a frozen past time would look expired.
     */
    private function whileBusy(Service $service, \Closure $test): void
    {
        $path = sys_get_temp_dir() . '/adv449-locks-' . getmypid();
        config(['cache.stores.adv449' => ['driver' => 'file', 'path' => $path, 'lock_path' => $path], 'cache.default' => 'adv449']);
        Carbon::setTestNow();
        $held = RenewalBilling::selectingLock((int) $service->client_id);
        $this->assertTrue($held->get(), 'precondition: another run holds the lock');
        try {
            $test(); // blocks for InvoiceSelectedItems' 10 s
        } finally {
            $held->release();
            Cache::store('adv449')->flush();
        }
    }

    /**
     * ADV449-3: while another run holds the customer's lock, InvoiceSelectedItems::lock() means to say «busy»
     * (`if (!$lock->block(10)) throw new DisplayException(busy)`), but Lock::block() never returns false: it throws
     * Illuminate\Contracts\Cache\LockTimeoutException. Staff get an error page (500) instead of «try again in a
     * moment», and with several customers the locks already taken are not released (held for their 600 s). The same in
     * the header modal and the bulk actions; the Summary makes it one click away. Fix: catch LockTimeoutException
     * (or use `$lock->get()` in a loop / `block()` inside try), release what was taken, throw the DisplayException.
     */
    public function test_a_busy_customer_says_busy_instead_of_a_lock_timeout_error(): void
    {
        $service = $this->service();

        $this->whileBusy($service, fn () => $this->summary()->set('selectedServices', [(string) $service->id])
            ->callAction('invoiceSelectedRows')->assertNotified(__('invoices.selected.nothing_billed')));

        $this->assertSame(0, $this->lines($service));
    }

    /**
     * ADV449-4: when nothing was processed (busy), the action still empties selectedServices/selectedDomains: staff must
     * find and tick the rows again to retry. Clear the ticks only when the selection was actually processed.
     * (Fails on ADV449-3's exception first; once that is fixed, it fails on the cleared ticks.)
     */
    public function test_the_ticks_are_kept_when_the_customer_is_busy_and_nothing_was_processed(): void
    {
        $service = $this->service();

        $this->whileBusy($service, function () use ($service): void {
            try {
                $page = $this->summary()->set('selectedServices', [(string) $service->id])->callAction('invoiceSelectedRows');
            } catch (\Throwable $e) {
                $this->fail('ADV449-3 first: ' . $e::class);
            }
            $page->assertSet('selectedServices', [(string) $service->id]);
        });
    }

    // ---------------------------------------------------------------- regression guards (pass on the PR)

    /** Regression guard: two staff with the same ticks (or a replayed request) bill the period once. */
    public function test_two_staff_billing_the_same_rows_bill_each_period_once(): void
    {
        $service = $this->service();
        $domain = $this->domain();
        $a = $this->summary()->set('selectedServices', [(string) $service->id])->set('selectedDomains', [(string) $domain->id]);
        $b = $this->summary()->set('selectedServices', [(string) $service->id])->set('selectedDomains', [(string) $domain->id]);

        $a->callAction('invoiceSelectedRows');
        $b->callAction('invoiceSelectedRows');
        // The same staff member again on the cleared page, with the state put back by hand.
        $a->set('selectedServices', [(string) $service->id])->set('selectedDomains', [(string) $domain->id])->callAction('invoiceSelectedRows');

        $this->assertSame([1, 1], [$this->lines($service), $this->lines($domain)]);
    }

    /** Regression guard: a domain id in selectedServices (and the other way round) is not billed as the other kind. */
    public function test_ids_in_the_wrong_list_bill_nothing_of_the_other_kind(): void
    {
        $service = $this->service();
        $domain = $this->domain();
        // Make the ids differ so a cross-match cannot hide behind equal numbers.
        $this->assertNotSame($service->id + 1000, $domain->id);

        $this->summary()->set('selectedServices', [(string) $domain->id + 1000])->set('selectedDomains', [(string) $service->id + 1000])
            ->callAction('invoiceSelectedRows')->assertNotified(__('invoices.selected.none'));

        $this->assertSame([0, 0], [$this->lines($service), $this->lines($domain)]);
    }

    /** Regression guard: a large tampered array (2 000 ids; 50 000 did not finish in the Livewire test harness, not followed further) neither errors nor bills anything not ticked. */
    public function test_a_huge_array_of_ids_is_handled(): void
    {
        $service = $this->service();
        $other = $this->service();
        $ids = array_map('strval', range(100000, 102000));
        $ids[] = (string) $service->id;

        $this->summary()->set('selectedServices', $ids)->callAction('invoiceSelectedRows');

        $this->assertSame([1, 0], [$this->lines($service), $this->lines($other)]);
    }

    /** Regression guard: zero, negative, empty, null and false ids bill nothing. */
    public function test_zero_negative_and_empty_ids_bill_nothing(): void
    {
        $service = $this->service();

        $this->summary()->set('selectedServices', ['0', '-' . $service->id, '', null, false])
            ->callAction('invoiceSelectedRows')->assertNotified(__('invoices.selected.none'));

        $this->assertSame(0, $this->lines($service));
    }

    /** Regression guard: staff who may see services and domains but not create invoices cannot run it by mounting it. */
    public function test_staff_without_invoices_create_cannot_mount_it_with_tampered_state(): void
    {
        $service = $this->service();
        $viewer = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.services.viewAny', 'admin.domains.viewAny', 'admin.invoices.viewAny'])->create();

        $this->summary($viewer)->assertActionHidden('invoiceSelectedRows')
            ->set('selectedServices', [(string) $service->id])->call('mountAction', 'invoiceSelectedRows')->call('callMountedAction');

        $this->assertSame(0, $this->lines($service));
    }

    /** Regression guard: staff who may create invoices but see neither services nor domains bill nothing. */
    public function test_staff_who_see_neither_kind_bill_nothing(): void
    {
        $service = $this->service();
        $domain = $this->domain();
        $blind = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.invoices.viewAny', 'admin.invoices.create'])->create();

        $this->summary($blind)->set('selectedServices', [(string) $service->id])->set('selectedDomains', [(string) $domain->id])
            ->callAction('invoiceSelectedRows');

        $this->assertSame([0, 0], [$this->lines($service), $this->lines($domain)]);
    }

    /** Regression guard: the header modal action and «With selected» do not bill the same period twice. */
    public function test_the_modal_action_then_with_selected_bill_once(): void
    {
        $service = $this->service();
        $page = $this->summary()->set('selectedServices', [(string) $service->id]);

        $page->callAction('invoiceSelected', ['services' => [$service->id]]);
        $page->callAction('invoiceSelectedRows');

        $this->assertSame(1, $this->lines($service));
    }
}
