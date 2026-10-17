<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * D7 (owner 2026-10-17): a domain lost for good (deleted at its registry, moved away, ended by staff) has its open renewal
 * invoices cancelled by the cron (domain_invoices_cancelled), as myip's hook did after 30 days, now when it is known: a
 * domain-only invoice whole, a mixed one loses the domain's renewal and stage fee lines. A «charge» on it stays.
 */
class DomainLostInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        MailFacade::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['EUR', 'string']] as $key => [$value, $type]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $type]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.domain_billing_enabled' => true, 'settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false,
            'settings.domain_reminder_days' => '']);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Deleted, 'expires_at' => '2026-08-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function invoice(): Invoice
    {
        return Invoice::query()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-08-01']);
    }

    private function renewal(Invoice $invoice, Domain $domain): InvoiceItem
    {
        return $invoice->items()->create(['description' => 'Renewal', 'price' => '23.33', 'quantity' => 1, 'reference_type' => Domain::class,
            'reference_id' => $domain->id, 'period_start' => '2026-08-01', 'period_end' => '2027-08-01']);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    public function test_a_lost_domains_own_invoice_is_cancelled_with_its_reason(): void
    {
        $invoice = $this->invoice();
        $domain = $this->domain();
        $this->renewal($invoice, $domain);
        $invoice->items()->create(['description' => 'Restore fee', 'price' => '37.50', 'quantity' => 1, 'kind' => 'redemption_fee',
            'reference_type' => Domain::class, 'reference_id' => $domain->id]);

        $this->cron();

        $this->assertSame([Invoice::STATUS_CANCELLED, __('invoices.cancellation_reasons.domain_lost')],
            [$invoice->fresh()->status, $invoice->fresh()->cancellation_reason]);
    }

    public function test_a_mixed_invoice_loses_only_the_domains_lines(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-11-15']);
        $invoice = $this->invoice();
        $invoice->items()->create(['description' => 'Hosting', 'price' => '10.00', 'quantity' => 1, 'reference_type' => Service::class,
            'reference_id' => $service->id, 'period_start' => '2026-11-15', 'period_end' => '2026-12-15']);
        $this->renewal($invoice, $this->domain());

        $this->cron();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame([Service::class], $invoice->fresh()->items->pluck('reference_type')->all());
    }

    public function test_a_domain_still_recoverable_and_a_charge_on_a_lost_one_are_left_alone(): void
    {
        $recoverable = $this->domain(['sld' => 'alive', 'fqdn' => 'alive.com', 'status' => DomainStatus::Redemption]);
        $open = $this->invoice();
        $this->renewal($open, $recoverable);
        $charge = $this->invoice();
        $charge->items()->create(['description' => 'Owner change', 'price' => '30.00', 'quantity' => 1, 'kind' => InvoiceItem::KIND_CHARGE,
            'reference_type' => Domain::class, 'reference_id' => $this->domain()->id]);

        $this->cron();

        $this->assertSame([Invoice::STATUS_UNPAID, Invoice::STATUS_UNPAID], [$open->fresh()->status, $charge->fresh()->status]);
    }

    public function test_moved_away_or_ended_by_staff_count_as_lost_and_a_paid_invoice_is_never_touched(): void
    {
        $away = $this->invoice();
        $this->renewal($away, $this->domain(['sld' => 'away', 'fqdn' => 'away.com', 'status' => DomainStatus::TransferredAway]));
        $paid = $this->invoice();
        $this->renewal($paid, $this->domain());
        (new RecordPaymentService)->handle($paid->fresh(), null, '23.33', transactionId: 'tx-1');

        $this->cron();

        $this->assertSame(Invoice::STATUS_CANCELLED, $away->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $paid->fresh()->status);
    }

    public function test_one_holding_money_stays_open_and_staff_are_told_once(): void
    {
        $invoice = $this->invoice();
        $this->renewal($invoice, $this->domain());
        (new RecordPaymentService)->handle($invoice->fresh(), null, '10.00', transactionId: 'tx-part'); // part paid by transfer

        $this->cron();
        $this->cron();

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $told = EmailLog::query()->where('subject', 'Cron Job: needs staff attention')->pluck('body')
            ->filter(fn (string $body) => str_contains($body, "Invoice #{$invoice->id} left open"))->count();
        $this->assertSame(1, $told);
    }
}
