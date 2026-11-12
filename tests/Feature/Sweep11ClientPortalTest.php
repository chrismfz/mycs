<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Livewire\Domains\Show;
use App\Models\BillingAgreement;
use App\Models\ChargeAttempt;
use App\Models\Currency;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\Payment\AutoCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use MyCS\Extensions\Gateways\AcPay\AcPay;
use Tests\TestCase;

/**
 * Sweep 11, client portal (adversarial verifier). A test here fails on main at b91b448a and proves a finding. Fake data only.
 */
class Sweep11ClientPortalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DomainTld $tld;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-20 10:00:00');
        Queue::fake();
        MailFacade::fake();
        Cache::flush();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        foreach (['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['EUR', 'string']] as $key => [$value, $kind]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $kind]);
            config(["settings.{$key}" => $value]);
        }
        Cache::forget('settings.rows');
        config(['settings.cronjob_invoice' => 7, 'settings.domain_invoice_days' => 15, 'settings.system_email_address' => 'staff@example.test',
            'settings.mail_disable' => false, 'settings.domain_billing_enabled' => true,
            'settings.autocharge_days_before' => 0, 'settings.autocharge_retry_days' => '', 'settings.autocharge_max_failures' => 0]);
        $this->user = User::factory()->create();
        $this->tld = DomainTld::query()->create(['tld' => 'com', 'grace_days' => 40, 'redemption_days' => 30, 'is_active' => true]);
        DomainTldPrice::query()->create(['domain_tld_id' => $this->tld->id, 'operation' => 'renew', 'years' => 1, 'currency_code' => 'EUR', 'price' => '23.33']);

        if (!class_exists(AcPay::class)) {
            eval('namespace MyCS\\Extensions\\Gateways\\AcPay; class AcPay {
                public static array $charges = [];
                public static $answer = null;
                public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; }
                public function cancelBillingAgreement($agreement): bool { return true; }
                public function charge($invoice, $amount, $agreement) {
                    self::$charges[] = ["invoice" => $invoice->id, "amount" => (float) $amount, "key" => $invoice->chargeKey ?? null];
                    return self::$answer === null ? true : (self::$answer)($invoice, $amount);
                } }');
        }
        AcPay::$charges = [];
        AcPay::$answer = null;
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'AcPay'], ['name' => 'AcPay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $this->user->client()->id, 'gateway_id' => $gateway->id,
            'name' => 'Visa 4242', 'external_reference' => 'pm_1', 'type' => 'card']);
        $this->assertNotNull($this->user->client()->fresh()->autoChargeAgreement(), 'precondition: a default saved method');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function domain(string $sld): Domain
    {
        return Domain::factory()->create(['client_id' => $this->user->client()->id, 'sld' => $sld, 'tld' => 'com', 'fqdn' => "{$sld}.com",
            'domain_tld_id' => $this->tld->id, 'registrar_connection_id' => null, 'status' => DomainStatus::Active, 'expires_at' => '2026-11-01',
            'currency_code' => 'EUR', 'years' => 1, 'auto_renew' => true, 'recurring_amount' => null]);
    }

    private function openRenewal(Domain $domain): ?InvoiceItem
    {
        return InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $domain->id)->whereNull('kind')
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_UNPAID))->latest('id')->first();
    }

    /**
     * S11-CLIENT-PORTAL-1: two domains expire the same day and the cron bills them on one invoice. The customer turns auto
     * renew off on one of them (its page; the confirmation speaks of that domain only). The other one, auto renew on and
     * a card saved, is then never charged: the whole invoice left the automatic charge, and nothing was moved or told.
     */
    public function test_auto_renew_off_on_one_domain_does_not_take_its_sibling_out_of_the_automatic_charge(): void
    {
        $kept = $this->domain('kept-one');
        $dropped = $this->domain('dropped-one');
        $this->artisan('app:cron-job')->assertExitCode(0);
        $line = $this->openRenewal($kept);
        $this->assertNotNull($line, 'precondition: the kept domain is billed');
        $this->assertSame($line->invoice_id, $this->openRenewal($dropped)?->invoice_id, 'precondition: both on one invoice');
        $this->assertTrue(AutoCharge::willCharge($line->invoice), 'precondition: the shared invoice would be charged');

        Livewire::actingAs($this->user)->test(Show::class, ['domain' => $dropped])->call('toggleAutoRenew');
        $this->assertFalse($dropped->fresh()->auto_renew, 'precondition: auto renew off');

        Carbon::setTestNow('2026-11-01 09:00:00');
        (new AutoCharge)->run();

        $line = $this->openRenewal($kept);
        $paid = InvoiceItem::query()->where('reference_type', Domain::class)->where('reference_id', $kept->id)->whereNull('kind')
            ->whereHas('invoice', fn ($invoice) => $invoice->where('status', Invoice::STATUS_PAID))->exists();
        $this->assertTrue($paid || ($line !== null && ChargeAttempt::query()->where('invoice_id', $line->invoice_id)->exists()),
            'the domain that still renews automatically was not charged on its due date: its invoice also bills one turned off');
    }
}
