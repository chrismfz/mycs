<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Exceptions\DisplayException;
use App\Models\ApiKey;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Domains\DomainRenewalService;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Review R6 of #172: a domain line was taken for this year's renewal by its date when it had no period, so an owner
 * change (or last year's late renewal) stopped the cron billing the renewal. Now (code review F1 of #174, the safe side)
 * a domain line is a renewal only with its period, or when its maker asks (kind «renewal»: the next period not on a live
 * invoice); anything else without a period is a «charge». What a line is, and its domain, are set when it is made.
 * billedLine reads periods only.
 */
class DomainLinePeriodTest extends TestCase
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
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['EUR', 'string']] as $key => [$value, $type]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $type]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_invoice' => 7, 'settings.domain_invoice_days' => 15, 'settings.domain_billing_enabled' => true]);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 0, 'is_active' => true]);
        foreach ([['renew', 1, '23.33'], ['renew', 2, '46.66']] as [$operation, $years, $price]) {
            DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => $price]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(array $attributes = []): Domain
    {
        return Domain::factory()->create($attributes + ['client_id' => $this->user->client()->id, 'sld' => 'nixpal', 'tld' => 'com', 'fqdn' => 'nixpal.com',
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function invoice(): Invoice
    {
        return Invoice::query()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => '2026-10-20']);
    }

    /** @return list<InvoiceItem> its renewal lines (no kind) */
    private function renewals(Domain $domain): array
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')->orderBy('id')->get()->all();
    }

    public function test_a_charge_on_the_domain_does_not_stop_its_renewal_being_billed(): void
    {
        $domain = $this->domain();
        $this->invoice()->items()->create(['description' => 'Owner change', 'price' => '30.00', 'quantity' => 1,
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_CHARGE]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        [$renewal] = $this->renewals($domain);
        $this->assertSame(['2026-11-01', '2027-11-01'], [$renewal->period_start->toDateString(), $renewal->period_end->toDateString()]);
    }

    public function test_a_line_without_a_period_is_a_charge_unless_asked_to_be_the_renewal(): void
    {
        $domain = $this->domain(['years' => 2]);
        $charge = $this->invoice()->items()->create(['description' => 'Owner change', 'price' => '30.00', 'quantity' => 1,
            'reference_type' => Domain::class, 'reference_id' => $domain->id]);
        $this->assertSame([InvoiceItem::KIND_CHARGE, null], [$charge->kind, $charge->period_start], 'forgetting to say it is a charge renews nothing');

        $line = $this->invoice()->items()->create(['description' => 'nixpal.com renewal', 'price' => '46.66', 'quantity' => 1,
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_RENEWAL]);
        $this->assertNull($line->kind);

        $this->assertSame(['2026-11-01', '2028-11-01'], [$line->period_start->toDateString(), $line->period_end->toDateString()]);
        $this->artisan('app:cron-job')->assertExitCode(0);
        $this->assertCount(1, $this->renewals($domain), 'billed: the cron does not bill the same years');

        (new RecordPaymentService)->handle($line->invoice->fresh(), null, '46.66', transactionId: 'tx-hand');
        $this->assertTrue(DomainRenewalService::waitingLines()->whereKey($line->id)->exists(), 'a paid renewal waiting for its registrar');
    }

    public function test_one_made_while_the_next_year_is_billed_takes_the_year_after(): void
    {
        $domain = $this->domain();
        $this->artisan('app:cron-job')->assertExitCode(0); // bills 2026-11-01 → 2027-11-01

        $line = $this->invoice()->items()->create(['description' => 'Another year', 'price' => '23.33', 'quantity' => 1,
            'reference_type' => Domain::class, 'reference_id' => $domain->id, 'kind' => InvoiceItem::KIND_RENEWAL]);

        $this->assertSame('2027-11-01', $line->period_start->toDateString(), 'never the same year twice');
    }

    public function test_last_years_late_renewal_does_not_count_as_this_years(): void
    {
        // Renewed late last year (in its grace), its line carries its own period: this year's is billed at its time.
        $domain = $this->domain();
        $old = $this->invoice();
        $old->items()->create(['description' => 'Late renewal', 'price' => '23.33', 'quantity' => 1, 'reference_type' => Domain::class,
            'reference_id' => $domain->id, 'period_start' => '2025-11-01', 'period_end' => '2026-11-01']);
        DB::table('invoice_items')->where('invoice_id', $old->id)->update(['created_at' => '2025-11-20']);

        $this->assertNull(DomainRenewalService::billedLine($domain, Carbon::parse('2026-11-01')));
    }

    public function test_the_admin_api_takes_a_charge_and_only_that_kind(): void
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'test', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => ['admin.invoice_items.create', 'invoice_items.create']]);
        $domain = $this->domain();
        $invoice = $this->invoice();
        $body = ['invoice_id' => $invoice->id, 'description' => 'Owner change', 'price' => 30, 'quantity' => 1,
            'reference_type' => Domain::class, 'reference_id' => $domain->id];

        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), $body + ['kind' => 'grace_fee'])->assertUnprocessable();
        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), ['invoice_id' => $invoice->id, 'description' => 'Hosting',
            'price' => 10, 'quantity' => 1, 'kind' => InvoiceItem::KIND_CHARGE])->assertUnprocessable(); // domain lines only
        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), $body + ['kind' => InvoiceItem::KIND_CHARGE])->assertSuccessful();
        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), $body + ['description' => 'Renewal', 'kind' => InvoiceItem::KIND_RENEWAL])
            ->assertSuccessful()->assertJsonPath('data.attributes.period_start', fn ($date) => str_starts_with((string) $date, '2026-11-01'));

        $lines = InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('id')->get();
        $this->assertSame([[InvoiceItem::KIND_CHARGE, null], [null, '2026-11-01']], $lines->map(fn ($l) => [$l->kind, $l->period_start?->toDateString()])->all());

        // A domain without an expiry has no period to renew (F6).
        $pending = $this->domain(['sld' => 'new', 'fqdn' => 'new.com', 'expires_at' => null, 'status' => DomainStatus::PendingRegister]);
        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), ['reference_id' => $pending->id, 'kind' => InvoiceItem::KIND_RENEWAL] + $body)
            ->assertUnprocessable();
    }

    public function test_what_a_line_is_and_its_domain_stay_as_made(): void
    {
        // F2/F4 of #174: a charge never becomes a renewal (paid, it would renew at the registrar), a renewal never a charge
        // (it would leave the paid year unrenewed and billed again), and a domain line keeps its domain; a stage fee still
        // becomes the next stage's.
        $domain = $this->domain();
        $other = $this->domain(['sld' => 'other', 'fqdn' => 'other.com']);
        $invoice = $this->invoice();
        $charge = $invoice->items()->create(['description' => 'Owner change', 'price' => '30.00', 'quantity' => 1, 'reference_type' => Domain::class, 'reference_id' => $domain->id]);
        $fee = $invoice->items()->create(['description' => 'Grace fee', 'price' => '100.30', 'quantity' => 1, 'kind' => 'grace_fee', 'reference_type' => Domain::class, 'reference_id' => $domain->id]);

        foreach ([fn () => $charge->update(['kind' => null]), fn () => $fee->update(['kind' => InvoiceItem::KIND_CHARGE]),
            fn () => $charge->update(['reference_id' => $other->id])] as $change) {
            try {
                $change();
                $this->fail('changed');
            } catch (DisplayException) {
            }
        }
        $fee->update(['kind' => 'redemption_fee']);
        $this->assertSame([InvoiceItem::KIND_CHARGE, $domain->id, 'redemption_fee'], [$charge->fresh()->kind, (int) $charge->fresh()->reference_id, $fee->fresh()->kind]);
    }
}
