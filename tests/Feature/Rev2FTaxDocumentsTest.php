<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsProvider;
use App\Services\Invoice\RecordPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Independent review of the fixes for flow «f-tax-documents» (sweep 2, 2026-11-07). Each test fails for what it names.
 */
class Rev2FTaxDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientId = User::factory()->create()->client()->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, array{0: mixed, 1: string}>  $settings */
    private function settings(array $settings): void
    {
        foreach ($settings as $key => [$value, $type]) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => $type]);
        }
        SettingsProvider::reload();
    }

    private function counter(): int
    {
        return (int) Setting::query()->where('key', 'invoice_number')->whereNull('settingable_type')->value('value');
    }

    private function invoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->clientId, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => 10, 'description' => 'Hosting']);

        return $invoice->fresh();
    }

    /**
     * REV2-F-TAX-DOCUMENTS-1. The fix only looks at the other year when the counter's candidate is taken there. Once the new
     * year's counter has passed the old year's last number (counter set back to 0 for 2027, 2026 ended at 2026-3, 2027
     * already at 2027-5), the candidate 2026-6 is free: the backdated proforma takes it and moves the counter on. 2026-4 and
     * 2026-5 are never issued, and the next 2027 invoice jumps to 2027-7 (2027-6 never issued): the gap the finding was
     * about, in both years.
     */
    public function test_rev_a_backdated_proforma_after_the_new_year_overtook_the_old_one_leaves_no_gap(): void
    {
        $this->settings(['invoice_proforma' => [true, 'boolean'], 'invoice_number_format' => ['{year}-{number}', 'string'],
            'invoice_number' => [3, 'string']]);
        $now = now();
        DB::table('invoices')->insert(array_map(fn (int $n) => ['client_id' => $this->clientId, 'number' => "2026-{$n}",
            'status' => Invoice::STATUS_PAID, 'currency_code' => 'USD', 'created_at' => $now, 'updated_at' => $now], [1, 2, 3]));
        Carbon::setTestNow('2026-12-30 10:00:00');
        $backdated = $this->invoice();

        Carbon::setTestNow('2027-01-01 09:00:00');
        $this->settings(['invoice_number' => [0, 'string']]);
        foreach (range(1, 5) as $n) {
            $paid = $this->invoice();
            (new RecordPaymentService)->recordByStaff($paid, null, 10, "tx-2027-{$n}");
            $this->assertSame("2027-{$n}", $paid->fresh()->number, 'precondition: the new year counts from 1');
        }
        Carbon::setTestNow('2027-01-05 09:00:00');

        (new RecordPaymentService)->recordByStaff($backdated, null, 10, 'bank-3112', paidAt: Carbon::parse('2026-12-31 15:00:00'));
        $this->assertSame(5, $this->counter(), "2026's own sequence: the counter, 2027's, stays at 5");
        $next = $this->invoice();
        (new RecordPaymentService)->recordByStaff($next, null, 10, 'tx-2027-6');

        $this->assertSame(['2026-4', '2027-6'], [$backdated->fresh()->number, $next->fresh()->number],
            '2026 goes on from 2026-3 and 2027 from 2027-5: no hole in either year');
    }
}
