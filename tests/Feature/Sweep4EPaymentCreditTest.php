<?php

namespace Tests\Feature;

use App\Events\Invoice as InvoiceEvent;
use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Upgrade;
use App\Models\Credit;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\MoneyEvent;
use App\Models\Order;
use App\Models\Price;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\MoneyTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Others\Affiliates\Listeners\RewardAffiliate;
use MyCS\Extensions\Others\Affiliates\Models\Affiliate;
use MyCS\Extensions\Others\Affiliates\Models\AffiliateOrder;
use Tests\TestCase;

/** Sweep 4, lens e-payment-credit (adversarial verifier): credit created with no money behind it. */
class Sweep4EPaymentCreditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Cache::flush();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        config(['settings.credits_enabled' => true, 'settings.credits_on_downgrade' => true, 'settings.credits_auto_use' => false]);
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $this->user = User::factory()->create();
    }

    private function causeCents(int $clientId, string $cause): int
    {
        return (int) MoneyEvent::query()->where('client_id', $clientId)->where('cause', $cause)->get()
            ->sum(fn (MoneyEvent $event) => (int) round(abs((float) $event->amount) * 100));
    }

    private function creditCents(int $clientId): int
    {
        return (int) round((float) (Credit::query()->where('client_id', $clientId)->where('currency_code', 'USD')->value('amount') ?? 0) * 100);
    }

    /**
     * E-PAYMENT-CREDIT-1: the only period line of a monthly service is on an invoice refunded in full; a downgrade then
     * still gives credit for the days left, priced at the catalog price («imported without its lines» fallback).
     */
    public function test_downgrade_after_full_refund_gives_no_credit_for_the_refunded_period(): void
    {
        $from = $this->createProduct();
        $to = $this->createProduct();
        Price::query()->where('plan_id', $to->plan->id)->update(['price' => 2.00]);
        DB::table('product_upgrades')->insert(['product_id' => $from->product->id, 'upgrade_id' => $to->product->id]);
        $clientId = $this->user->client()->id;
        $service = Service::factory()->create([
            'client_id' => $clientId, 'product_id' => $from->product->id, 'plan_id' => $from->plan->id,
            'status' => 'active', 'price' => 10.00, 'currency_code' => 'USD', 'expires_at' => now()->addDays(20),
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'First month', 'price' => 10.00, 'quantity' => 1, 'reference_id' => $service->id,
            'reference_type' => Service::class, 'period_start' => now()->subDays(10)->toDateString(), 'period_end' => now()->addDays(20)->toDateString()]);
        $total = (float) $invoice->fresh()->remaining;
        ExtensionHelper::addPayment($invoice->id, 'Stripe', $total, transactionId: 'pi_e1', currency: 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // Staff refund all of it, back to the card, recorded here.
        ExtensionHelper::refundLocally(InvoiceTransaction::query()->where('transaction_id', 'pi_e1')->sole(), $total, notify: false);
        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->fresh()->status);
        $this->assertSame('active', $service->fresh()->status, 'the service runs on to its expiry');
        $this->assertSame(0, $this->creditCents($clientId));

        $this->livewireAs($this->user)->test(Upgrade::class, ['service' => $service->fresh()])
            ->set('upgrade', $to->product->id)
            ->call('doUpgrade');

        $this->assertSame([0, 0], [$this->causeCents($clientId, MoneyTrail::CAUSE_DOWNGRADE), $this->creditCents($clientId)],
            'downgrade credit given for days whose payment was refunded in full');
    }

    /**
     * E-PAYMENT-CREDIT-2: the affiliate reward is added again each time the same invoice becomes paid (chargeback
     * lost, then the dispute won).
     */
    public function test_affiliate_reward_once_per_invoice_across_chargeback_and_win(): void
    {
        Artisan::call('migrate', ['--path' => 'extensions/Others/Affiliates/database/migrations', '--force' => true]);
        Event::listen(InvoiceEvent\Paid::class, RewardAffiliate::class); // as the enabled extension registers it
        $affiliateUser = User::factory()->create();
        $affiliate = Affiliate::query()->create(['client_id' => $affiliateUser->client()->id, 'code' => 'SW4', 'reward' => 10]);
        $product = $this->createProduct();
        $clientId = $this->user->client()->id;
        $order = Order::query()->create(['client_id' => $clientId, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $clientId, 'order_id' => $order->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'active', 'price' => 100.00, 'currency_code' => 'USD']);
        AffiliateOrder::query()->create(['order_id' => $order->id, 'affiliate_id' => $affiliate->id]);
        $invoice = Invoice::factory()->create(['client_id' => $clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD',
            'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 100.00,
            'reference_type' => Service::class, 'reference_id' => $service->id]);
        $total = (float) $invoice->fresh()->remaining;

        ExtensionHelper::addPayment($invoice->id, 'Stripe', $total, transactionId: 'pi_aff', currency: 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $reward = $this->creditCents($affiliateUser->client()->id);
        $this->assertGreaterThan(0, $reward, 'rewarded on the first payment');

        ExtensionHelper::chargebackAtGateway('Stripe', 'pi_aff', 'dp_aff', $total, 'usd');
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        ExtensionHelper::chargebackReversedAtGateway('Stripe', 'pi_aff', 'dp_aff', $total, 'usd');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        $this->assertSame([$reward, $reward], [$this->creditCents($affiliateUser->client()->id),
            $this->causeCents($affiliateUser->client()->id, MoneyTrail::CAUSE_AFFILIATE_REWARD)],
            'the same sale rewarded twice: no new money came in');
    }
}
