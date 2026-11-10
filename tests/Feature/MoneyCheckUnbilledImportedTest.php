<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Money\MoneyCheck;
use App\Services\Money\UnbilledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ImportedWhmcsInvoices;
use Tests\TestCase;

/**
 * `service_unbilled` on what the WHMCS importer brings, through its own steps: a «Hosting» line has no period and a paid
 * one is never applied, so an imported service's current period has no line that gave it. That alone is never a finding.
 */
class MoneyCheckUnbilledImportedTest extends TestCase
{
    use ImportedWhmcsInvoices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Carbon::setTestNow('2026-09-20 10:00:00');
        $user = User::factory()->create();
        $product = $this->createProduct();
        $product->plan->update(['name' => 'Monthly']);
        $this->whmcs($user->client()->id, $product->product->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<int, list<string>> service => the kinds found for it */
    private function kinds(): array
    {
        $kinds = [];
        foreach ((new UnbilledServices)->find() as $finding) {
            $kinds[$finding['service']][] = $finding['kind'];
        }

        return $kinds;
    }

    public function test_an_imported_service_with_its_paid_invoice_is_not_told(): void
    {
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(480, 'Paid', [31 => 10.0], due: '2026-09-10');
        $this->importWhmcs();

        $this->assertSame([Service::STATUS_ACTIVE, '2026-10-10'], [Service::query()->findOrFail(31)->status, Service::query()->findOrFail(31)->expires_at->toDateString()]);
        $this->assertSame(Invoice::STATUS_PAID, $this->imported(480)->status);
        $this->assertSame([], $this->kinds(), 'no line gave its period: that is how every imported service looks');
        $this->assertSame([], (new MoneyCheck)->run());
    }

    public function test_an_imported_service_whose_unpaid_renewal_is_due_on_its_date_is_the_ordinary_customer(): void
    {
        // Overdue (due 10 days ago, with its service) and not due yet (invoiced ahead): WHMCS moves the date only when paid.
        $this->whmcsService(31, '2026-09-10');
        $this->whmcsInvoice(501, 'Unpaid', [31 => 10.0], due: '2026-09-10');
        $this->whmcsService(32, '2026-09-25');
        $this->whmcsInvoice(502, 'Unpaid', [32 => 10.0], due: '2026-09-25');
        $this->importWhmcs();
        $this->imported(501);

        $this->assertSame([], $this->kinds());
    }

    public function test_an_imported_service_running_past_an_unpaid_renewal_is_told(): void
    {
        // WHMCS let it run: its date is a month ahead (moved by hand, or a later invoice paid) of an unpaid renewal.
        $this->whmcsService(31, '2026-10-10');
        $this->whmcsInvoice(501, 'Unpaid', [31 => 10.0], due: '2026-09-10');
        // A part payment on it: what it still owes is said.
        $this->whmcsService(32, '2026-10-10');
        $this->whmcsInvoice(502, 'Unpaid', [32 => 10.0], due: '2026-09-10', paid: 4.0);
        $this->importWhmcs();
        $this->imported(501);

        $this->assertSame([31 => [UnbilledServices::PERIOD_NOT_PAID], 32 => [UnbilledServices::PERIOD_NOT_PAID]], $this->kinds());
        $this->assertSame([
            ['rule' => 'service_unbilled', 'subject' => 'service #31', 'detail' => 'runs to 2026-10-10 while invoice #501 that bills it is unpaid, due since 2026-09-10 (owes 10.00 EUR): collect it, cancel it, or move the due date back'],
            ['rule' => 'service_unbilled', 'subject' => 'service #32', 'detail' => 'runs to 2026-10-10 while invoice #502 that bills it is unpaid, due since 2026-09-10 (owes 6.00 EUR): collect it, cancel it, or move the due date back'],
        ], (new MoneyCheck)->run(['service_unbilled']));

        // Paid here: nothing is left to tell, and the service keeps its date (it paid what it already ran on).
        (new RecordPaymentService)->recordByStaff(Invoice::query()->findOrFail(501), null, '10.00', 'tr_501');
        $this->assertSame([32 => [UnbilledServices::PERIOD_NOT_PAID]], $this->kinds());
        $this->assertSame('2026-10-10', Service::query()->findOrFail(31)->expires_at->toDateString(), 'an old debt paid: no period more');
    }

    public function test_an_imported_service_with_no_due_date_is_told(): void
    {
        $this->whmcsService(31, '0000-00-00');
        $this->importWhmcs();

        $this->assertNull(Service::query()->findOrFail(31)->expires_at);
        $this->assertSame([31 => [UnbilledServices::NO_DUE_DATE]], $this->kinds());
    }
}
