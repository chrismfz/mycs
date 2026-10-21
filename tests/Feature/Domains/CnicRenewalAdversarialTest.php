<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\RegistrarRefused;
use App\Services\Domains\Registrars\CnicRegistrar;
use App\Services\Invoice\RecordPaymentService;
use App\Support\Domains\DomainRegistrarCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #302 (CentralNic Reseller, step 3: a paid renewal renewed at CNIC). Written without the author's
 * context. Never the real API: Http::fake only.
 *
 * Each test names its finding (CNIC-RENEW-ADV-n). FAILS = a problem on feat/cnic-renew (acdab7c2); GUARD = passes and
 * stays as a guard.
 */
class CnicRenewalAdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $sent = [];

    /** CNIC's PAIDUNTIL DATE (null: not in the answer). */
    private ?string $paidUntil = '2026-11-01';

    /** CNIC's RENEWAL DATE (null: not in the answer). */
    private ?string $renewalDate = null;

    private string $status = 'clientTransferProhibited';

    /** RenewDomain's answer: null = done (200, paid-until moves by PERIOD); an int = that HTTP status; a string = that body. */
    private string|int|null $renewAnswer = null;

    /** Done there even though the answer above says otherwise (a 504 of its front after CNIC did it). */
    private bool $doneAnyway = false;

    /** A StatusDomain after a RenewDomain fails with this HTTP status (null: answers). */
    private ?int $readAfterRenewFails = null;

    private bool $renewed = false;

    private bool $timeout = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            parse_str($request->body(), $form);
            $command = (string) ($form['s_command'] ?? '');
            $this->sent[] = $command;
            if (str_starts_with($command, 'COMMAND=RenewDomain')) {
                preg_match('/PERIOD=(\d+)/', $command, $m);
                $years = (int) ($m[1] ?? 1);
                $done = fn () => $this->paidUntil = Carbon::parse($this->paidUntil)->addYearsNoOverflow($years)->toDateString();
                if ($this->timeout) {
                    $done(); // sent, done there, its answer never came back

                    throw new ConnectionException('cURL error 28: Operation timed out');
                }
                if ($this->renewAnswer === null || $this->doneAnyway) {
                    $done();
                    if ($this->renewalDate !== null) {
                        $this->renewalDate = Carbon::parse($this->renewalDate)->addYearsNoOverflow($years)->toDateString();
                    }
                }
                $this->renewed = true;
                if ($this->renewAnswer === null) {
                    return Http::response("[RESPONSE]\ncode = 200\ndescription = Command completed successfully\nEOF\n");
                }

                return is_int($this->renewAnswer) ? Http::response('<html>Gateway</html>', $this->renewAnswer) : Http::response($this->renewAnswer);
            }
            if ($this->renewed && $this->readAfterRenewFails !== null && str_starts_with($command, 'COMMAND=StatusDomain')) {
                return Http::response('<html>Bad gateway</html>', $this->readAfterRenewFails);
            }
            $dates = ($this->paidUntil !== null ? "property[PAIDUNTIL DATE][0] = {$this->paidUntil} 00:00:00.0\nproperty[REGISTRATION EXPIRATION DATE][0] = {$this->paidUntil} 00:00:00.0\n" : '')
                . ($this->renewalDate !== null ? "property[RENEWAL DATE][0] = {$this->renewalDate} 00:00:00.0\n" : '');

            return Http::response("[RESPONSE]\ncode = 200\ndescription = OK\n{$dates}"
                . "property[STATUS][0] = {$this->status}\nproperty[TRANSFER LOCK][0] = 1\nproperty[NAMESERVER][0] = ns1.example.net\nEOF\n");
        });
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Domain, 1: InvoiceItem} */
    private function paidRenewal(int $years = 1): array
    {
        $cnic = DomainRegistrarConnection::query()->create(['registrar' => 'cnic', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $cnic->storeCredentials(['username' => 'reseller', 'password' => 'Cnic-Secret-1'], ['password']);
        $tld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'grace_days' => 40, 'redemption_days' => 30]);
        foreach ([1, 2] as $y) {
            DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => $y, 'currency_code' => 'EUR', 'price' => bcmul('23.33', (string) $y, 2)]);
        }
        $domain = Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $cnic->id, 'status' => DomainStatus::Active, 'expires_at' => $this->paidUntil,
            'currency_code' => 'EUR', 'auto_renew' => true, 'years' => $years]);

        return [$domain, $this->billAndPay($domain)];
    }

    private function billAndPay(Domain $domain): InvoiceItem
    {
        $before = InvoiceItem::query()->max('id') ?? 0;
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->where('id', '>', $before)->latest('id')->firstOrFail();
        (new RecordPaymentService)->handle($line->invoice, null, (float) $line->invoice->fresh()->total, transactionId: 'tx-' . $line->id);

        return $line->fresh();
    }

    private function renewals(): int
    {
        return collect($this->sent)->filter(fn ($c) => str_starts_with($c, 'COMMAND=RenewDomain'))->count();
    }

    private function renew(InvoiceItem $line): string
    {
        return app(DomainRenewalService::class)->renewLine($line->id);
    }

    /**
     * CNIC-RENEW-ADV-1 (HIGH) — FAILS. A RenewDomain whose answer was a 504 of CNIC's front, but which CNIC did: the next
     * run reads CNIC, finds the period and adopts it (right). Its «pending» renew log is left pending forever, though the
     * outcome is now known (done). Every later renewal of that domain then meets unconfirmed() first: see ADV-1b.
     */
    public function test_adv1_a_pending_renewal_found_done_there_is_settled_not_left_pending(): void
    {
        [, $line] = $this->paidRenewal();
        $this->renewAnswer = 504;
        $this->doneAnyway = true;

        $this->assertSame(DomainRenewalService::FAILED, $this->renew($line));
        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('action', 'renew')->sole()->status);

        $this->assertSame(DomainRenewalService::ADOPTED, $this->renew($line), 'CNIC shows the year: adopted');
        $this->assertSame(1, $this->renewals());
        $this->assertSame(0, DomainRegistrarLog::query()->where('action', 'renew')->where('status', DomainRegistrarLog::STATUS_PENDING)->count(),
            'the unknown charge is known now (done): its log must not stay «pending»');
    }

    /**
     * CNIC-RENEW-ADV-1b (HIGH) — FAILS. The consequence of ADV-1: a year later the next paid renewal of the same domain is
     * never renewed: renewLine() sees the year-old pending log (older than PENDING_HOLD_DAYS) and sends it to staff with
     * «a renew asked on 2026-10-21 had no clear answer and the registrar does not show it» (false: it showed it, and was
     * adopted). The same for every year after, and «Renew now» refuses (domains.renew.unconfirmed).
     */
    public function test_adv1b_next_years_renewal_is_not_blocked_by_last_years_adopted_timeout(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->renewAnswer = 504;
        $this->doneAnyway = true;
        $this->renew($line);
        $this->assertSame(DomainRenewalService::ADOPTED, $this->renew($line));
        $this->assertSame('2027-11-01', $domain->fresh()->expires_at->toDateString());

        Carbon::setTestNow('2027-10-21 10:00:00');
        $this->renewAnswer = null;
        $this->doneAnyway = false;
        $next = $this->billAndPay($domain->fresh());
        $this->assertSame('2027-11-01', $next->period_start->toDateString());

        $outcome = $this->renew($next);
        $this->assertSame(DomainRenewalService::APPLIED, $outcome, 'next year is renewed as any other; staff were told: '
            . ModuleAction::query()->where('invoice_item_id', $next->id)->value('last_error'));
        $this->assertSame(2, $this->renewals());
    }

    /**
     * CNIC-RENEW-ADV-2 (MEDIUM) — FAILS. CNIC's expiry is min(PAIDUNTIL, RENEWAL DATE). With AUTOEXPIRE on a TLD whose
     * renewal date is weeks before the paid-until (CnicRegistrar::expiry's own comment), or after an import whose expiry was
     * the registry's, a period CNIC has already been PAID for (staff renewed it by hand, or its AUTORENEW) is not «covered»:
     * the adopt-before-charge check misses it and RenewDomain charges the reseller a second year.
     */
    public function test_adv2_a_period_paid_there_is_never_charged_again_whatever_its_renewal_date(): void
    {
        [, $line] = $this->paidRenewal();
        $this->assertSame('2027-11-01', $line->period_end->toDateString());
        // Renewed there already (by hand), its renewal date (AUTOEXPIRE) a month before.
        $this->paidUntil = '2027-11-01';
        $this->renewalDate = '2027-10-01';

        $this->renew($line);

        $this->assertSame(0, $this->renewals(), 'CNIC is paid until the end of the period: it must not be charged again');
    }

    /**
     * CNIC-RENEW-ADV-3 (MEDIUM) — FAILS. An answer of StatusDomain without any date (expiry unknown: CnicRegistrar never
     * guesses) still has a status, so the domain is «Active»: covers() cannot be checked, lacking() defaults to 1 and a
     * RenewDomain is sent blind: the period may well be paid there already (the adopt-before-charge check is skipped).
     */
    public function test_adv3_no_expiry_read_means_no_blind_charge(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->paidUntil = null; // CNIC answers, without a date

        $this->renew($line);

        $this->assertSame(0, $this->renewals(), 'its expiry unknown: staff, never a blind RenewDomain');
        $this->assertNull($line->fresh()->applied_at);
        $this->assertSame(ModuleAction::STATUS_OPEN, ModuleAction::query()->where('domain_id', $domain->id)->sole()->status);
    }

    /**
     * CNIC-RENEW-ADV-3b (MEDIUM) — FAILS. The same, for a two-year line: one year is renewed (lacking() = 1 without an
     * expiry), and the line is applied: the customer paid two years and the second is never asked of CNIC.
     */
    public function test_adv3b_a_two_year_line_is_never_renewed_for_one_on_an_unknown_expiry(): void
    {
        [, $line] = $this->paidRenewal(years: 2);
        $this->assertSame('2028-11-01', $line->period_end->toDateString());
        $this->paidUntil = null;

        $this->renew($line);

        $periods = collect($this->sent)->filter(fn ($c) => str_starts_with($c, 'COMMAND=RenewDomain'))->map(fn ($c) => preg_match('/PERIOD=(\d+)/', $c, $m) ? (int) $m[1] : 0)->values()->all();
        $this->assertNotSame([1], $periods, 'a two-year line was renewed for one year');
    }

    /**
     * CNIC-RENEW-ADV-4 (LOW) — FAILS. A 5xx is «refused: the same would be refused again» (and the commit says «5xx to
     * staff»), yet the next run (the daily cron, a Retry) sends RenewDomain again, and logs another «failed» renewal each
     * day while staff deal with it (by hand at CNIC: a race between staff's renewal and the cron's read-then-charge).
     * Before #302 the guard adv12 of #297 asserted a second run sent nothing; #302 dropped that second run.
     */
    public function test_adv4_a_refusal_is_not_sent_again_by_the_next_run(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->renewAnswer = "[RESPONSE]\ncode = 549\ndescription = Command failed; insufficient funds\nEOF\n";

        $this->assertSame(DomainRenewalService::FAILED, $this->renew($line));
        $this->renew($line); // the next day's cron

        $this->assertSame(1, $this->renewals(), 'refused: staff\'s, never asked again on its own');
        $this->assertSame(1, DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('action', 'renew')->count());
    }

    /** GUARD. Renewed (200), the read back fails (502): applied once, logged OK for its year, never asked again. */
    public function test_guard_a_renewal_done_whose_read_back_fails_is_applied_once(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->readAfterRenewFails = 502;

        $this->assertSame(DomainRenewalService::APPLIED, $this->renew($line));
        $this->assertSame(DomainRenewalService::NOTHING, $this->renew($line));
        $this->assertSame(1, $this->renewals());
        $log = DomainRegistrarLog::query()->where('action', 'renew')->sole();
        $this->assertSame([DomainRegistrarLog::STATUS_OK, 1], [$log->status, $log->request['years']]);
        $this->assertNotNull($line->fresh()->applied_at);
        $this->assertSame('2026-11-01', $domain->fresh()->expires_at->toDateString(), 'never a guessed expiry');
    }

    /** GUARD. A timeout (no answer at all) after it was sent: pending, never sent again. */
    public function test_guard_a_timeout_is_pending_never_sent_again(): void
    {
        [, $line] = $this->paidRenewal();
        $this->timeout = true;
        $this->paidUntil = '2026-11-01';

        $this->assertSame(DomainRenewalService::FAILED, $this->renew($line));
        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('action', 'renew')->sole()->status);
        $this->paidUntil = '2026-11-01'; // CNIC does not show it (yet)
        $this->timeout = false;
        $this->assertSame(DomainRenewalService::WAITING, $this->renew($line));
        $this->assertSame(1, $this->renewals());
    }

    /** GUARD. A 200 whose body has no code (a proxy's page): maybe done, pending. */
    public function test_guard_a_200_without_a_code_is_pending(): void
    {
        [, $line] = $this->paidRenewal();
        $this->renewAnswer = '<html>Maintenance</html>';

        $this->assertSame(DomainRenewalService::FAILED, $this->renew($line));
        $this->assertNull($line->fresh()->applied_at);
        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('action', 'renew')->sole()->status);
        $this->renewAnswer = null;
        $this->renew($line);
        $this->assertSame(1, $this->renewals());
    }

    /** GUARD. A 4xx (try later): nothing done, logged failed, the next run renews once. */
    public function test_guard_a_4xx_is_retried_and_renewed_once(): void
    {
        [, $line] = $this->paidRenewal();
        $this->renewAnswer = "[RESPONSE]\ncode = 421\ndescription = Command failed due to server error. Client should try again\nEOF\n";

        $this->assertSame(DomainRenewalService::FAILED, $this->renew($line));
        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, DomainRegistrarLog::query()->where('action', 'renew')->sole()->status);
        $this->renewAnswer = null;
        $this->assertSame(DomainRenewalService::APPLIED, $this->renew($line));
        $this->assertSame(DomainRenewalService::NOTHING, $this->renew($line));
        $this->assertSame(2, $this->renewals());
        $this->assertSame('2027-11-01', $this->paidUntil, 'one year charged');
    }

    /** GUARD. Two years paid: PERIOD=2; one of them there already: PERIOD=1. */
    public function test_guard_multi_year_lines_renew_the_years_missing(): void
    {
        [$domain, $line] = $this->paidRenewal(years: 2);
        $this->assertSame(DomainRenewalService::APPLIED, $this->renew($line));
        $this->assertStringContainsString("DOMAIN=example.com\nPERIOD=2", collect($this->sent)->first(fn ($c) => str_starts_with($c, 'COMMAND=RenewDomain')));
        $this->assertSame('2028-11-01', $domain->fresh()->expires_at->toDateString());
    }

    public function test_guard_a_two_year_line_with_one_year_there_renews_one(): void
    {
        [, $line] = $this->paidRenewal(years: 2);
        $this->paidUntil = '2027-11-01'; // its AUTORENEW took one year

        $this->assertSame(DomainRenewalService::APPLIED, $this->renew($line));
        $this->assertStringContainsString('PERIOD=1', collect($this->sent)->first(fn ($c) => str_starts_with($c, 'COMMAND=RenewDomain')));
        $this->assertSame('2028-11-01', $this->paidUntil);
    }

    /** GUARD. In redemption at CNIC: staff, never a RenewDomain. */
    public function test_guard_redemption_is_staffs(): void
    {
        [, $line] = $this->paidRenewal();
        $this->status = 'redemptionPeriod';

        $this->assertSame(DomainRenewalService::FAILED, $this->renew($line));
        $this->assertSame(0, $this->renewals());
    }

    /** GUARD. Another run holding the domain's lock: nothing sent. */
    public function test_guard_the_lock_held_means_nothing_sent(): void
    {
        [$domain, $line] = $this->paidRenewal();
        $this->assertTrue(Cache::lock("domain-registrar:{$domain->id}", 600)->get());

        $this->assertSame(DomainRenewalService::WAITING, $this->renew($line));
        $this->assertSame(0, $this->renewals());
    }

    /** GUARD. PERIOD bounds: 0 or 11 years never reach CNIC. */
    public function test_guard_period_bounds_never_reach_cnic(): void
    {
        $domain = Domain::factory()->make(['fqdn' => 'example.com']);
        $credentials = new DomainRegistrarCredentials(['username' => 'reseller', 'password' => 'x'], sandbox: false);
        foreach ([0, 11, -1] as $years) {
            try {
                (new CnicRegistrar)->renew($domain, $years, $credentials);
                $this->fail("{$years} years was asked");
            } catch (RegistrarRefused) {
            }
        }
        $this->assertSame([], $this->sent);
    }
}
