<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Audit;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Owner 2026-11-08 (REV3-L-ROUNDING-TAX-2): staff change the plan of a pending service whose order line is a pro rata
 * first period. The line keeps its price (Service::refreshOpenPeriods), and staff are told once per change: the old plan's
 * stub it keeps and what the new plan's would be (ProrataOrderLineNotice). A non pro rata order line and a paid order are
 * as before: nothing told.
 */
class ProrataPlanNoticeTest extends TestCase
{
    use RefreshDatabase;

    private const SUBJECT = '%unpaid pro rata order%';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Config::set('audit.console', true); // the invoice's activity, as the web and the cron record it
        $this->storeSettings(['credits_enabled' => true, 'credits_auto_use' => false, 'default_currency' => 'USD',
            'system_email_address' => 'staff@example.test', 'mail_disable' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /** @return array{Product, Plan, Plan} a product at 30.00 + 5.00 setup a month, and another monthly plan at 40.00 */
    private function catalog(bool $prorata = true): array
    {
        /** @var Product $product */
        $product = $this->createProduct($prorata ? ['prorata_day' => 1, 'prorata_next_after_day' => 20] : [])->product;
        $plan = $product->plans()->firstOrFail();
        Price::query()->where('plan_id', $plan->id)->update(['price' => 30.00, 'setup_fee' => 5.00]);
        $pricier = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Pricier',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $pricier->id, 'price' => 40.00, 'setup_fee' => 5.00, 'currency_code' => 'USD']);

        return [$product, $plan, $pricier];
    }

    /** @return array{Service, InvoiceItem} the order placed on 2026-01-10: its service and its line */
    private function order(Product $product, Plan $plan): array
    {
        Carbon::setTestNow('2026-01-10 10:00:00');
        $placed = (new PlaceOrderService)->place(User::factory()->create()->client(), 'USD', [new OrderLine($product, $plan)]);

        return [Service::query()->sole(), $placed->invoice->fresh('items')->items->sole()];
    }

    /** As the service page saves it (EditService::handleRecordUpdate). */
    private function staffSave(Service $service, array $changes): void
    {
        $service->refresh();
        $service->applyStaffChanges($changes, $service->getAttributes());
    }

    /** @return Collection<int, EmailLog> */
    private function notices()
    {
        return EmailLog::query()->where('to', 'staff@example.test')->where('subject', 'like', self::SUBJECT)->orderBy('id')->get();
    }

    /** @return Collection<int, Audit> */
    private function activity(InvoiceItem $line)
    {
        return Audit::query()->where('auditable_type', (new Invoice)->getMorphClass())->where('auditable_id', $line->invoice_id)
            ->where('event', ChangeOpenLine::AUDIT_EVENT)->orderBy('id')->get();
    }

    public function test_a_plan_change_of_a_pending_pro_rata_service_keeps_the_line_and_tells_staff_once(): void
    {
        [$product, $plan, $pricier] = $this->catalog();
        [$service, $line] = $this->order($product, $plan);
        // 22 of 31 days of 30.00 = 21.29, and the setup fee 5.00.
        $this->assertSame(['26.29', '2026-01-10', '2026-02-01'], [$this->money($line->price), $line->period_start->toDateString(), $line->period_end->toDateString()]);
        $this->assertSame(Service::STATUS_PENDING, $service->status);

        $this->staffSave($service, ['plan_id' => $pricier->id]);

        $line->refresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $line->invoice->status);
        $this->assertSame(['26.29', '5.00', '2026-02-01'], [$this->money($line->price), $this->money($line->setup_fee), $line->period_end->toDateString()],
            'the order line keeps the old plan\'s stub, its setup fee and its day');
        $notices = $this->notices();
        $this->assertCount(1, $notices, 'one notice for the change');
        $body = (string) $notices->sole()->body;
        $this->assertStringContainsString("service #{$service->id}", $body);
        $this->assertStringContainsString("invoice #{$line->invoice_id}", $body);
        $this->assertStringContainsString('26.29 USD', $body, 'the old plan\'s pro rata price it keeps');
        // 22 of 31 days of 40.00 = 28.39, and the setup fee as billed.
        $this->assertStringContainsString('it would be 33.39 USD', $body, 'what the new plan\'s would be');
        $this->assertStringContainsString('10/01/2026 - 01/02/2026', $body);

        $activity = $this->activity($line);
        $this->assertCount(1, $activity, 'in the invoice\'s activity too');
        $this->assertSame(ChangeOpenLine::KEPT_ORDER_LINE, $activity[0]->new_values['kept_because']);
        $this->assertSame(['price' => '26.29'], $activity[0]->old_values);
        $this->assertSame('33.39', $activity[0]->new_values['price']);

        // Another save with no plan change tells nothing more.
        $this->staffSave($service, ['notes' => 'called the customer']);
        $this->assertCount(1, $this->notices(), 'once per change');
        $this->assertCount(1, $this->activity($line));
    }

    public function test_each_plan_change_is_told_once(): void
    {
        [$product, $plan, $pricier] = $this->catalog();
        $dearer = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Dearer',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $dearer->id, 'price' => 62.00, 'setup_fee' => 5.00, 'currency_code' => 'USD']);
        [$service, $line] = $this->order($product, $plan);

        $this->staffSave($service, ['plan_id' => $pricier->id]);
        $this->staffSave($service, ['plan_id' => $dearer->id]);

        $this->assertSame('26.29', $this->money($line->fresh()->price));
        $notices = $this->notices();
        $this->assertCount(2, $notices);
        $this->assertStringContainsString('it would be 33.39 USD', (string) $notices[0]->body);
        // 22 of 31 days of 62.00 = 44.00, and 5.00.
        $this->assertStringContainsString('it would be 49.00 USD', (string) $notices[1]->body);
    }

    public function test_a_price_staff_set_with_the_plan_is_the_new_plans(): void
    {
        [$product, $plan, $pricier] = $this->catalog();
        [$service, $line] = $this->order($product, $plan);

        $this->staffSave($service, ['plan_id' => $pricier->id, 'price' => '31.00']);

        $this->assertSame('26.29', $this->money($line->fresh()->price));
        // 22 of 31 days of 31.00 = 22.00, and 5.00.
        $this->assertStringContainsString('it would be 27.00 USD', (string) $this->notices()->sole()->body);
    }

    public function test_a_plan_at_the_same_price_tells_nothing(): void
    {
        [$product, $plan] = $this->catalog();
        $same = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'name' => 'Same',
            'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $same->id, 'price' => 30.00, 'setup_fee' => 5.00, 'currency_code' => 'USD']);
        [$service, $line] = $this->order($product, $plan);

        $this->staffSave($service, ['plan_id' => $same->id]);

        $this->assertSame('26.29', $this->money($line->fresh()->price));
        $this->assertCount(0, $this->notices(), 'the same stub: nothing to fix');
        $this->assertCount(0, $this->activity($line));
    }

    public function test_a_pending_service_that_is_not_pro_rata_is_as_before(): void
    {
        [$product, $plan, $pricier] = $this->catalog(prorata: false);
        [$service, $line] = $this->order($product, $plan);
        $this->assertNull($line->period_start, 'precondition: no period until it is paid');

        $this->staffSave($service, ['plan_id' => $pricier->id]);

        $line->refresh();
        $this->assertSame(['35.00', null], [$this->money($line->price), $line->period_start]);
        $this->assertCount(0, $this->notices());
        $this->assertCount(0, $this->activity($line));
    }

    public function test_a_paid_active_service_is_as_before(): void
    {
        [$product, $plan, $pricier] = $this->catalog();
        [$service, $line] = $this->order($product, $plan);
        ExtensionHelper::addPayment($line->invoice_id, null, $line->invoice->fresh()->remaining, null, 'paid-1', currency: 'USD');
        $this->assertSame(Invoice::STATUS_PAID, $line->invoice->fresh()->status);
        $this->assertSame(Service::STATUS_ACTIVE, $service->fresh()->status);

        $this->staffSave($service, ['plan_id' => $pricier->id]);

        $this->assertSame(['26.29', '2026-02-01'], [$this->money($line->fresh()->price), $line->fresh()->period_end->toDateString()]);
        $this->assertCount(0, $this->notices());
        $this->assertCount(0, $this->activity($line));
    }

    /** Review REV-PPN-3: A to B, back to A (the same stub: nothing), A to B again: two changes told, two activity rows. */
    public function test_a_change_made_again_after_a_change_back_is_told_again(): void
    {
        [$product, $plan, $pricier] = $this->catalog();
        [$service, $line] = $this->order($product, $plan);

        $this->staffSave($service, ['plan_id' => $pricier->id]);
        $this->staffSave($service, ['plan_id' => $plan->id]);
        $this->staffSave($service, ['plan_id' => $pricier->id]);

        $this->assertCount(2, $this->notices());
        $this->assertCount(2, $this->activity($line), 'each change in the invoice\'s activity, not the second taken for the first');
    }

    /**
     * Review REV-PPN-4: an Active service whose order is still unpaid (staff activated it) keeps the old stub all the same
     * and is told; the plan set for its next renewal, applied by the cron (RenewalBilling), is told as the cron's.
     */
    public function test_an_active_service_with_its_order_unpaid_is_told_and_a_cron_change_says_so(): void
    {
        [$product, $plan, $pricier] = $this->catalog();
        [$service, $line] = $this->order($product, $plan);
        $service->forceFill(['status' => Service::STATUS_ACTIVE, 'next_plan_id' => $pricier->id])->save();
        $this->assertCount(0, $this->notices());

        // As RenewalBilling applies a change of cycle «from the next renewal».
        $service->refresh();
        $service->plan_id = $pricier->id;
        $service->setRelation('plan', $pricier);
        $service->price = (float) $service->calculatePrice();
        $service->next_plan_id = null;
        $service->save();

        $this->assertSame('26.29', $this->money($line->fresh()->price));
        $body = (string) $this->notices()->sole()->body;
        $this->assertStringContainsString('by the cron (the plan set for its next renewal)', $body);
        $this->assertStringContainsString('it would be 33.39 USD', $body);

        // A change by staff is not said to be the cron's.
        $this->staffSave($service, ['plan_id' => $plan->id]);
        $this->staffSave($service, ['plan_id' => $pricier->id]);
        $last = (string) $this->notices()->last()->body;
        $this->assertStringNotContainsString('by the cron', $last);
    }
}
