<?php

namespace Tests\Feature\Domains;

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
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * Adversarial review of #306 (which unconfirmed charges a click or an adoption settles; a renewal closed «not renewed»
 * charged only by staff's Retry). Every test_adv<n> FAILS on the PR's head and shows one defect; test_guard_* pass.
 */
class Pr306AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $op;

    private User $user;

    /** @var array<string, mixed> the registrar's record of the domain */
    private array $record = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> renew/restore POSTs */
    private array $charges = [];

    /** @var list<string> what each next charge does: ok | timeout | refuse */
    private array $script = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
        config(['audit.console' => true, 'settings.default_currency' => 'EUR', 'settings.domain_billing_enabled' => true,
            'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $tld = DomainTld::query()->create(['tld' => 'com', 'is_active' => true, 'sort' => 1, 'registrar_connection_id' => $this->op->id,
            'epp_code_required' => true, 'grace_days' => 40, 'redemption_days' => 0]);
        foreach ([['register', 1, '23.33'], ['renew', 1, '23.33'], ['renew', 2, '46.66']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
        $this->user = User::factory()->create();
        $this->record = ['id' => 30391502, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => 'ACT',
            'expiration_date' => '2026-11-01 10:00:00', 'name_servers' => [['name' => 'ns1.example.net'], ['name' => 'ns2.example.net']]];
        Http::fake(fn (Request $request) => $this->registrar($request));
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function registrar(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        if (str_contains($request->url(), '/auth/login')) {
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
            $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears((int) ($request->data()['period'] ?? 1))->format('Y-m-d H:i:s');

            return Http::response(['code' => 0, 'data' => ['success' => true]]);
        }
        if ($request->method() === 'GET' && str_contains($path, '/v1/domains')) {
            return Http::response(['data' => ['results' => [$this->record], 'total' => 1] + $this->record]);
        }

        return Http::response([], 404);
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => DomainTld::query()->where('tld', 'com')->value('id'), 'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502',
            'status' => DomainStatus::Active, 'expires_at' => '2026-11-01', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    /** A renewal line of the domain for [start, end), on an invoice of its own: paid, or left with $status. */
    private function line(Domain $domain, string $start, string $end, ?string $status = null): InvoiceItem
    {
        $invoice = Invoice::query()->create(['client_id' => $domain->client_id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => today(), 'currency_code' => 'EUR']);
        $line = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => '23.33', 'quantity' => 1,
            'description' => 'example.com renewal', 'period_start' => $start, 'period_end' => $end]);
        if ($status === null) {
            (new RecordPaymentService)->handle($invoice, null, (float) $invoice->fresh()->total, transactionId: 'tx-' . $line->id);
        } else {
            DB::table('invoices')->where('id', $invoice->id)->update(['status' => $status]); // as it ends up (refunded, cancelled)
        }

        return $line->fresh();
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /** An unconfirmed charge for $line, asked $daysAgo days ago (its answer never came). */
    private function pendingCharge(Domain $domain, InvoiceItem $line, int $daysAgo): DomainRegistrarLog
    {
        $log = DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $this->op->id, 'invoice_id' => $line->invoice_id,
            'action' => 'renew', 'status' => DomainRegistrarLog::STATUS_PENDING, 'error' => 'cURL error 28: Operation timed out',
            'request' => ['years' => 1, 'target_expiry' => $line->period_end->toDateString(), 'line' => $line->id]]);
        DB::table('domain_registrar_logs')->where('id', $log->id)->update(['created_at' => now()->subDays($daysAgo)]);

        return $log->fresh();
    }

    /**
     * ADV-1 (money/stuck, regression). Line X (2026 → 2027) was charged and the job died in the call (RenewDomainJob's
     * timeout is 240 s: no catch ran, so its log is «pending» and no row was opened); its invoice was then refunded, so X
     * is no longer a paid renewal and no row is ever opened for it. The period is billed again and paid (line Y). The cron
     * finds X's charge unconfirmed for over 7 days and opens Y's row: «A renew asked on … had no clear answer … check the
     * registrar». Staff check: not done; they «Close (not renewed)» Y's row (the modal: «its unconfirmed charge was not
     * done»), then Retry it. #306 settles only Y's own logs, so X's charge stays pending and blocks every charge of the
     * domain forever: Retry, «Renew now» and next year's line all stop on it, and no button settles it (before #306 the
     * Close did). A paid domain that can never be renewed through MyCS.
     */
    public function test_adv1_close_on_the_blocked_row_never_settles_the_charge_that_blocks_it(): void
    {
        $domain = $this->domain();
        $x = $this->line($domain, '2026-11-01', '2027-11-01', Invoice::STATUS_REFUNDED);
        $this->pendingCharge($domain, $x, 10);
        $y = $this->line($domain, '2026-11-01', '2027-11-01');

        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($y->id));
        $row = ModuleAction::query()->open()->where('invoice_item_id', $y->id)->sole();
        $this->assertStringContainsString('had no clear answer', (string) $row->last_error);
        $row->closeUnrenewed($staff = $this->staff()); // checked at the registrar: not done
        $outcome = app(DomainRenewalService::class)->renewLine($y->id, $staff); // its Retry

        $this->assertSame(DomainRenewalService::APPLIED, $outcome,
            "Closed «not renewed» after checking the registrar, then Retry: still blocked by line #{$x->id}'s charge, which nothing can settle any more");
        $this->assertCount(1, $this->charges);
    }

    /**
     * ADV-2 (money/stuck). The same dead end reached through «Renewed by hand» on a manual connection: X's charge got no
     * answer (pending), its connection was then switched off (manual) and staff renewed it by hand at the registrar
     * («Renewed by hand»: the manual path applies the line but never settles its pending charge). A year later, the
     * connection back on, line Y (2027 → 2028) is paid: X's pending charge blocks it, and Close + Retry on Y's row (all
     * #306 leaves staff) never clear it.
     */
    public function test_adv2_renewed_by_hand_on_a_manual_connection_leaves_a_charge_that_blocks_the_domain_for_good(): void
    {
        $domain = $this->domain();
        $x = $this->line($domain, '2026-11-01', '2027-11-01');
        $this->script = ['timeout'];
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($x->id));
        $this->op->forceFill(['is_active' => false])->save();
        $staff = $this->staff();
        ModuleAction::query()->open()->where('invoice_item_id', $x->id)->sole()->markResolved($staff); // «Renewed by hand»
        $this->assertNotNull($x->fresh()->applied_at);
        $this->record['expiration_date'] = '2027-11-01 10:00:00'; // renewed by hand there

        Carbon::setTestNow('2027-10-20 10:00:00');
        $this->op->forceFill(['is_active' => true])->save();
        $y = $this->line($domain, '2027-11-01', '2028-11-01');
        app(DomainRenewalService::class)->renewLine($y->id);
        ModuleAction::query()->open()->where('invoice_item_id', $y->id)->first()?->closeUnrenewed($staff);
        $outcome = app(DomainRenewalService::class)->renewLine($y->id, $staff);

        $why = (string) ModuleAction::query()->open()->where('invoice_item_id', $y->id)->value('last_error');
        // Fixed: «Renewed by hand» settled X's charge, so Y is renewed on its first run (the Close and Retry find nothing left).
        $this->assertContains($outcome, [DomainRenewalService::APPLIED, DomainRenewalService::NOTHING]);
        $this->assertNotNull($y->fresh()->applied_at, "Line #{$y->id} paid, never renewed: blocked by line #{$x->id}'s charge, applied by hand a year ago ({$why})");
    }

    /**
     * ADV-3 (money, race). renewLine() reads closedUnrenewed() before it takes the domain's lock, and renewLocked() never
     * reads it again. Its row open («the registrar could not be read»), staff «Close (not renewed)» it (the customer let
     * it go; refunded by hand later) while the cron (or the payment's RenewDomainJob) is between that read and the lock:
     * the close takes the free lock and returns, the cron takes it next and charges the year staff just closed.
     */
    public function test_adv3_close_between_the_crons_check_and_its_lock_is_charged_anyway(): void
    {
        $domain = $this->domain();
        $line = $this->line($domain, '2026-11-01', '2027-11-01');
        $row = ModuleAction::domainFailed($domain, $line, 'openprovider', new RuntimeException('The registrar could not be read: cURL error 28'));
        $staff = $this->staff();
        $armed = true;
        ModuleAction::retrieved(function () use (&$armed, $row, $staff) {
            if ($armed) {
                $armed = false; // the cron has just read the row; staff click now
                $row->closeUnrenewed($staff);
            }
        });

        app(DomainRenewalService::class)->renewLine($line->id); // the cron

        $this->assertSame(ModuleAction::CLOSED_UNRENEWED, $row->fresh()->resolution);
        $this->assertCount(0, $this->charges, 'closed «not renewed» by staff, then charged by the cron run that was already under way');
    }

    /**
     * ADV-4 (stuck order). A registration sent with no answer (pending). Staff «Close (stop it)» (as before #306): it is
     * put on hold, its request left unconfirmed. They then check the registrar (not registered) and want to say so, but
     * «Not done there» (and «Done by hand») only exist on an OPEN row, and markNotDone() returns on a closed one: the
     * request stays pending forever, so CancelOrder keeps the domain («done, or may be») and never refunds it, and the
     * domain stays pending-register on hold with nothing that can settle it.
     */
    public function test_adv4_after_close_stop_it_an_unconfirmed_registration_can_never_be_said_not_done(): void
    {
        $domain = $this->domain(['status' => DomainStatus::PendingRegister, 'expires_at' => null, 'registrar_domain_id' => null]);
        $log = DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $this->op->id, 'action' => 'register',
            'status' => DomainRegistrarLog::STATUS_PENDING, 'request' => ['years' => 1], 'error' => 'HTTP 503']);
        $row = ModuleAction::domainFailed($domain, null, 'openprovider', new RuntimeException('No answer'), ModuleAction::DOMAIN_REGISTER);
        $staff = $this->staff();
        $row->closeUnrenewed($staff);
        $row->fresh()->markNotDone($staff); // checked at the registrar afterwards: not registered

        $this->assertSame(DomainRegistrarLog::STATUS_FAILED, $log->fresh()->status,
            'Closed (stop it) over an unconfirmed registration: no action left can ever say it was not done');
    }

    /** Guard: a renewal closed «not renewed» is skipped by the payment's job, and Retry (staff) renews it. */
    public function test_guard_closed_line_skipped_by_job_renewed_by_retry(): void
    {
        $domain = $this->domain();
        $line = $this->line($domain, '2026-11-01', '2027-11-01');
        $this->script = ['refuse'];
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        ModuleAction::query()->open()->where('invoice_item_id', $line->id)->sole()->closeUnrenewed($staff = $this->staff());
        (new RenewDomainJob($line->id))->handle(app(DomainRenewalService::class));
        $this->assertCount(1, $this->charges);
        $this->assertSame(DomainRenewalService::APPLIED, app(DomainRenewalService::class)->renewLine($line->id, $staff));
        $this->assertCount(2, $this->charges);
    }

    /** Guard: Close under a held registrar lock says «busy» and changes nothing (sweep S4). */
    public function test_guard_close_while_the_domain_is_locked_is_busy(): void
    {
        $domain = $this->domain();
        $line = $this->line($domain, '2026-11-01', '2027-11-01');
        $row = ModuleAction::domainFailed($domain, $line, 'openprovider', new RuntimeException('x'));
        $log = $this->pendingCharge($domain, $line, 1);
        $lock = Cache::lock("domain-registrar:{$domain->id}", 600);
        $this->assertTrue($lock->get());
        try {
            $row->closeUnrenewed($this->staff());
            $this->fail('no «busy»');
        } catch (RuntimeException) {
        } finally {
            $lock->release();
        }
        $this->assertSame(ModuleAction::STATUS_OPEN, $row->fresh()->status);
        $this->assertSame(DomainRegistrarLog::STATUS_PENDING, $log->fresh()->status);
    }
}
