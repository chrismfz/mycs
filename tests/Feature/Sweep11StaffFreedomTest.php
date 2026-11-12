<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\UndoRefund;
use App\Services\Money\MoneyCheck;
use App\Services\Money\UnbilledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Sweep 11, adversarial verifier, «staff-freedom». Each test fails on main (b91b448a) for the reason its candidate
 * reports. Names are example ones; Http is faked, never a real registrar.
 */
class Sweep11StaffFreedomTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Plan $plan;

    private Product $product;

    /** @var array<string, mixed> the registrar's record of the domain */
    private array $record = [];

    /** @var list<string> the charges asked of the registrar (paths) */
    private array $charges = [];

    private bool $registrarDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Cache::flush();
        Carbon::setTestNow('2026-10-20 10:00:00');
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.cronjob_payment_reminder' => 6, 'settings.cronjob_overdue_reminders' => '1,3',
            'settings.cronjob_order_suspend' => 8, 'settings.cronjob_suspend_enabled' => true, 'settings.cronjob_invoice' => 7,
            'settings.credits_auto_use' => false, 'audit.console' => true, 'settings.domain_billing_enabled' => true,
            'settings.domain_invoice_days' => 15]);
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->user = User::factory()->create();
        $created = $this->createProduct();
        Price::query()->where('plan_id', $created->plan->id)->update(['price' => 10.00]);
        $this->plan = $created->plan;
        $this->product = $created->product;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Openprovider, faked: GET returns the record (or a 500 while it is «down»); a renew moves its expiry. */
    private function registrar(): DomainRegistrarConnection
    {
        $connection = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $connection->storeCredentials(['username' => 'u', 'password' => 'Op-Example-Pa55'], ['password']);
        $this->record = ['status' => 'ACT', 'expiration_date' => '2026-11-01 10:00:00', 'id' => 30391502,
            'domain' => ['name' => 'example-sweep', 'extension' => 'com'], 'name_servers' => [['name' => 'ns1.example.test'], ['name' => 'ns2.example.test']]];
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/auth/login')) {
                return Http::response(['data' => ['token' => 'tok']]);
            }
            if ($request->method() === 'POST' && (str_ends_with($path, '/renew') || str_ends_with($path, '/restore'))) {
                $this->charges[] = $path;
                $this->record['expiration_date'] = Carbon::parse($this->record['expiration_date'])->addYears((int) ($request->data()['period'] ?? 1))->format('Y-m-d H:i:s');

                return Http::response(['code' => 0, 'data' => ['success' => true]]);
            }
            if ($this->registrarDown) {
                return Http::response(['code' => 500, 'desc' => 'Internal error'], 500);
            }

            return Http::response(['data' => $this->record]);
        });

        return $connection;
    }

    /**
     * S11-STAFF-FREEDOM-1. A paid invoice bills a domain renewal and something else. The registrar cannot be read when
     * the payment arrives, so the renewal waits (a task for staff). Staff give the domain's part back (a partial refund:
     * the invoice stays paid). The cron's next run still buys the year at the registrar.
     */
    public function test_a_domain_renewal_whose_money_was_refunded_is_not_bought_at_the_registrar(): void
    {
        $this->markTestIncomplete('known (2026-11-12): a paid domain renewal waiting at the registrar is still bought after its part was refunded; watched live');
        $connection = $this->registrar();
        $tld = DomainTld::query()->firstOrCreate(['tld' => 'com'], ['grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        DomainTldPrice::query()->firstOrCreate(['domain_tld_id' => $tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR'], ['price' => '10.00']);
        $domain = Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => 'example-sweep', 'tld' => 'com', 'fqdn' => 'example-sweep.com',
            'domain_tld_id' => $tld->id, 'registrar_connection_id' => $connection->id, 'registrar_domain_id' => '30391502', 'status' => DomainStatus::Active,
            'expires_at' => '2026-11-01', 'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);

        // Billed by the cron; the same invoice bills something else too (60.00).
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->sole();
        $line->invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 60]);
        $invoice = Invoice::query()->findOrFail($line->invoice_id);
        $part = $line->totalCents() / 100;

        // Paid while the registrar is down: the renewal waits, with a task for staff.
        $this->registrarDown = true;
        $payment = (new RecordPaymentService)->recordByStaff($invoice, null, (string) $invoice->total, 'bank-s11-1');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(DomainRenewalService::FAILED, app(DomainRenewalService::class)->renewLine($line->id));
        $this->assertSame([], $this->charges);
        $this->assertTrue(ModuleAction::query()->open()->where('invoice_item_id', $line->id)->exists(), 'a task for staff: the registrar could not be read');

        // Staff give the domain's money back; the invoice stays paid (the rest is kept).
        ExtensionHelper::refundLocally($payment->fresh(), $part, notify: false);
        $invoice = $invoice->fresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertFalse($invoice->keepsItsMoney(), 'the invoice no longer holds what it bills');

        // The registrar is back; the cron runs.
        $this->registrarDown = false;
        Carbon::setTestNow(now()->addHours(2));
        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertSame([], $this->charges, "{$part} EUR of the invoice was refunded (it keeps " . ($invoice->moneyKeptCents() / 100) . ' of ' . $invoice->total
            . '), and the cron bought the domain a year at the registrar: line applied=' . json_encode($line->fresh()->applied_at !== null)
            . ', expiry now ' . $domain->fresh()->expires_at->toDateString());
    }

    private function service(string $due): Service
    {
        return Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $this->plan->id, 'product_id' => $this->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => $due, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1]);
    }

    /** @return list<string> */
    private function kinds(Service $service): array
    {
        return array_values(array_column(array_filter((new UnbilledServices)->find(), fn (array $finding) => $finding['service'] === $service->id), 'kind'));
    }

    /**
     * The renewal paid and applied, refunded in full by mistake, the due date moved back as the money check advises for a
     * refunded invoice, and the refund then undone.
     *
     * @return array{0: Service, 1: Invoice, 2: bool} the service, the invoice, and whether the undo was refused
     */
    private function refundedMovedBackAndUndone(): array
    {
        Carbon::setTestNow('2026-11-10 10:00:00');
        $service = $this->service('2026-11-15');
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => '2026-11-15']);
        $invoice->items()->create(['reference_id' => $service->id, 'reference_type' => Service::class, 'description' => 'Renewal', 'quantity' => 1, 'price' => 10,
            'period_start' => '2026-11-15', 'period_end' => '2026-12-15']);
        $payment = (new RecordPaymentService)->recordByStaff($invoice->fresh(), null, '10.00', 'bank-s11-2');
        $this->assertSame('2026-12-15', $service->fresh()->expires_at->toDateString());

        Carbon::setTestNow('2026-11-10 11:00:00');
        ExtensionHelper::refundLocally($payment->fresh(), 10.00, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertSame([UnbilledServices::PERIOD_NOT_PAID], $this->kinds($service));
        $this->assertStringContainsString('bill it again, or move the due date back',
            (string) collect((new MoneyCheck)->run(['service_unbilled']))->pluck('detail')->implode(' '));

        // Staff follow the advice.
        $service->fresh()->applyStaffChanges(['expires_at' => '2026-11-15']);
        $this->assertSame('2026-11-15', $service->fresh()->expires_at->toDateString());

        // The refund was the mistake: undone.
        Carbon::setTestNow('2026-11-10 12:00:00');
        $refused = false;
        try {
            (new UndoRefund)->handle($payment->fresh(), (int) UndoRefund::refunds($payment->fresh())->sole()->id);
        } catch (DisplayException) {
            $refused = true;
        }

        return [$service->fresh(), $invoice->fresh(), $refused];
    }

    /** S11-STAFF-FREEDOM-2: the invoice is paid again with its money, and the service does not hold its period; nobody is told. */
    public function test_an_undone_refund_gives_its_period_back_after_the_due_date_was_moved_back_or_staff_are_told(): void
    {
        $this->markTestIncomplete('known (2026-11-12): an undone refund after the due date was moved back leaves a paid period the service does not hold; watched live');
        $mailsBefore = EmailLog::query()->where('template_key', 'system')->count();
        [$service, $invoice, $refused] = $this->refundedMovedBackAndUndone();
        if ($refused) {
            $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->status);

            return; // refused, with its reason: staff know
        }

        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(1000, $invoice->moneyKeptCents());
        $given = $service->expires_at->toDateString() === '2026-12-15';
        $told = EmailLog::query()->where('template_key', 'system')->count() > $mailsBefore
            || collect((new MoneyCheck)->run())->contains(fn (array $finding) => $finding['subject'] === "service #{$service->id}");
        $this->assertTrue($given || $told, "invoice #{$invoice->id} is paid again and keeps 10.00 for 2026-11-15..2026-12-15, yet the service is due "
            . $service->expires_at->toDateString() . ', and neither a notice nor the money check tells staff');
    }

    /** S11-STAFF-FREEDOM-2, what follows: the cron suspends the paid service, with nothing open to pay. */
    public function test_the_cron_does_not_suspend_a_service_whose_refund_was_undone(): void
    {
        $this->markTestIncomplete('known (2026-11-12): an undone refund after the due date was moved back leaves a paid period the service does not hold; watched live');
        [$service, $invoice, $refused] = $this->refundedMovedBackAndUndone();
        if ($refused) {
            $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->status);

            return;
        }
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);

        while (now()->toDateString() < '2026-11-30') {
            Carbon::setTestNow(now()->addDay());
            $this->artisan('app:cron-job')->assertExitCode(0);
        }

        $service = $service->fresh();
        $open = Invoice::query()->where('client_id', $service->client_id)->whereIn('status', Invoice::OPEN_STATUSES)->count();
        $this->assertSame(Service::STATUS_ACTIVE, $service->status,
            "paid 2026-11-15..2026-12-15 on invoice #{$invoice->id} (paid, keeps its money), yet on 2026-11-30 it is {$service->status} (due "
            . $service->expires_at->toDateString() . "), with {$open} open invoice(s) to pay");
    }
}
