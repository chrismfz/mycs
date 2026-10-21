<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
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
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of #305 (a renewal charges the years paid, earliest period first). test_adv* FAIL on the PR's head
 * and show a defect; test_guard_* pass and pin a guard that holds. Openprovider over Http::fake only.
 */
class Pr305AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $op;

    private User $user;

    /** @var array<string, mixed> */
    private array $record = [];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $charges = [];

    private ?string $failWith = null;

    private bool $lag = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Cache::flush();
        Queue::fake();
        Mail::fake();
        Http::preventStrayRequests();
        config(['audit.console' => true, 'settings.domain_billing_enabled' => true, 'settings.domain_invoice_days' => 15, 'settings.credits_auto_use' => false]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $this->op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeOp(array $record): void
    {
        $this->record = $record + ['id' => 30391502, 'domain' => ['name' => 'example', 'extension' => 'com'],
            'name_servers' => [['name' => 'ns1.example.net'], ['name' => 'ns2.example.net']]];
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'POST' && (str_ends_with($path, '/renew') || str_ends_with($path, '/restore'))) {
                $this->charges[] = [$path, $request->data()];
                if ($this->failWith !== null) {
                    return Http::response(['code' => 399, 'desc' => $this->failWith], 400);
                }
                if (!$this->lag) {
                    $years = str_ends_with($path, '/restore') ? 1 : (int) $request->data()['period'];
                    $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears($years)->format('Y-m-d H:i:s');
                }
                $this->record['status'] = 'ACT';

                return Http::response(['code' => 0, 'data' => ['success' => true]]);
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

        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active,
            'expires_at' => '2026-11-01', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function pay(Invoice $invoice): void
    {
        (new RecordPaymentService)->handle($invoice, null, (float) $invoice->fresh()->total, transactionId: 'tx-' . $invoice->id);
    }

    private function lineFor(Domain $domain, string $from, string $to, string $status = Invoice::STATUS_UNPAID): InvoiceItem
    {
        $invoice = Invoice::query()->create(['client_id' => $domain->client_id, 'status' => $status, 'due_at' => today(), 'currency_code' => 'EUR']);

        return $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => '23.33', 'quantity' => 1,
            'description' => "example.com {$from} - {$to}", 'period_start' => $from, 'period_end' => $to]);
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function chargedYears(): int
    {
        return (int) collect($this->charges)->sum(fn ($c) => str_ends_with($c[0], '/restore') ? 1 : (int) ($c[1]['period'] ?? 0));
    }

    /**
     * ADV-1 (money/expiry). F3 is fixed only when the earlier period is ALREADY PAID when the later one runs. The customer
     * pays B (2027-11-01 → 2028-11-01) while A (2026-11-01 → 2027-11-01) is still unpaid: B lacks 2 years at the
     * registrar, is charged min(2, 1) = 1 (that year actually fills A's period) and applied «short». A is paid next: the
     * registrar shows 2027-11-01 = A's end, so A is ADOPTED. Two years paid, one bought: the name expires 2027-11-01 while
     * paid to 2028-11-01, and billedLine counts 2027 → 2028 as billed (B), so the cron never invoices it again.
     */
    public function test_adv1_later_period_paid_before_the_earlier_one_buys_one_year_for_two_paid(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00']);
        $domain = $this->domain();
        $a = $this->lineFor($domain, '2026-11-01', '2027-11-01');
        $b = $this->lineFor($domain, '2027-11-01', '2028-11-01');
        $service = app(DomainRenewalService::class);

        $this->pay($b->invoice);
        $service->renewLine($b->id);
        $this->pay($a->invoice);
        $service->renewLine($a->id);
        // and whatever the cron does next with anything still waiting
        foreach (DomainRenewalService::waitingLines()->pluck('id') as $id) {
            $service->renewLine($id);
        }

        $this->assertSame(2, $this->chargedYears(), 'two consecutive years paid, two bought at the registrar');
        $this->assertSame('2028-11-01', substr((string) $this->record['expiration_date'], 0, 10));
    }

    /**
     * ADV-2 (money). «Renew now» in the window after a transfer that replaced a renewal completed and the target's expiry
     * still lags (renew_transfer.done_at + short_since set, < RENEW_AFTER_TRANSFER_HOURS, the registry not read yet).
     * The owner's rule (#304): never a second year while only the registrar lags — the cron waits for the registry
     * (RDAP). renewNow finds that same paid, applied line by billedLine, chargedYears() = 0 (a transfer is not a
     * renew/restore), and the new branch says «A line a transfer applied without a year was not charged: renewed here»:
     * it charges a renew at the target. When the transfer's year lands: two years bought for one paid, and the returned
     * line is the already-paid one, so the extra year is never billed.
     */
    public function test_adv2_renew_now_right_after_a_renewal_transfer_buys_a_second_year(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00']);
        $cnic = DomainRegistrarConnection::query()->create(['registrar' => 'centralnic_reseller', 'label' => 'CNIC', 'is_active' => true, 'mode' => 'production']);
        $domain = $this->domain();
        $line = $this->lineFor($domain, '2026-11-01', '2027-11-01');
        $this->pay($line->invoice);
        $line->forceFill(['applied_at' => now()->subHours(2)])->save();
        DomainRegistrarLog::query()->create(['domain_id' => $domain->id, 'registrar_connection_id' => $this->op->id, 'invoice_id' => $line->invoice_id,
            'action' => 'transfer', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['years' => 1, 'line' => $line->id, 'renewal' => true, 'from' => $cnic->id], 'response' => ['status' => 'REQ', 'id' => 30391502]]);
        $domain->forceFill(['meta' => ['sent_at' => now()->subHours(2)->toIso8601String(), 'renew_transfer' => ['line' => $line->id, 'from' => $cnic->id,
            'to' => $this->op->id, 'at' => now()->subHours(2)->toIso8601String(), 'sent_at' => now()->subHours(2)->toIso8601String(),
            'done_at' => now()->subHour()->toIso8601String(), 'short_since' => now()->subHour()->toIso8601String(), 'prior' => ['handles' => []]]]])->save();

        try {
            app(DomainRenewalService::class)->renewNow($domain->refresh(), $this->staff());
        } catch (Throwable) {
            // refusing is right
        }

        $this->assertSame(0, $this->chargedYears(), 'the year paid was bought by its transfer: «Renew now» never buys it again on the registrar\'s lag');
    }

    /**
     * Guard (F3 holds). A and B both paid, B run first: A is renewed first under the same lock, then B; the registrar
     * lagging after A's charge (applied «short») does not make B buy A's year again: 2 bought for 2 paid.
     */
    public function test_guard_f3_earlier_first_even_when_the_registrar_lags(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00']);
        $domain = $this->domain();
        $a = $this->lineFor($domain, '2026-11-01', '2027-11-01');
        $b = $this->lineFor($domain, '2027-11-01', '2028-11-01');
        $this->pay($a->invoice);
        $this->pay($b->invoice);
        $this->lag = true;

        app(DomainRenewalService::class)->renewLine($b->id);
        app(DomainRenewalService::class)->renewLine($a->id);

        $this->assertSame(2, $this->chargedYears());
        $this->assertNotNull($a->fresh()->applied_at);
        $this->assertNotNull($b->fresh()->applied_at);
    }

    /**
     * Guard. The earlier period refused by the registrar: the later one waits (nothing charged for it), and staff have an
     * open action for the domain; staff's Retry of the LATER line does not charge it past the refused earlier one.
     */
    public function test_guard_earlier_refused_later_waits_and_staff_know(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00']);
        $domain = $this->domain();
        $a = $this->lineFor($domain, '2026-11-01', '2027-11-01');
        $b = $this->lineFor($domain, '2027-11-01', '2028-11-01');
        $this->pay($a->invoice);
        $this->pay($b->invoice);
        $this->failWith = 'Not allowed';

        // Held for staff, and said on B too (code review 1 of #305): never a paid period held back with nothing open for it.
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($b->id));
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($b->id, $this->staff(), true));
        $this->assertTrue(ModuleAction::query()->open()->where('invoice_item_id', $b->id)->exists(), 'B says it waits for A');

        $this->assertCount(1, $this->charges, 'only A asked, once');
        $this->assertTrue(ModuleAction::query()->open()->where('domain_id', $domain->id)->exists(), 'staff told');
        $this->assertNull($b->fresh()->applied_at);
    }

    /**
     * Guard (F1/F2). «Renew now» never charges more years than the line bills, nor a period whose paid line was charged
     * already and applied «short»; a second press over an unpaid «Renew now» invoice is refused.
     */
    public function test_guard_renew_now_twice_with_lagging_registrar_charges_once(): void
    {
        $this->fakeOp(['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00']);
        $domain = $this->domain();
        $staff = $this->staff();
        $this->lag = true;

        $line = app(DomainRenewalService::class)->renewNow($domain, $staff);
        try {
            app(DomainRenewalService::class)->renewNow($domain->refresh(), $staff);
        } catch (Throwable) {
        }
        $this->pay($line->invoice);
        app(DomainRenewalService::class)->renewLine($line->id);
        try {
            app(DomainRenewalService::class)->renewNow($domain->refresh(), $staff);
        } catch (Throwable) {
        }

        $this->assertSame(1, $this->chargedYears());
    }
}
