<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Jobs\Domains\RenewDomainJob;
use App\Models\ApiKey;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainReminders;
use App\Services\Domains\DomainRenewalService;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of PR #174 (fix/billedline-period-less), written without the author's context. A failing test is a
 * finding (ADV-174-n); a passing one documents an attack the code defends.
 */
class DomainLinePeriodAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['EUR', 'string']] as $key => [$value, $type]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $type]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_invoice' => 7, 'settings.domain_invoice_days' => 15, 'settings.domain_billing_enabled' => true]);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        foreach ([['renew', 1, '23.33'], ['renew', 2, '46.66']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        $this->token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'adv', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $this->token), 'permissions' => ['admin.invoice_items.create', 'invoice_items.create',
                'admin.invoice_items.update', 'invoice_items.update']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(array $attributes = []): Domain
    {
        static $n = 0;
        $n++;

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => "nixpal{$n}", 'tld' => 'com', 'fqdn' => "nixpal{$n}.com",
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function invoice(string $due = '2026-10-20'): Invoice
    {
        return Invoice::query()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => $due]);
    }

    private function line(Invoice $invoice, Domain $domain, array $extra = []): InvoiceItem
    {
        // A renewal made by hand asks to be one (since the review of #174: without, a line without a period is a charge).
        return $invoice->items()->create($extra + ['description' => 'Line', 'price' => '23.33', 'quantity' => 1,
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_RENEWAL]);
    }

    private function pay(Invoice $invoice): void
    {
        $invoice = $invoice->fresh();
        (new RecordPaymentService)->handle($invoice, null, (string) $invoice->total, transactionId: 'tx-' . $invoice->id);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    private function patchLine(InvoiceItem $line, array $body)
    {
        return $this->withToken($this->token)->patchJson(route('api.v1.admin.invoice-items.update', ['invoice_item' => $line->id]), $body);
    }

    /** @return list<InvoiceItem> its renewal lines (no kind) on invoices not cancelled */
    private function liveRenewals(Domain $domain): array
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')
            ->whereHas('invoice', fn ($q) => $q->where('status', '!=', Invoice::STATUS_CANCELLED))->orderBy('id')->get()->all();
    }

    // ---------------------------------------------------------------- findings (failing)

    /**
     * ADV-174-1: a paid owner-change fee («charge») turned into a renewal afterwards (Admin API kind=null; kind is not a
     * money field, the invoice may be paid) gets a period and becomes a paid renewal waiting for its registrar: D5b-2
     * renews the domain at the registrar with the company's money for a fee that never paid a renewal.
     */
    public function test_adv1_a_paid_charge_made_a_renewal_after_payment_is_not_renewed_at_the_registrar(): void
    {
        $domain = $this->domain();
        $invoice = $this->invoice();
        $charge = $this->line($invoice, $domain, ['description' => 'Owner change', 'price' => '5.00', 'kind' => InvoiceItem::KIND_CHARGE]);
        $this->pay($invoice);

        $this->patchLine($charge, ['kind' => null]);

        $this->assertFalse(DomainRenewalService::waitingLines()->whereKey($charge->id)->exists(),
            'a 5.00 fee paid on a frozen invoice must never become a renewal the registrar charges for');
    }

    /**
     * ADV-174-2: the reverse: a paid renewal waiting for its registrar re-labelled «charge» (API, paid invoice) drops out of
     * waitingLines (never renewed though paid) and out of billedLine (the cron bills the same year again).
     */
    public function test_adv2_a_paid_renewal_cannot_be_relabelled_a_charge(): void
    {
        $domain = $this->domain();
        $invoice = $this->invoice();
        $renewal = $this->line($invoice, $domain);
        $this->pay($invoice);
        $this->assertTrue(DomainRenewalService::waitingLines()->whereKey($renewal->id)->exists());

        $this->patchLine($renewal, ['kind' => InvoiceItem::KIND_CHARGE]);

        $this->assertNull($renewal->fresh()->kind, 'a paid invoice keeps what it charged: its renewal stays its renewal');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertCount(1, $this->liveRenewals($domain), 'the year paid is not billed a second time');
    }

    /**
     * ADV-174-3: the API's rule (`nullable|in:charge`) lets staff clear a stage fee's kind: the grace fee becomes a second
     * renewal (the next year, at the fee's price) on the same invoice; paid, the registrar is charged an extra year.
     */
    public function test_adv3_a_stage_fee_cannot_be_turned_into_a_renewal_through_the_api(): void
    {
        $domain = $this->domain(['status' => DomainStatus::Grace, 'expires_at' => '2026-10-10']);
        $invoice = $this->invoice();
        $this->line($invoice, $domain);
        $fee = $this->line($invoice, $domain, ['description' => 'Grace fee', 'price' => '100.30', 'kind' => 'grace_fee']);

        $response = $this->patchLine($fee, ['kind' => null]);

        $this->assertSame('grace_fee', $fee->fresh()->kind, 'response ' . $response->status() . ': a stage fee is not a renewal');
        $this->assertCount(1, $this->liveRenewals($domain));
    }

    /**
     * ADV-174-4: a renewal line moved to another domain (API reference_id) keeps the first domain's period: givePeriod only
     * fills an empty one. The second domain counts that period as billed (or as a period it never had), and paid, the
     * registrar is asked to renew it up to the first domain's end.
     */
    public function test_adv4_a_line_moved_to_another_domain_gets_that_domains_period(): void
    {
        $a = $this->domain(['expires_at' => '2026-11-01']);
        $b = $this->domain(['expires_at' => '2027-03-15']);
        $invoice = $this->invoice();
        $line = $this->line($invoice, $a);
        $this->assertSame('2026-11-01', $line->period_start->toDateString());

        // Closed by refusing it (code review F4): a domain line keeps its domain and its period; staff make a new line.
        $this->patchLine($line, ['reference_id' => $b->id])->assertUnprocessable();

        $line->refresh();
        $this->assertSame([(int) $a->id, '2026-11-01'], [(int) $line->reference_id, $line->period_start?->toDateString()]);
    }

    /**
     * ADV-174-5: a domain without an expiry yet (pending, not synced): a renewal line made for it keeps no period; once the
     * sync sets the expiry, billedLine ignores the period-less line and the cron bills the same year again. Paid, the
     * period-less line never reaches the registrar (waitingLines needs a period): the customer pays twice, renewed once.
     */
    public function test_adv5_a_line_made_before_the_expiry_is_known_is_not_billed_twice(): void
    {
        // Closed by refusing it (code review F6): no expiry, no period to renew; a charge on it stays possible.
        $domain = $this->domain(['expires_at' => null]);
        $invoice = $this->invoice();
        try {
            $this->line($invoice, $domain);
            $this->fail('a renewal of a domain without an expiry was made');
        } catch (DisplayException) {
        }
        $this->assertSame(InvoiceItem::KIND_CHARGE, $this->line($invoice, $domain, ['kind' => null])->kind);

        $domain->update(['expires_at' => '2026-11-01']); // the sync learns it
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertCount(1, $this->liveRenewals($domain), 'one renewal for the year');
    }

    /**
     * ADV-174-6: a 2-year domain in its grace: the cron bills ONE year (D-13: a restore brings back one year, priced so),
     * givePeriod gives it its 2 years. Staff add the renewal by hand at the one-year price: paid, D5b-2 restores and renews
     * another year at the registrar (the company pays 2 years, the customer paid 1).
     */
    public function test_adv6_a_hand_made_renewal_in_grace_gets_one_year_as_the_crons(): void
    {
        $domain = $this->domain(['years' => 2, 'status' => DomainStatus::Grace, 'expires_at' => '2026-10-10']);
        $line = $this->line($this->invoice(), $domain);

        $this->assertSame(['2026-10-10', '2027-10-10'], [$line->period_start->toDateString(), $line->period_end->toDateString()]);
    }

    /**
     * ADV-174-7: a hole. Year 1 is on an invoice cancelled before its period (kept billable, Δ3/ADV-4), year 2 on a live
     * one (staff billed two years apart). billedLine asks «a line whose period ENDS after the start», not «covers the
     * start»: year 2 counts as year 1 billed. The cron never bills year 1 (the registrar is paid one year for year 2's
     * money and the domain lapses a year early, never billed again); a new hand-made line jumps to year 3.
     */
    public function test_adv7_a_hole_before_a_later_period_is_billed(): void
    {
        $domain = $this->domain(['expires_at' => '2026-12-01']); // beyond the 15 days: a cancellation keeps it billable
        $first = $this->invoice();
        $this->line($first, $domain); // 2026-12-01 → 2027-12-01
        $second = $this->invoice();
        $year2 = $this->line($second, $domain);
        $this->assertSame('2027-12-01', $year2->period_start->toDateString());
        $first->update(['status' => Invoice::STATUS_CANCELLED]); // nobody chose: kept billable (period beyond its days)
        $this->assertNull(InvoiceItem::query()->where('invoice_id', $first->id)->value('forgone_at'));

        $this->assertNull(DomainRenewalService::billedLine($domain, Carbon::parse('2026-12-01')),
            'year 1 is not billed: the year-2 line does not cover 2026-12-01');
        $third = $this->line($this->invoice(), $domain);
        $this->assertSame('2026-12-01', $third->period_start->toDateString(), 'the first unbilled period, not the one after the last');
    }

    /**
     * ADV-174-8: lines made before this change without a period (staff, the API since D5b-1) are now ignored by billedLine
     * and there is no migration giving them one: an open hand-made renewal is billed a second time by the next cron, and
     * paid it never reaches the registrar.
     */
    public function test_adv8_an_existing_period_less_renewal_is_not_billed_again_after_the_deploy(): void
    {
        $domain = $this->domain();
        $invoice = $this->invoice();
        DB::table('invoice_items')->insert(['invoice_id' => $invoice->id, 'description' => 'Renewal by hand', 'price' => '23.33', 'quantity' => 1,
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'taxed' => false, 'tax_rate' => 0, 'tax_amount' => 0,
            'line_total' => '23.33', 'created_at' => '2026-10-18', 'updated_at' => '2026-10-18']);

        // The migration (2026_11_25) makes it what it is without a period: a charge, which never renewed anything (none on
        // the live sites when it ran). The cron then bills the year.
        DB::table('invoice_items')->where('reference_type', Domain::class)->whereNull('kind')->whereNull('period_start')->update(['kind' => InvoiceItem::KIND_CHARGE]);
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertCount(1, $this->liveRenewals($domain), 'one renewal for the year');
        $this->assertSame(1, InvoiceItem::query()->where('reference_id', $domain->id)->where('kind', InvoiceItem::KIND_CHARGE)->count());
    }

    /**
     * ADV-174-9: «a expiry moved one day is the same period» (D5b-1), but covers() now wants due_at >= expiry: the
     * registrar's expiry one day later than the cron's (sync) makes the domain's own renewal invoice send its invoice
     * reminders too: the customer gets both the domain notice and the payment reminder.
     */
    public function test_adv9_an_expiry_moved_a_day_by_the_sync_still_covers_its_renewal_invoice(): void
    {
        $domain = $this->domain(['expires_at' => '2026-11-01']);
        $invoice = $this->invoice('2026-11-01'); // as the cron makes it: due on the expiry
        $this->line($invoice, $domain);
        $this->assertTrue(DomainReminders::covers($invoice->fresh()->load('items'), 'due'), 'precondition: covered');

        $domain->update(['expires_at' => '2026-11-02']); // the registrar's day

        $this->assertTrue(DomainReminders::covers($invoice->fresh()->load('items'), 'due'));
    }

    // ---------------------------------------------------------------- defended (passing)

    public function test_def_a_paid_charge_is_never_renewed_at_the_registrar(): void
    {
        $domain = $this->domain();
        $invoice = $this->invoice();
        $charge = $this->line($invoice, $domain, ['price' => '5.00', 'kind' => InvoiceItem::KIND_CHARGE]);
        $this->pay($invoice);

        $this->assertNull($charge->fresh()->period_start);
        $this->assertFalse(DomainRenewalService::waitingLines()->whereKey($charge->id)->exists());
        Queue::assertNotPushed(RenewDomainJob::class);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertCount(1, $this->liveRenewals($domain), 'the charge does not stop the renewal being billed');
    }

    public function test_def_the_api_refuses_a_renewal_on_a_paid_invoice(): void
    {
        $domain = $this->domain();
        $invoice = $this->invoice();
        $this->line($invoice, $domain, ['price' => '5.00', 'kind' => InvoiceItem::KIND_CHARGE]);
        $this->pay($invoice);

        $this->withToken($this->token)->postJson(route('api.v1.admin.invoice-items.store'), ['invoice_id' => $invoice->id, 'description' => 'Renewal',
            'price' => 23.33, 'quantity' => 1, 'reference_type' => Domain::class, 'reference_id' => $domain->id])->assertStatus(422);
        $this->assertCount(0, $this->liveRenewals($domain));
    }

    public function test_def_two_period_less_lines_on_one_invoice_take_consecutive_years(): void
    {
        $domain = $this->domain(['years' => 2]);
        $invoice = $this->invoice();
        $one = $this->line($invoice, $domain);
        $two = $this->line($invoice, $domain);

        $this->assertSame(['2026-11-01', '2028-11-01', '2028-11-01', '2030-11-01'], [$one->period_start->toDateString(), $one->period_end->toDateString(),
            $two->period_start->toDateString(), $two->period_end->toDateString()]);
    }

    public function test_def_a_period_given_up_is_billed_again_by_hand_and_the_old_invoice_cannot_reopen(): void
    {
        $domain = $this->domain(); // 2026-11-01: within the 15 days, a cancellation gives it up
        $old = $this->invoice();
        $this->line($old, $domain);
        $old->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertCount(0, $this->liveRenewals($domain), 'Δ3: given up, not billed again by the cron');

        $new = $this->line($this->invoice(), $domain);
        $this->assertSame('2026-11-01', $new->period_start->toDateString(), 'staff bill it again by hand');

        try {
            $old->fresh()->update(['status' => Invoice::STATUS_UNPAID]);
        } catch (\Throwable) {
        }
        $this->assertSame(Invoice::STATUS_CANCELLED, $old->fresh()->status, 'never two live invoices for one period');
    }

    public function test_def_a_paid_renewal_not_applied_pushes_the_next_hand_made_line_a_year(): void
    {
        $domain = $this->domain();
        $invoice = $this->invoice();
        $this->line($invoice, $domain);
        $this->pay($invoice);

        $next = $this->line($this->invoice(), $domain);
        $this->assertSame('2027-11-01', $next->period_start->toDateString());
    }

    public function test_def_a_refunded_renewal_leaves_its_year_to_a_new_line(): void
    {
        $domain = $this->domain();
        $invoice = $this->invoice();
        $this->line($invoice, $domain);
        $this->pay($invoice);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_REFUNDED]);

        $new = $this->line($this->invoice(), $domain);
        $this->assertSame('2026-11-01', $new->period_start->toDateString());
    }
}
