<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use App\Services\Money\CreditLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial verification, sweep 3, lens «settings matrix»: the same flow on an instance with «Automatically use
 * credits» off (myip: NoAutoApplyCredit on, written by the importer). Fake data only.
 */
class Sweep3LSettingsMatrixTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');
        Queue::fake();
        $this->settings(['credits_enabled' => [true, 'boolean'], 'credits_auto_use' => [false, 'boolean'], 'default_currency' => ['USD', 'string']]);
        config(['settings.cronjob_invoice' => 7, 'settings.cronjob_order_suspend' => 2, 'settings.cronjob_order_terminate' => 0,
            'settings.cronjob_suspend_requires_invoice' => false]);
        $this->user = User::factory()->create();
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

    private function cron(): void
    {
        $this->artisan('app:cron-job')->assertExitCode(0);
    }

    /**
     * L-SETTINGS-MATRIX-1. A service renewed by a gateway subscription (PayPal billing agreement / Stripe subscription,
     * subscription_id set), its last period paid. The subscription's sale for the next period arrives before the cron
     * bills it (no open invoice): it lands on the latest (paid) invoice and becomes credit, as the gateways do. The cron
     * then bills the renewal; RenewalBilling hands it to CreditAutoApply::toSubscriptionRenewal, whose own words are «a
     * subscription sale with no open invoice to land on becomes credit, and that credit is what pays the renewal».
     *
     * Expected: the subscription's money pays its renewal whatever the auto-apply policy (the code itself skips the
     * customer's opt-out for it: «that credit is the subscription's payment»). Actual with credits_auto_use off: the
     * renewal stays unpaid, the money sits as credit, no charge ever comes (it is the subscription's to pay), and the
     * cron suspends the service for non-payment two days after its due date.
     */
    public function test_l_settings_matrix_1_a_subscription_sale_that_became_credit_pays_its_renewal_with_auto_use_off(): void
    {
        $product = $this->createProduct();
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'expires_at' => '2026-11-15', 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
            'subscription_id' => 'I-SUBSCRIPTIONSWEEP3',
        ]);
        $paid = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => '2026-10-15', 'created_at' => '2026-10-08']);
        $paid->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Renewal',
            'quantity' => 1, 'price' => 10.00, 'period_start' => '2026-10-15', 'period_end' => '2026-11-15']);
        DB::table('invoices')->where('id', $paid->id)->update(['status' => Invoice::STATUS_PAID]);
        Gateway::query()->firstOrCreate(['extension' => 'PayPal'], ['name' => 'PayPal', 'type' => 'gateway', 'enabled' => true]);

        // The subscription's sale for the period from 2026-11-15, before the cron billed it: on the latest (paid) invoice.
        ExtensionHelper::addPayment($paid->id, 'PayPal', 10.00, null, 'SALE-SWEEP3-1', currency: 'USD');
        $ledger = new CreditLedger;
        $this->assertSame(1000, $ledger->balanceCents($service->client_id, 'USD'), 'precondition: the sale became credit');

        $this->cron(); // bills the renewal (7 days before 2026-11-15)
        $renewal = Invoice::query()->where('id', '!=', $paid->id)->whereHas('items', fn ($q) => $q->where('reference_type', Service::class)
            ->where('reference_id', $service->id))->first();
        $this->assertNotNull($renewal, 'precondition: the cron billed the renewal');

        $this->assertSame(Invoice::STATUS_PAID, $renewal->fresh()->status, 'the subscription sale that became credit pays its renewal');
        $this->assertSame(0, $ledger->balanceCents($service->client_id, 'USD'));

        Carbon::setTestNow('2026-11-18 10:00:00'); // Suspend Days (2) after its due date
        $this->cron();
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status, 'not suspended while its own subscription payment sits as credit');
    }
}
