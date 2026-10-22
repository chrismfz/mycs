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
use App\Services\Domains\DomainRenewalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #310 (which line is «this period's renewal» once the expiry moves). test_adv<n>_... fail on the
 * PR as it is (a finding each); test_guard_... pass and keep what the PR fixed.
 */
class Pr310AdversarialTest extends TestCase
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
            $renewal = $this->renewalDate !== null ? "property[RENEWAL DATE][0] = {$this->renewalDate} 00:00:00.0\n" : '';

            return Http::response("[RESPONSE]\ncode = 200\ndescription = OK\nproperty[PAIDUNTIL DATE][0] = {$this->paidUntil} 00:00:00.0\n{$renewal}"
                . "property[STATUS][0] = clientTransferProhibited\nproperty[TRANSFER LOCK][0] = 1\nproperty[NAMESERVER][0] = ns1.example.net\nEOF\n");
        });
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15,
            'settings.cronjob_invoice' => 7, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'grace_days' => 40, 'redemption_days' => 0]);
        foreach ([['renew', 1, '23.33'], ['renew', 2, '46.66'], ['grace', 1, '100.30']] as [$operation, $years, $price]) {
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

    /** A renewal line of the domain on an invoice of its own of $status, for [$start, $end). */
    private function line(Domain $domain, string $start, string $end, string $status, bool $applied, string $price = '23.33'): InvoiceItem
    {
        $invoice = Invoice::query()->create(['client_id' => $domain->client_id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => $start, 'currency_code' => 'EUR']);
        $line = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => $price, 'quantity' => 1,
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
     * ADV-1 (High, money: the customer billed twice; a regression of L1). A two-year renewal, paid and renewed (applied),
     * 2026-11-20 → 2028-11-20. CNIC's expiry is min(paid until, renewal date), and the sync moves ours back up to
     * MAX_BACK_DAYS (400): a renewal date a year inside the paid period (CNIC renewing yearly at the registry what was paid
     * for two, or any expiry moved inside a multi-year period) sets ours to 2027-11-01. Before #310 the applied line covered
     * it (period_end > start); now an applied line counts only if it STARTS within 31 days of the expiry, so the cron bills
     * 2027-11-01 → 2028-11-01 again, a year the customer has paid. Paid, it is adopted at CNIC (paid until covers it): the
     * money is simply taken twice. Reminders, «Renew now» and the portal read the same rule (renew/unpaid for a paid year).
     * Fix: an applied line counts while its period reaches more than SAME_PERIOD_DAYS past the expiry (it is last year's
     * only when it ends within that window), not by where it starts.
     */
    public function test_adv1_an_applied_two_year_period_is_billed_again_when_the_expiry_moves_inside_it(): void
    {
        Carbon::setTestNow('2027-10-20 10:00:00');
        $domain = $this->domain(['registrar_connection_id' => $this->cnic()->id, 'expires_at' => '2028-11-20', 'years' => 2]);
        $this->line($domain, '2026-11-20', '2028-11-20', Invoice::STATUS_PAID, true, '46.66'); // two years, paid and renewed
        $this->paidUntil = '2028-11-20';
        $this->renewalDate = '2027-11-01';
        $this->artisan('domains:sync', ['--domain' => 'example.com'])->assertSuccessful();
        $this->assertSame('2027-11-01', $domain->fresh()->expires_at->toDateString(), 'precondition: CNIC\'s renewal date, inside the paid period');

        $this->cron();

        $this->assertCount(1, $this->renewalLines($domain), 'a year inside a paid and renewed period is never invoiced again');
        $this->assertNotNull(DomainRenewalService::billedLine($domain->fresh(), Carbon::parse('2027-11-01'), payable: true),
            'its paid period is «this period\'s renewal» for reminders, «Renew now» and the portal (paid, nothing to ask)');
    }

    /**
     * ADV-2 (Medium, expiry: a silent lapse; a regression of L3). The guard refuses a move back past ANY open renewal that
     * starts more than a month after CNIC's date, not only the one billed for the current period. Billed: this year
     * 2026-12-01 → 2027-12-01 and, ahead («Invoice next period»), 2027-12-01 → 2028-12-01. CNIC's renewal date is
     * 2026-11-20, eleven days back, inside the period billed (billedLine still finds this year's line from it). The year
     * ahead blocks the move: ours stays 2026-12-01 while CNIC deletes the name on 2026-11-20 (AUTOEXPIRE); reminders and the
     * invoice's due date say 2026-12-01, a customer paying on the 25th pays for a name already gone.
     * Fix: refuse only when the move takes the expiry off the period billed (billedLine from ours finds a line, from theirs
     * none), or check only the line covering our expiry.
     */
    public function test_adv2_a_small_move_back_is_refused_because_a_later_period_is_billed_ahead(): void
    {
        $domain = $this->domain(['registrar_connection_id' => $this->cnic()->id, 'expires_at' => '2026-12-01']);
        $this->line($domain, '2026-12-01', '2027-12-01', Invoice::STATUS_UNPAID, false); // this year, open
        $this->line($domain, '2027-12-01', '2028-12-01', Invoice::STATUS_UNPAID, false); // next year, billed ahead
        $this->paidUntil = '2026-12-01';
        $this->renewalDate = '2026-11-20'; // eleven days before ours
        $this->artisan('domains:sync', ['--domain' => 'example.com'])->assertSuccessful();

        $this->assertSame('2026-11-20', $domain->fresh()->expires_at->toDateString(), 'the day CNIC deletes it, within the period billed');
        $this->assertStringNotContainsString('not moved back', (string) $domain->fresh()->sync_error);
    }

    /**
     * ADV-3 (Medium, money: the fee on the wrong invoice; pre-existing, the PR's «same rule as billing» is not). The stage
     * fee's lookup has the new lower bound but no upper one (billedLine's period_start <= expiry + 31), and takes the newest
     * line: with next year billed ahead, the grace fee lands on 2027-11-01 → 2028-11-01, not on the open renewal of the
     * period in grace. The customer pays the renewal the reminders name, the fee is unpaid, and the paid renewal stops at
     * «a restore is needed but its grace fee is not paid» while the grace runs down.
     * Fix: the same window as billedLine (period_start <= expiry + SAME_PERIOD_DAYS), or billedLine itself.
     */
    public function test_adv3_the_grace_fee_lands_on_a_later_period_billed_ahead(): void
    {
        $domain = $this->domain(['expires_at' => '2026-11-01']);
        $this->cron(); // billed 2026-11-01 → 2027-11-01
        $this->assertCount(1, $this->renewalLines($domain));
        $current = $this->renewalLines($domain)[0];
        $this->line($domain, '2027-11-01', '2028-11-01', Invoice::STATUS_UNPAID, false); // «Invoice next period», ahead

        Carbon::setTestNow('2026-11-10 10:00:00');
        $domain->fresh()->forceFill(['status' => DomainStatus::Grace])->save(); // by its dates (lifecycle)
        $this->cron();

        $fee = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->where('kind', 'grace_fee')->first();
        $this->assertNotNull($fee, 'precondition: a grace fee is billed');
        $this->assertSame((int) $current->invoice_id, (int) $fee->invoice_id, 'the grace fee goes with the renewal of the period in grace');
    }

    /** Guard (L1 at the end of a two-year period): CNIC's renewal date weeks before its paid-until, the next period is billed. */
    public function test_guard_the_end_of_an_applied_two_year_period_moved_back_is_billed(): void
    {
        Carbon::setTestNow('2028-10-20 10:00:00');
        $domain = $this->domain(['registrar_connection_id' => $this->cnic()->id, 'expires_at' => '2028-11-20', 'years' => 2]);
        $this->line($domain, '2026-11-20', '2028-11-20', Invoice::STATUS_PAID, true, '46.66');
        $this->paidUntil = '2028-11-20';
        $this->renewalDate = '2028-10-30';
        $this->artisan('domains:sync', ['--domain' => 'example.com'])->assertSuccessful();
        $this->assertSame('2028-10-30', $domain->fresh()->expires_at->toDateString());

        $this->cron();

        $next = collect($this->renewalLines($domain))->first(fn (InvoiceItem $l) => $l->period_start->toDateString() === '2028-10-30');
        $this->assertNotNull($next, 'the period from its new expiry is billed');
    }

    /** Guard (L3): a paid renewal not applied yet holds the move back as an unpaid one does. */
    public function test_guard_a_paid_waiting_renewal_holds_a_move_back_past_its_start(): void
    {
        $domain = $this->domain(['registrar_connection_id' => $this->cnic()->id, 'expires_at' => '2026-12-01']);
        $this->line($domain, '2026-12-01', '2027-12-01', Invoice::STATUS_PAID, false);
        $this->paidUntil = '2026-12-01';
        $this->renewalDate = '2026-10-25';
        $this->artisan('domains:sync', ['--domain' => 'example.com'])->assertSuccessful();

        $this->assertSame('2026-12-01', $domain->fresh()->expires_at->toDateString());
        $this->assertStringContainsString('not moved back', (string) $domain->fresh()->sync_error);
    }

    /** Guard (L2): an expiry drifted forward within the window still gets its fee on the open renewal, once. */
    public function test_guard_a_drifted_expiry_gets_its_grace_fee_once(): void
    {
        $domain = $this->domain(['expires_at' => '2026-11-01']);
        $this->cron();
        $current = $this->renewalLines($domain)[0];
        $domain->update(['expires_at' => '2026-11-20']); // 19 days later, by a sync

        Carbon::setTestNow('2026-11-25 10:00:00');
        $domain->fresh()->forceFill(['status' => DomainStatus::Grace])->save();
        $this->cron();
        $this->cron();

        $fees = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->where('kind', 'grace_fee')->get();
        $this->assertCount(1, $fees);
        $this->assertSame((int) $current->invoice_id, (int) $fees[0]->invoice_id);
        $this->assertCount(1, $this->renewalLines($domain), 'not billed again');
    }
}
