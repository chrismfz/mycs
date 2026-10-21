<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Domain sweep (2026-10-22), state and dates: expiry dates, lifecycle, the domain steps of the cron. Each test is a
 * finding that fails on the code as it is (test_F<n>_...).
 */
class DomainSweepBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    /** What the fake CentralNic Reseller answers to StatusDomain. */
    private string $paidUntil = '2026-11-20';

    private ?string $renewalDate = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            parse_str($request->body(), $form);
            $renewal = $this->renewalDate !== null ? "property[RENEWAL DATE][0] = {$this->renewalDate} 00:00:00.0\n" : '';

            return Http::response("[RESPONSE]\ncode = 200\ndescription = OK\nproperty[PAIDUNTIL DATE][0] = {$this->paidUntil} 00:00:00.0\n{$renewal}"
                . "property[STATUS][0] = clientTransferProhibited\nproperty[TRANSFER LOCK][0] = 1\nproperty[NAMESERVER][0] = ns1.example.net\nEOF\n");
        });
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15,
            'settings.cronjob_invoice' => 7, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'grace_days' => 40, 'redemption_days' => 0]);
        foreach ([['renew', 1, '23.33'], ['grace', 1, '100.30']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cnic(): DomainRegistrarConnection
    {
        $cnic = DomainRegistrarConnection::query()->create(['registrar' => 'cnic', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $cnic->storeCredentials(['username' => 'reseller', 'password' => 'Cnic-Secret-1'], ['password']);

        return $cnic;
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-20',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    /** A renewal line of the domain on an invoice of $status, for [$start, $end). */
    private function line(Domain $domain, string $start, string $end, string $status, bool $applied): InvoiceItem
    {
        $invoice = Invoice::query()->create(['client_id' => $domain->client_id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => $start, 'currency_code' => 'EUR']);
        $line = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => '23.33', 'quantity' => 1,
            'description' => "Renewal {$domain->fqdn}", 'period_start' => $start, 'period_end' => $end]);
        if ($status !== Invoice::STATUS_UNPAID) {
            Invoice::query()->whereKey($invoice->id)->update(['status' => $status]); // as the WHMCS import or a payment left it
        }
        if ($applied) {
            $line->forceFill(['applied_at' => now()])->save();
        }

        return $line->fresh();
    }

    /** @return list<InvoiceItem> */
    private function renewalLines(Domain $domain): array
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->orderBy('id')->get()->all();
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /**
     * F1 (expiry). CNIC's expiry is min(paid until, renewal date) and the sync may move ours back (expiryIsWhenItGoes). The
     * year renewed last (a line ending on the paid-until day) then «covers» the new, earlier expiry in
     * DomainRenewalService::billedLine (period_end > start): the next year is never billed, no reminder goes (it reads as
     * paid), nothing renews it, and nobody is told. CNIC deletes it on its renewal date (AUTOEXPIRE).
     */
    public function test_f1_an_expiry_moved_back_by_the_sync_is_never_billed_while_last_years_line_ends_after_it(): void
    {
        $cnic = $this->cnic();
        $domain = $this->domain(['registrar_connection_id' => $cnic->id, 'expires_at' => '2026-11-20']);
        $control = $this->domain(['registrar_connection_id' => $cnic->id, 'expires_at' => '2026-11-20', 'sld' => 'control', 'fqdn' => 'control.com']);
        // Last year's renewal, paid and done (renewed by hand at CNIC, or before CNIC had an API: D-10).
        $this->line($domain, '2025-11-20', '2026-11-20', Invoice::STATUS_PAID, true);
        // CNIC: paid until 2026-11-20, but its renewal date (when it deletes it) is 2026-10-30.
        $this->renewalDate = '2026-10-30';
        $this->artisan('domains:sync', ['--domain' => 'example.com'])->assertSuccessful();
        $this->artisan('domains:sync', ['--domain' => 'control.com'])->assertSuccessful();
        $this->assertSame('2026-10-30', $domain->fresh()->expires_at->toDateString(), 'precondition: the sync moved it back');

        $this->cron();

        $this->assertSame('2026-10-30', $this->renewalLines($control)[0]->period_start->toDateString() ?? null, 'control: one without last year\'s line is billed');

        $next = collect($this->renewalLines($domain))->first(fn (InvoiceItem $l) => $l->period_start->toDateString() === '2026-10-30');
        $this->assertNotNull($next, 'the year from its (new) expiry is billed 15 days before it: it goes on 2026-10-30');
    }

    /**
     * F2 (money/expiry). The stage fee is looked up on a renewal line whose period starts ON or AFTER the expiry
     * (RenewalBilling::addStageFee), while billedLine treats a line up to 31 days off as the same period. An expiry
     * corrected forward by a day after billing (sync drift, the case DomainBillingTest guards against double billing) then
     * finds no renewal: the grace fee is never added, silently. The customer pays the renewal only and the paid renewal
     * stops at «restore needed but its grace fee is not paid» while the domain runs down its grace.
     */
    public function test_f2_an_expiry_drifted_a_day_after_billing_never_gets_its_stage_fee(): void
    {
        $domain = $this->domain(['expires_at' => '2026-11-01']);
        $this->cron(); // billed 2026-11-01 → 2027-11-01
        $this->assertCount(1, $this->renewalLines($domain));
        $domain->update(['expires_at' => '2026-11-02']); // the registrar's day, one later (sync drift)

        Carbon::setTestNow('2026-11-10 10:00:00');
        $domain->fresh()->forceFill(['status' => DomainStatus::Grace])->save(); // by its dates (lifecycle)
        $this->cron();

        $this->assertCount(1, $this->renewalLines($domain), 'precondition: not billed again');
        $fee = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->where('kind', 'grace_fee')->first();
        $this->assertNotNull($fee, 'its grace fee lands on the open renewal invoice, as for an expiry not drifted');
    }

    /**
     * F3 (money). The same window the other way: CNIC's renewal date more than SAME_PERIOD_DAYS (31) before ours, read after
     * the year was invoiced (MAX_BACK_DAYS allows 400): billedLine no longer sees the open invoice, and a second one is made
     * for an overlapping year. Paid both, the second renews again at the registrar.
     */
    public function test_f3_an_expiry_moved_back_more_than_a_month_after_billing_is_billed_twice(): void
    {
        Carbon::setTestNow('2026-10-21 10:00:00');
        $domain = $this->domain(['registrar_connection_id' => $this->cnic()->id, 'expires_at' => '2026-12-01']);
        $this->line($domain, '2026-12-01', '2027-12-01', Invoice::STATUS_UNPAID, false); // billed, open
        $this->paidUntil = '2026-12-01';
        $this->renewalDate = '2026-10-25'; // goes there 37 days before ours
        $this->artisan('domains:sync', ['--domain' => 'example.com'])->assertSuccessful();
        // Fixed: not moved back past the open renewal's period; staff told (the sync's warning).
        $this->assertSame('2026-12-01', $domain->fresh()->expires_at->toDateString(), 'not moved back past an open renewal');
        $this->assertStringContainsString('not moved back', (string) $domain->fresh()->sync_error);

        $this->cron();

        $this->assertCount(1, $this->renewalLines($domain), 'one open renewal invoice for one year, never a second overlapping it');
    }
}
