<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\PaymentReminders;
use App\Services\Service\DunningForecast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Independent review of the sweep fixes for area «cron-billing» (CRON-BILLING-1..3). CRON-BILLING-1 made the cron's
 * «suspend only with an overdue invoice» count renewal invoices only (a charge alone is not one, #507), in
 * DunningRules::suspendable, CronJob::heldBack and the cron's notices. Two other readers of the same rule were left on
 * every invoice, a charge too, and now disagree with the cron. The real cron (`app:cron-job`, day by day) is the oracle.
 * Fake data only.
 */
class RevCronBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-11 09:00')); // a Wednesday
        Queue::fake();
        config(['settings.cronjob_suspend_requires_invoice' => true, 'settings.cronjob_invoice' => 7, 'settings.cronjob_order_terminate' => 60,
            'settings.mail_disable' => true, 'settings.credits_auto_use' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::lock('app:cron-job')->forceRelease();
        parent::tearDown();
    }

    private function service(string $expires): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => $expires, 'currency_code' => 'USD', 'price' => 10.00, 'quantity' => 1,
        ]);
    }

    /** An old open setup fee (a KIND_CHARGE line only), long overdue: not the invoice a suspension requires. */
    private function openCharge(Service $service): Invoice
    {
        $charge = Invoice::factory()->create(['client_id' => $service->client_id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $charge->items()->create(['reference_type' => Service::class, 'reference_id' => $service->id, 'description' => 'Setup fee',
            'quantity' => 1, 'price' => 20, 'kind' => InvoiceItem::KIND_CHARGE]);
        Invoice::query()->whereKey($charge->id)->update(['due_at' => '2026-10-15', 'created_at' => '2026-10-01']);

        return $charge->fresh();
    }

    /** @return string|null the first day the real cron is seen to suspend it, running once a day */
    private function suspendedOn(Service $service, int $days): ?string
    {
        for ($d = 0; $d <= $days; $d++) {
            $this->artisan('app:cron-job')->assertExitCode(0);
            if ($service->fresh()->status === Service::STATUS_SUSPENDED) {
                return Carbon::today()->toDateString();
            }
            Carbon::setTestNow(now()->addDay());
        }

        return null;
    }

    /**
     * DunningForecast's «a renewal the cron bills before then counts too» (ADV-681-3) still skips a service with any open
     * invoice, a charge too, while suspendable(requiresInvoice) no longer counts the charge. A service due 11-20 (billed
     * 11-13, suspended 11-23 with 2 Suspend Days) with an old setup fee open is foretold by neither: the forecast and the
     * «suspended tomorrow» calls staff make from it miss it, and the cron suspends it all the same.
     */
    public function test_rev_cb_1_a_service_with_an_open_charge_and_a_renewal_still_to_bill_is_foretold(): void
    {
        config(['settings.cronjob_order_suspend' => 2]);
        $service = $this->service('2026-11-20 12:00:00');
        $this->openCharge($service);

        $forecast = DunningForecast::compute(Carbon::today());
        $foretold = null;
        foreach ($forecast['days'] as $day => $lists) {
            if (in_array($service->id, $lists['suspend'], true)) {
                $foretold ??= $day;
            }
        }

        $seen = $this->suspendedOn($service, DunningForecast::DAYS);

        $this->assertSame('2026-11-23', $seen, 'the cron bills its renewal on 11-13 and suspends it on 11-23');
        $this->assertSame($seen, $foretold, 'the forecast must foretell what the cron suspends (DunningForecast::compute, its «unbilled» query still counts the charge as a bill)');
    }

    /**
     * PaymentReminders::suspendOn (the last overdue notice's «suspended on …») reads every invoice of the service,
     * a charge-only one too, as the cron did before the fix. The customer of a service 6 days past due, with only an old
     * setup fee open, is told «suspended on 14/11» on that setup fee's notice; the cron no longer suspends for a charge:
     * it bills the renewal and suspends Suspend Days after that invoice, later than the date told.
     */
    public function test_rev_cb_2_the_suspension_date_told_on_a_charge_is_the_day_the_cron_suspends(): void
    {
        config(['settings.cronjob_order_suspend' => 8]);
        $service = $this->service('2026-11-05 12:00:00');
        $charge = $this->openCharge($service);

        $told = (new \ReflectionMethod(PaymentReminders::class, 'suspendOn'))->invoke(null, $charge->fresh());
        $this->assertNotNull($told, 'the notice tells a date');

        $seen = $this->suspendedOn($service, 12);

        $this->assertSame($told, $seen === null ? null : Carbon::parse($seen)->format('d/m/Y'),
            'the date told on the charge\'s notice must be the day the cron suspends (or none told)');
    }
}
