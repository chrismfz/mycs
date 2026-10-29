<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ModuleAction;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Services\Service\RenewServiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Round-2 adversarial review of #532: the «renew» task of a paid Offline SSL renewal (RenewServiceService::activate,
 * ExtensionHelper::renewByHand).
 */
class OfflineSslRound2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['settings.default_currency' => 'USD']);
    }

    /** @param array<string, mixed> $attributes */
    private function offlineSslService(array $attributes = []): Service
    {
        $server = Server::query()->create(['name' => 'TheSSLStore (offline)', 'extension' => 'OfflineSsl', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);

        return Service::factory()->create(array_merge(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'server_id' => $server->id, 'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD',
            'price' => 10, 'quantity' => 1, 'domain' => 'shop.example.test', 'expires_at' => now()->addDays(3)], $attributes));
    }

    private function paidLine(Service $service): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'currency_code' => 'USD', 'status' => Invoice::STATUS_UNPAID]);
        $item = $invoice->items()->create(['description' => 'Certificate', 'quantity' => 1, 'price' => 10, 'reference_type' => Service::class,
            'reference_id' => $service->id]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'paid']);

        return $item;
    }

    private function renewTasks(Service $service)
    {
        return ModuleAction::query()->open()->where('service_id', $service->id)->where('action', ModuleAction::RENEW)->get();
    }

    /**
     * ADV-532-R2-1. Staff started a certificate by hand before its order was paid (WHMCS: an order accepted unpaid; its
     * certificate bought at TheSSLStore then), so the service already runs over its first period. The order's own invoice
     * is then paid: RenewServiceService takes it as the first period (isFirstOrderLine), adds no time, and still calls
     * activate() on an active service, so a «renew» task tells staff to buy the certificate again at the provider. That is
     * its first order, not a renewal: a second certificate bought for one paid period.
     */
    public function test_adv_532_r2_1_the_order_invoice_paid_after_staff_started_it_is_no_renewal(): void
    {
        $service = $this->offlineSslService(['created_at' => now()->startOfDay(), 'expires_at' => now()->addMonth()->addDays(2)]);
        $expires = $service->fresh()->expires_at->toDateString();

        (new RenewServiceService)->handle($service->fresh(), $this->paidLine($service));

        $this->assertSame($expires, $service->fresh()->expires_at->toDateString(), 'its first period: no time added');
        $this->assertCount(0, $this->renewTasks($service),
            'the order\'s own invoice of a certificate staff already started became a «renew» task: the certificate would be bought twice');
        Http::assertNothingSent();
    }

    /**
     * ADV-532-R2-2. A certificate suspended for non-payment two periods behind: the customer pays one old period. The
     * payment applies, the service stays suspended (still overdue, Δ3a), and staff get «still overdue» by mail. But the
     * «renew» task fires too, «renew this certificate at its provider (paid until <a date already past>)», with nothing
     * saying it stays suspended and more is owed: staff buy a year's certificate for a service whose customer has not
     * paid for it (the paid period is over already).
     */
    public function test_adv_532_r2_2_a_payment_that_leaves_it_suspended_never_reads_as_a_renewal_to_buy(): void
    {
        $service = $this->offlineSslService(['status' => Service::STATUS_SUSPENDED, 'dunning_suspended_at' => now()->subMonth(),
            'expires_at' => now()->subMonths(3)->startOfDay()]);

        (new RenewServiceService)->handle($service->fresh(), $this->paidLine($service));

        $fresh = $service->fresh();
        $this->assertSame(Service::STATUS_SUSPENDED, $fresh->status, 'still overdue: it stays suspended');
        $this->assertTrue($fresh->expires_at->isPast(), 'paid until a date already past');
        $task = $this->renewTasks($service)->first();
        $this->assertTrue($task === null || str_contains((string) $task->last_error, 'suspended'),
            'a «renew» task for a period already over, of a service still suspended for non-payment, says nothing of it: ' . $task?->last_error);
        Http::assertNothingSent();
    }

    /**
     * ADV-532-R2-3. renewByHand → staffTask closes the open «renew» task as superseded, then creates the new one, outside
     * any transaction (it runs after the payment's commit). If the create fails (a DB error, a deadlock), the older task
     * is already closed and the newer one never exists; renewByHand reports it and returns. Two paid renewals then leave
     * staff no open task at all: the original ADV-532-1 (paid, never renewed at the CA), silently.
     */
    public function test_adv_532_r2_3_a_failed_newer_task_never_closes_the_older_one(): void
    {
        $service = $this->offlineSslService();
        ExtensionHelper::renewByHand($service, '2027-11-01');
        $this->assertCount(1, $this->renewTasks($service));

        // A failed insert (not a «deadlock» message: inside RefreshDatabase's outer transaction Laravel leaves a nested one
        // to its outer on a deadlock, as MariaDB rolls the whole back; after the payment's commit it is the outer one).
        ModuleAction::creating(fn () => throw new RuntimeException('the insert failed'));
        ExtensionHelper::renewByHand($service->fresh(), '2028-11-01');

        $this->assertCount(1, $this->renewTasks($service),
            'the older «renew» task was closed as superseded by a newer one that was never made: two paid renewals, no task left');
        Http::assertNothingSent();
    }
}
