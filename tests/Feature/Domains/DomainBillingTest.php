<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice\InvoiceItemReference;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Domains D5b-1 (docs/domains.md D-4, D-8, D-14): a domain is billed directly, N days before its expiry, for its years at
 * its renewal price, on the same invoice as the services due the same day; a period once; the fee of its grace or
 * redemption on the open renewal invoice. A paid renewal waits (paid, not applied) for D5b-2's registrar renewal.
 */
class DomainBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['EUR', 'string']]);
        config(['settings.cronjob_invoice' => 7, 'settings.domain_invoice_days' => 15]);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        foreach ([['renew', 1, '23.33'], ['renew', 2, '46.66'], ['grace', 1, '100.30']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $values): void
    {
        foreach ($values as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /** @return list<InvoiceItem> */
    private function domainLines(Domain $domain): array
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->orderBy('id')->get()->all();
    }

    public function test_a_domain_is_billed_its_own_days_before_expiry_for_its_years_at_its_price(): void
    {
        $domain = $this->domain(['years' => 2]);

        $this->cron();

        [$line] = $this->domainLines($domain);
        $this->assertSame(['46.66', '2026-11-01', '2028-11-01', null], [$line->price, $line->period_start->toDateString(), $line->period_end->toDateString(), $line->kind]);
        $this->assertStringContainsString('nixpal.com', $line->description);
        $this->assertSame(['2026-11-01', Invoice::STATUS_UNPAID, 'EUR'], [$line->invoice->due_at->toDateString(), $line->invoice->status, $line->invoice->currency_code]);
        $this->assertNull($line->invoice->finish_pending_at, 'finished and emailed in the same run');

        $this->cron();
        $this->assertCount(1, $this->domainLines($domain), 'a period is billed once');
    }

    public function test_not_billed_before_its_days_without_auto_renew_or_without_a_price(): void
    {
        $later = $this->domain(['expires_at' => '2026-11-20']);
        $off = $this->domain(['sld' => 'off', 'fqdn' => 'off.com', 'auto_renew' => false]);
        $unpriced = $this->domain(['sld' => 'x', 'fqdn' => 'x.com', 'years' => 3]);

        $this->cron();

        $this->assertSame([[], [], []], [$this->domainLines($later), $this->domainLines($off), $this->domainLines($unpriced)]);
    }

    public function test_a_period_staff_cancelled_is_not_billed_again(): void
    {
        $domain = $this->domain();
        $this->cron();
        $this->domainLines($domain)[0]->invoice->update(['status' => Invoice::STATUS_CANCELLED]);

        $this->cron();

        $this->assertCount(1, $this->domainLines($domain));
    }

    public function test_a_domain_and_a_service_due_the_same_day_share_one_invoice_unless_separate(): void
    {
        $product = $this->createProduct();
        Service::factory()->create(['client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25']);
        $domain = $this->domain(['expires_at' => '2026-10-25']);

        $this->cron();

        $invoice = $this->domainLines($domain)[0]->invoice;
        $this->assertSame([Domain::class, Service::class], $invoice->items->pluck('reference_type')->sort()->values()->all());

        $other = User::factory()->create();
        $other->client()->forceFill(['separate_invoices' => true])->save();
        Service::factory()->create(['client_id' => $other->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'EUR', 'price' => 10.00, 'quantity' => 1, 'expires_at' => '2026-10-25']);
        $alone = $this->domain(['client_id' => $other->client()->id, 'sld' => 'alone', 'fqdn' => 'alone.com', 'expires_at' => '2026-10-25']);

        $this->cron();

        $this->assertSame([Domain::class], $this->domainLines($alone)[0]->invoice->items->pluck('reference_type')->all());
    }

    public function test_an_expired_domain_in_grace_is_billed_due_today_with_its_grace_fee_once(): void
    {
        $domain = $this->domain(['expires_at' => '2026-10-10', 'status' => DomainStatus::Grace]);

        $this->cron();
        $this->cron();

        $lines = $this->domainLines($domain);
        $this->assertSame([[null, '23.33'], ['grace_fee', '100.30']], array_map(fn ($l) => [$l->kind, $l->price], $lines));
        $this->assertSame('2026-10-20', $lines[0]->invoice->due_at->toDateString(), 'past its expiry: due today');
        $this->assertSame($lines[0]->invoice_id, $lines[1]->invoice_id, 'on the existing renewal invoice (D-8)');
    }

    public function test_the_grace_fee_becomes_the_redemption_fee_and_an_invoice_with_money_is_never_touched(): void
    {
        DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => 'redemption', 'years' => 1, 'currency_code' => 'EUR', 'price' => '37.50']);
        $this->tld->update(['redemption_days' => 30]);
        $domain = $this->domain(['expires_at' => '2026-10-10', 'status' => DomainStatus::Grace]);
        $this->cron();

        $domain->update(['status' => DomainStatus::Redemption]);
        $this->cron();
        $this->assertSame([[null, '23.33'], ['redemption_fee', '37.50']], array_map(fn ($l) => [$l->kind, $l->price], $this->domainLines($domain)));

        $other = $this->domain(['sld' => 'paid', 'fqdn' => 'paid.com', 'expires_at' => '2026-10-10', 'status' => DomainStatus::Grace]);
        $this->cron();
        $invoice = $this->domainLines($other)[0]->invoice;
        (new RecordPaymentService)->handle($invoice, null, 1, transactionId: 'tx-part');
        $other->update(['status' => DomainStatus::Redemption]);
        $this->cron();
        $this->assertSame([[null, '23.33'], ['grace_fee', '100.30']], array_map(fn ($l) => [$l->kind, $l->price], $this->domainLines($other)));
    }

    public function test_a_paid_domain_renewal_waits_for_the_registrar_and_is_never_marked_applied(): void
    {
        $domain = $this->domain();
        $this->cron();
        $invoice = $this->domainLines($domain)[0]->invoice;

        (new RecordPaymentService)->handle($invoice, null, 23.33, transactionId: 'tx-domain');

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->apply_pending_since, 'paid, not applied: D5b-2 renews it at the registrar');
        $this->assertNull($this->domainLines($domain)[0]->applied_at);
    }

    public function test_an_invoice_line_may_point_at_the_customers_own_domain_only(): void
    {
        $domain = $this->domain();
        $theirs = Domain::factory()->create(['client_id' => User::factory()->create()->client()->id, 'sld' => 'theirs', 'tld' => 'com', 'fqdn' => 'theirs.com']);
        $invoice = Invoice::query()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-11-01']);

        $this->assertNull(InvoiceItemReference::problem($invoice, Domain::class, $domain->id));
        $this->assertNotNull(InvoiceItemReference::problem($invoice, Domain::class, $theirs->id));
    }
}
