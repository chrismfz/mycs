<?php

namespace Tests\Feature\Domains;

use App\Admin\Resources\ModuleActionResource\Pages\ListModuleActions;
use App\Enums\DomainStatus;
use App\Jobs\Domains\RenewDomainJob;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainSyncService;
use App\Services\Invoice\ProcessPaidInvoiceService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Mcp\Proposals;
use App\Services\Ops\Health;
use App\Services\Service\RetryModuleAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of D5b-2 (PR #170): a paid domain renewal done at its registrar, real money. Each finding is a
 * failing test (ADV-n); the «holds» tests pin what was attacked and stood.
 */
class DomainsD5b2AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $op;

    private User $user;

    /** @var array<string, mixed> the registrar's record */
    private array $record = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> the charges asked: [path, body] */
    private array $charges = [];

    /**
     * What each next charge does: ok | timeout (no answer, nothing shows) | refuse (400) | e502 (a 5xx after the registrar
     * took it: nothing shows yet) | lag (charged, the expiry shows late). Empty: ok.
     *
     * @var list<string>
     */
    private array $script = [];

    /** @var (callable(): void)|null run on each domain read */
    private $onRead = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        Http::preventStrayRequests();
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->user = User::factory()->create();
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeOp(array $record): void
    {
        $this->record = $record + ['id' => 30391502, 'domain' => ['name' => 'nixpal', 'extension' => 'com'],
            'name_servers' => [['name' => 'ns1.nixpal.com'], ['name' => 'ns2.nixpal.com']]];
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'POST' && (str_ends_with($path, '/renew') || str_ends_with($path, '/restore'))) {
                $this->charges[] = [$path, $request->data()];
                $how = array_shift($this->script) ?? 'ok';
                if ($how === 'timeout') {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }
                if ($how === 'refuse') {
                    return Http::response(['code' => 399, 'desc' => 'Insufficient funds'], 400);
                }
                if ($how === 'e502') {
                    return Http::response('<html>502 Bad Gateway</html>', 502);
                }
                if ($how === 'ok') {
                    $years = str_ends_with($path, '/restore') ? 1 : (int) $request->data()['period'];
                    $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears($years)->format('Y-m-d H:i:s');
                    $this->record['status'] = 'ACT';
                    unset($this->record['soft_quarantine_expiry_date'], $this->record['hard_quarantine_expiry_date']);
                }

                return Http::response(['data' => ['success' => true]]); // «lag»: charged, the record unchanged yet
            }
            if ($this->onRead !== null) {
                ($this->onRead)();
            }

            return Http::response(['data' => $this->record]);
        });
    }

    private function domain(array $attributes = []): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        foreach ([1 => '23.33', 2 => '46.66'] as $years => $price) {
            DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => $years, 'currency_code' => 'EUR'], ['price' => $price]);
        }

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active,
            'expires_at' => '2026-11-01', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function billAndPay(Domain $domain): InvoiceItem
    {
        Queue::fake();
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->latest('id')->firstOrFail();
        (new RecordPaymentService)->handle($line->invoice, null, (float) $line->invoice->fresh()->total, transactionId: 'tx-' . $line->id);

        return $line->fresh();
    }

    /** A second paid line for the same domain and period, on an invoice of its own (staff's, a duplicate). */
    private function paidDuplicate(InvoiceItem $line): InvoiceItem
    {
        $invoice = Invoice::query()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => today(), 'currency_code' => 'EUR']);
        $copy = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $line->reference_id, 'price' => '23.33', 'quantity' => 1,
            'description' => 'nixpal.com renewal', 'period_start' => $line->period_start, 'period_end' => $line->period_end]);
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->fresh()->total, transactionId: 'tx-dup-' . $copy->id);

        return $copy->fresh();
    }

    private function renew(InvoiceItem $line): string
    {
        return app(DomainRenewalService::class)->renewLine($line->id);
    }

    private function staff(array $permissions = ['*']): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
    }

    // ---------------------------------------------------------------- findings (fail on the PR's code)

    /**
     * ADV-1 (High): a 5xx from the registrar on a charge is an unknown outcome (the registrar may have taken it; a
     * gateway answered), but it is logged «failed» as a refusal, so the next Retry or cron run charges again (D-17).
     */
    public function test_adv1_a_5xx_on_a_charge_is_unknown_never_charged_again_blindly(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->script = ['e502'];

        $this->assertSame(DomainRenewalService::FAILED, $this->renew($line));
        $action = ModuleAction::query()->open()->where('domain_id', $domain->id)->sole();
        RetryModuleAction::run($action); // staff's Retry the same minute (or the cron's next run)

        $this->assertCount(1, $this->charges, 'a 502 after the charge was asked: never asked again blindly');
    }

    /**
     * ADV-2 (High): «Renew now» ignores a charge whose outcome is unknown (pending): a timeout shows staff «the registrar
     * did not accept it», they click again, and the registrar is charged a second time.
     */
    public function test_adv2_renew_now_after_a_timeout_never_charges_again_blindly(): void
    {
        $domain = $this->domain(['expires_at' => '2026-12-20']);
        $this->record['expiration_date'] = '2026-12-20 10:00:00';
        $staff = $this->staff();
        $this->script = ['timeout'];
        try {
            app(DomainRenewalService::class)->renewNow($domain, $staff);
            $this->fail('a timeout surfaces');
        } catch (Throwable) { // fix round: said to staff as «outcome unknown», not the raw exception
        }
        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, DomainRegistrarLog::query()->where('action', 'renew')->sole()->status);

        try {
            app(DomainRenewalService::class)->renewNow($domain->fresh(), $staff); // «did not accept it»: clicked again
        } catch (Throwable) {
        }

        $this->assertCount(1, $this->charges, 'a pending charge for this period: Renew now refuses, as the payment path does');
    }

    /** ADV-2b (High): the same through the paid line: its job timed out (pending), staff «Renew now» charges again. */
    public function test_adv2b_renew_now_on_a_paid_line_with_a_pending_charge_never_charges_again(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->script = ['timeout'];
        $this->assertSame(DomainRenewalService::FAILED, $this->renew($line));

        try {
            app(DomainRenewalService::class)->renewNow($domain->fresh(), $this->staff());
        } catch (Throwable) {
        }

        $this->assertCount(1, $this->charges);
    }

    /**
     * ADV-3 (Medium): «Renew now» takes a line on a REFUNDED invoice as «billed»: the registrar is charged and the
     * renewal is «on invoice #X, to be paid», an invoice that is final and can never be paid (billedOrBill only skips
     * cancelled ones).
     */
    public function test_adv3_renew_now_never_bills_onto_a_refunded_invoice(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain); // its job never ran
        Invoice::query()->whereKey($line->invoice_id)->update(['status' => Invoice::STATUS_REFUNDED]); // refunded before the job
        $this->assertSame(DomainRenewalService::NOTHING, $this->renew($line), 'holds: refunded, the job charges nothing');

        $renewed = app(DomainRenewalService::class)->renewNow($domain->fresh(), $this->staff());

        $this->assertCount(1, $this->charges);
        $this->assertNotSame(Invoice::STATUS_REFUNDED, $renewed->invoice->fresh()->status, 'the charge is billed on an invoice that can be paid');
    }

    /**
     * ADV-4 (Medium): «Mark resolved» on an API registrar moves the expiry to the end of the paid period without asking
     * the registrar, and the docblock's «corrected on its next sync» is false: the sync never moves an expiry back (D-9).
     * A refusal closed this way shows the domain renewed forever while it lapses at the registrar, its paid line applied.
     */
    public function test_adv4_mark_resolved_on_an_api_registrar_does_not_leave_a_false_expiry(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->script = ['refuse'];
        $this->renew($line);
        $action = ModuleAction::query()->open()->where('domain_id', $domain->id)->sole();

        try {
            $action->markResolved($this->staff()); // not renewed at the registrar (staff closed it, or the MCP proposal did)
        } catch (RuntimeException) { // fix round: refused, the registrar does not show it
        }
        app(DomainSyncService::class)->sync($domain->fresh());

        $this->assertSame('2026-11-01', $domain->fresh()->expires_at->toDateString(), 'the registrar still ends it on 2026-11-01');
    }

    /**
     * ADV-5 (Low): the paid status is read before the lock and the registrar read, never again before the charge: an
     * invoice refunded meanwhile is still charged at the registrar.
     */
    public function test_adv5_an_invoice_refunded_while_its_renewal_runs_is_not_charged(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->onRead = function () use ($line) {
            Invoice::query()->whereKey($line->invoice_id)->update(['status' => Invoice::STATUS_REFUNDED]);
        };

        $this->renew($line);

        $this->assertSame([], $this->charges, 'refunded before the charge: nothing asked');
    }

    /**
     * ADV-6 (Low): «Renew now» twice (two staff, a second click after the first finished) bills and charges a second year
     * while the first one's invoice is still unpaid: the modal promises one renewal of :years.
     */
    public function test_adv6_renew_now_twice_does_not_charge_two_years_before_any_payment(): void
    {
        $domain = $this->domain(['expires_at' => '2026-12-20']);
        $this->record['expiration_date'] = '2026-12-20 10:00:00';
        $staff = $this->staff();

        app(DomainRenewalService::class)->renewNow($domain, $staff);
        try {
            app(DomainRenewalService::class)->renewNow($domain->fresh(), $staff);
        } catch (Throwable) {
        }

        $this->assertCount(1, $this->charges);
    }

    /**
     * ADV-7 (Medium): a restore that went through and a renewal refused (the paid N years, ADV-11) are KNOWN outcomes, yet
     * the restore's «ok» log blocks the other years: staff's Retry does nothing for 7 days («Nothing to do»), then it is
     * «the registrar does not show it» forever. The customer paid 2 years and gets 1 unless staff renew by hand.
     */
    public function test_adv7_a_restore_done_and_a_renewal_refused_can_be_retried(): void
    {
        $domain = $this->domain(['years' => 2]);
        $line = $this->billAndPay($domain);
        $this->record = array_merge($this->record, ['status' => 'DEL', 'soft_quarantine_expiry_date' => '2026-12-11 10:00:00']);
        $this->script = ['ok', 'refuse'];
        $this->assertSame(DomainRenewalService::FAILED, $this->renew($line));
        $action = ModuleAction::query()->open()->where('domain_id', $domain->id)->sole();

        $this->assertTrue(RetryModuleAction::run($action), 'the cause fixed (funds), Retry renews the other year');
        $this->assertSame('2028-11-01', $domain->fresh()->expires_at->toDateString());
        $this->assertCount(3, $this->charges); // restore, the refused renew, the renew: never a second restore
    }

    /**
     * ADV-8 (Low): a paid renewal stuck at the registrar (refused, unknown) is never «given up», so the health check
     * (and the MCP `module_actions given_up_only`) says all is well while a customer's paid domain is not renewed.
     */
    public function test_adv8_health_warns_about_a_paid_domain_renewal_waiting_for_staff(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->script = ['refuse'];
        $this->renew($line);
        $this->assertSame(1, ModuleAction::query()->open()->count());

        $this->assertSame(Health::WARN, Health::report()['checks']['module_actions']['status']);
    }

    /**
     * ADV-9 (Low): a domain's renewal action is guarded by the services' permission: staff who may not touch domains
     * (no admin.domains.*) Retry a registrar charge or «Mark resolved» it (the line applied, the expiry moved).
     */
    public function test_adv9_a_domain_renewal_action_needs_a_domains_permission(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->script = ['refuse'];
        $this->renew($line);
        $action = ModuleAction::query()->open()->where('domain_id', $domain->id)->sole();
        $servicesOnly = $this->staff(['admin.services.viewAny', 'admin.services.update']);

        $this->assertFalse(Gate::forUser($servicesOnly)->allows('update', $action));
    }

    /**
     * ADV-10 (Low): the MCP Retry proposal refuses every domain renewal action («still being retried by the queue»: it
     * is never given up), and the resolve proposal describes it as «domain_renew on service # (?)».
     */
    public function test_adv10_the_mcp_retry_proposal_works_for_a_domain_renewal(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->script = ['refuse'];
        $this->renew($line);
        $action = ModuleAction::query()->open()->where('domain_id', $domain->id)->sole();

        $proposal = Proposals::propose(Proposals::RETRY_MODULE_ACTION, ['module_action_id' => $action->id], $this->staff());
        $this->assertStringContainsString('nixpal.com', $proposal->summary);
    }

    // ---------------------------------------------------------------- holds (attacked, stood)

    public function test_holds_job_cron_and_retry_in_turn_charge_once(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);

        $this->assertSame(DomainRenewalService::APPLIED, $this->renew($line)); // the job
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->artisan('app:cron-job')->assertExitCode(0); // the cron
        $this->assertSame(DomainRenewalService::NOTHING, $this->renew($line)); // a second job (apply re-run)
        (new ProcessPaidInvoiceService)->handle($line->invoice->fresh());

        $this->assertCount(1, $this->charges);
    }

    public function test_holds_the_registrar_lock_held_means_no_charge(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $lock = Cache::lock("domain-registrar:{$domain->id}", 600);
        $this->assertTrue($lock->get());

        $this->assertSame(DomainRenewalService::WAITING, $this->renew($line));
        $lock->release();
        $this->assertSame([], $this->charges);
    }

    public function test_holds_a_refunded_or_cancelled_invoice_after_the_job_was_queued_is_not_charged(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        Invoice::query()->whereKey($line->invoice_id)->update(['status' => Invoice::STATUS_REFUNDED]);
        $this->assertSame(DomainRenewalService::NOTHING, $this->renew($line));
        Invoice::query()->whereKey($line->invoice_id)->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->assertSame(DomainRenewalService::NOTHING, $this->renew($line));
        $this->assertSame([], $this->charges);
    }

    public function test_holds_an_unpaid_line_is_never_charged(): void
    {
        $domain = $this->domain();
        Queue::fake();
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->whereNull('kind')->sole();

        $this->assertSame(DomainRenewalService::NOTHING, $this->renew($line));
        $this->assertSame([], $this->charges);
    }

    public function test_holds_two_paid_lines_for_the_same_period_charge_once(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $dup = $this->paidDuplicate($line);

        $this->assertSame(DomainRenewalService::APPLIED, $this->renew($line));
        $this->assertSame(DomainRenewalService::ADOPTED, $this->renew($dup));
        $this->assertCount(1, $this->charges);
    }

    public function test_holds_a_lagging_registrar_on_the_same_invoice_is_not_charged_twice(): void
    {
        $domain = $this->domain(['expires_at' => '2026-12-20']);
        $this->record['expiration_date'] = '2026-12-20 10:00:00';
        $this->script = ['lag']; // charged, the new expiry not shown yet
        $line = app(DomainRenewalService::class)->renewNow($domain, $this->staff());
        Queue::fake();
        (new RecordPaymentService)->handle($line->invoice, null, (float) $line->invoice->fresh()->total, transactionId: 'tx-later');

        // Fix round: applied as charged («Renew now» answered OK), staff told the registrar lags; never charged again.
        $this->assertContains($this->renew($line->fresh()), [DomainRenewalService::WAITING, DomainRenewalService::APPLIED]);
        $this->assertCount(1, $this->charges);
    }

    public function test_holds_renew_now_then_the_cron_bills_nothing_again_for_that_period(): void
    {
        $domain = $this->domain(['expires_at' => '2026-11-01']);
        app(DomainRenewalService::class)->renewNow($domain, $this->staff());
        Queue::fake();
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame(1, InvoiceItem::query()->where('reference_type', Domain::class)->whereNull('kind')->count());
        $this->assertCount(1, $this->charges);
    }

    public function test_holds_a_payment_never_fails_on_a_registrar_that_does_not_answer(): void
    {
        $domain = $this->domain();
        Queue::fake();
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->whereNull('kind')->sole();
        $this->script = ['timeout'];

        (new RecordPaymentService)->handle($line->invoice, null, (float) $line->invoice->fresh()->total, transactionId: 'tx-sync');
        // The job run as the queue would run it (here, by hand): its failure is staff's, never the payment's.
        (new RenewDomainJob($line->id))->handle(app(DomainRenewalService::class));

        $this->assertSame(Invoice::STATUS_PAID, $line->invoice->fresh()->status);
        $this->assertSame(1, ModuleAction::query()->open()->where('domain_id', $domain->id)->count());
    }

    public function test_holds_secrets_never_reach_the_logs_or_the_actions(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->script = ['timeout'];
        $this->renew($line);

        $all = json_encode([DomainRegistrarLog::query()->get()->toArray(), ModuleAction::query()->get()->toArray()]);
        $this->assertStringNotContainsString('Op-Secret-Pa55', $all);
    }

    public function test_holds_the_pending_module_actions_list_shows_a_domain_row(): void
    {
        $domain = $this->domain();
        $line = $this->billAndPay($domain);
        $this->script = ['refuse'];
        $this->renew($line);
        $action = ModuleAction::query()->open()->sole();

        $this->livewireAs($this->staff())->test(ListModuleActions::class)->assertOk()->assertCanSeeTableRecords([$action])
            ->assertSee('nixpal.com');
    }

    public function test_holds_mark_resolved_on_a_manual_registrar_never_moves_the_expiry_back(): void
    {
        $manual = DomainRegistrarConnection::query()->create(['registrar' => 'manual', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $domain = $this->domain(['registrar_connection_id' => $manual->id]);
        $line = $this->billAndPay($domain);
        $this->renew($line);
        $domain->forceFill(['expires_at' => '2028-01-01'])->save(); // staff renewed further meanwhile

        ModuleAction::query()->open()->sole()->markResolved($this->staff());

        $this->assertSame('2028-01-01', $domain->fresh()->expires_at->toDateString());
        $this->assertNotNull($line->fresh()->applied_at);
    }
}
