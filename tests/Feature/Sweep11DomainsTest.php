<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainRegistrarLog;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\Lookup\LaravelTransport;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\SplitInvoice;
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
 * Sweep 11, domains (adversarial verifier). S11-DOMAINS-1: the «renewed at its registrar on credit: it is owed» guards read
 * the registrar log's invoice_id, which a line's move to another invoice (SplitInvoice: staff, or the customer's own
 * payment method / «do not charge automatically» through ItemPaymentMethod) never updates. Fixtures as DomainSweepRenewalTest.
 */
class Sweep11DomainsTest extends TestCase
{
    use RefreshDatabase;

    private DomainRegistrarConnection $op;

    private User $user;

    /** @var array<string, mixed> */
    private array $record = [];

    /** @var list<string> */
    private array $charges = [];

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
        $this->record = ['id' => 30391502, 'domain' => ['name' => 'example', 'extension' => 'com'], 'status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00',
            'name_servers' => [['name' => 'ns1.example.net'], ['name' => 'ns2.example.net']]];
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/renew')) {
                $this->charges[] = $path;
                $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears((int) $request->data()['period'])->format('Y-m-d H:i:s');

                return Http::response(['code' => 0, 'data' => ['success' => true]]);
            }

            return Http::response(['data' => $this->record]);
        });
    }

    protected function tearDown(): void
    {
        LaravelTransport::$resolve = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(): Domain
    {
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        foreach ([1 => '23.33', 2 => '46.66'] as $years => $price) {
            DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => $years, 'currency_code' => 'EUR'], ['price' => $price]);
        }

        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'example', 'tld' => 'com', 'fqdn' => 'example.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $this->op->id, 'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active,
            'expires_at' => '2026-11-01', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    /** An unpaid invoice with the domain's renewal line and something else on it; «Renew now» charges the registrar on it. */
    private function renewedNowOnSharedInvoice(Domain $domain): InvoiceItem
    {
        $invoice = Invoice::query()->create(['client_id' => $domain->client_id, 'status' => Invoice::STATUS_UNPAID, 'due_at' => today(), 'currency_code' => 'EUR']);
        $line = $invoice->items()->create(['reference_type' => Domain::class, 'reference_id' => $domain->id, 'price' => '23.33', 'quantity' => 1,
            'description' => 'example.com 2026-11-01 - 2027-11-01', 'period_start' => '2026-11-01', 'period_end' => '2027-11-01']);
        $invoice->items()->create(['price' => '10.00', 'quantity' => 1, 'description' => 'Something else']);

        $renewed = app(DomainRenewalService::class)->renewNow($domain, $this->staff());
        $this->assertSame($line->id, $renewed->id, 'the line billed already is the one renewed');
        $this->assertCount(1, $this->charges, 'the registrar was charged one period on credit');
        $this->assertNull($renewed->fresh()->applied_at);
        $this->assertSame((int) $invoice->id, (int) DomainRegistrarLog::query()->where('domain_id', $domain->id)->where('action', 'renew')
            ->where('status', DomainRegistrarLog::STATUS_OK)->value('invoice_id'));

        return $line->fresh();
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['*']])->id]);
    }

    /**
     * The domain ends with its on-credit renewal unpaid. On the invoice the registrar was charged on, the cron's cancel
     * leaves it for staff («it is owed»). After the line is split to an invoice of its own, the same call cancels it.
     */
    public function test_renewed_on_credit_stays_owed_after_its_line_is_split_to_another_invoice(): void
    {
        $domain = $this->domain();
        $line = $this->renewedNowOnSharedInvoice($domain);
        $first = (int) $line->invoice_id;

        // Control: before the move the guard holds, and nothing changes.
        $left = CancelOpenInvoices::handle([Invoice::query()->findOrFail($first)], 'Domain lost', $domain->refresh());
        $this->assertStringContainsString('renewed at its registrar', implode(' ', array_map(fn (Throwable $e) => $e->getMessage(), $left)));
        $this->assertSame($first, (int) $line->fresh()->invoice_id);

        $new = (new SplitInvoice)->handle($first, [$line->id]);
        $this->assertSame((int) $new->id, (int) $line->fresh()->invoice_id);

        $left = CancelOpenInvoices::handle([$new->fresh()], 'Domain lost', $domain->refresh());

        $this->assertSame(Invoice::STATUS_UNPAID, $new->fresh()->status, 'a registrar period bought on credit is owed wherever its line went: staff decide, the cron does not cancel it');
        $this->assertStringContainsString('renewed at its registrar', implode(' ', array_map(fn (Throwable $e) => $e->getMessage(), $left)));
    }

    /**
     * «Renew now» twice: refused while the first is unpaid (ADV-6). After the line is split away and the invoice the log
     * points at is paid (its other line), the first renewal is still unpaid, yet a second period is bought on credit.
     */
    public function test_renew_now_is_still_refused_after_the_unpaid_line_left_the_invoice_of_its_charge(): void
    {
        $domain = $this->domain();
        $line = $this->renewedNowOnSharedInvoice($domain);
        $first = Invoice::query()->findOrFail($line->invoice_id);

        $new = (new SplitInvoice)->handle((int) $first->id, [$line->id]);
        (new RecordPaymentService)->handle($first->fresh(), null, (float) $first->fresh()->total, transactionId: 'tx-' . $first->id);
        $this->assertSame(Invoice::STATUS_PAID, $first->fresh()->status);
        $this->assertSame(Invoice::STATUS_UNPAID, $new->fresh()->status);
        $this->assertNull($line->fresh()->applied_at);

        try {
            app(DomainRenewalService::class)->renewNow($domain->refresh(), $this->staff());
        } catch (Throwable) {
            // refused: right
        }

        $this->assertCount(1, $this->charges, 'renewed now once, that renewal not paid yet: not a second year on credit');
    }
}
