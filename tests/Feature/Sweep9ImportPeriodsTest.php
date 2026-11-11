<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\User;
use App\Services\Money\MoneyCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * Sweep 9, adversarial verifier, «import-periods» (WhmcsLinePeriods, #788). Invented rows, no network.
 */
class Sweep9ImportPeriodsTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    private int $clientA;

    private int $clientB;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->clientA = User::factory()->create()->client()->id;
        $this->clientB = User::factory()->create()->client()->id;
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        // The WHMCS service is client B's (moved there in WHMCS); client A is its first customer.
        $this->whmcs($this->clientB, $product->product->id);
        $this->whmcs->exec("INSERT INTO tblclients VALUES ({$this->clientA}, 1)");
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cronOn(string $day): void
    {
        Carbon::setTestNow($day . ' 03:00:00');
        config(['settings.cronjob_invoice' => 7, 'settings.credits_enabled' => false, 'settings.cronjob_order_suspend' => 8,
            'settings.cronjob_order_terminate' => 60]);
        $this->artisan('app:cron-job');
    }

    /** @return list<string> the open invoices of the service's own customer that bill it, as «period_start» */
    private function ownOpen(int $service): array
    {
        return InvoiceItem::query()->where('reference_type', Service::class)->where('reference_id', $service)
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', Invoice::OPEN_STATUSES)->where('client_id', $this->clientB))->orderBy('id')->get()
            ->map(fn (InvoiceItem $line) => $line->period_start?->toDateString() ?? 'no period')->all();
    }

    /**
     * S9-IMPORT-PERIODS-1. A service moved to client B in WHMCS while its renewal invoice was open: WHMCS leaves the
     * invoice with client A. The importer writes the renewal's period on A's invoice, and the cron (which does not take
     * another customer's open invoice for the service's bill, REV-766-3) then reads that period as billed: B is never
     * asked for 12-01..01-01, is suspended with nothing to pay, and nobody is told.
     */
    public function test_the_open_renewal_on_another_customers_invoice_does_not_stop_the_services_own_customer_being_billed(): void
    {
        $this->whmcsService(31, '2026-12-01');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-11-01', date: '2026-10-25');
        $this->whmcsInvoice(530, 'Unpaid', [31 => 10.0], due: '2026-12-01', date: '2026-11-17');
        // The open renewal stayed with the first customer.
        $this->whmcs->exec("UPDATE tblinvoices SET userid = {$this->clientA} WHERE id = 530");
        $this->whmcs->exec("UPDATE tblinvoiceitems SET userid = {$this->clientA} WHERE invoiceid = 530");
        $this->importWhmcs();
        $said = $this->importSaid();

        $service = Service::query()->findOrFail(31);
        $this->assertSame($this->clientB, (int) $service->client_id);
        $this->assertSame($this->clientA, (int) Invoice::query()->findOrFail(530)->client_id);

        $this->cronOn('2026-11-25');
        $billed = $this->ownOpen(31);
        $this->cronOn('2026-12-12');
        $service->refresh();
        $told = json_encode((new MoneyCheck)->run());

        $this->assertTrue($billed === ['2026-12-01'] || str_contains($said, '#31') || str_contains((string) $told, '"service":31'),
            'the customer of the service is billed its renewal from 2026-12-01 (as before #788), or staff are told of the service by the import or the money check; '
            . 'billed: ' . json_encode($billed) . ", status after Suspend Days: {$service->status}");
    }
}
