<?php

namespace Tests\Feature;

use App\Events\Invoice\Updating;
use App\Helpers\ExtensionHelper;
use App\Listeners\InvoiceNumberListener;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An invoice's number (Δ2, a setting per company): by its counter on issue; proforma (nixpal) only once paid, the year
 * the payment's; the number its id (myip). Never a number for a draft, a cancelled proforma or a second time.
 */
class InvoiceNumberingTest extends TestCase
{
    use RefreshDatabase;

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

    private function invoice(string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => $status, 'currency_code' => 'USD']);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => 10, 'description' => 'Hosting']);

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice): Invoice
    {
        ExtensionHelper::addPayment($invoice->id, null, 10, null, 'tx_' . $invoice->id, currency: 'USD');

        return $invoice->fresh();
    }

    public function test_by_its_counter_on_issue_never_for_a_draft(): void
    {
        $this->settings(['invoice_number_format' => ['INV-{number}', 'string'], 'invoice_number' => [41, 'string']]);

        $this->assertSame('INV-42', $this->invoice()->number);
        $draft = $this->invoice(Invoice::STATUS_DRAFT);
        $this->assertNull($draft->number, 'a draft is not issued yet');
        $this->assertSame(42, $this->counter());

        $draft->update(['status' => Invoice::STATUS_UNPAID]);
        $this->assertSame('INV-43', $draft->fresh()->number);
    }

    public function test_a_proforma_is_numbered_only_once_paid_with_the_year_of_its_payment(): void
    {
        $this->settings(['invoice_proforma' => [true, 'boolean'], 'invoice_number_format' => ['{year}-{number}', 'string'], 'invoice_number' => [6818, 'string']]);
        Carbon::setTestNow('2026-12-30 10:00:00');

        $cancelled = $this->invoice();
        $this->assertNull($cancelled->number);
        $cancelled->update(['status' => Invoice::STATUS_CANCELLED]);
        $this->assertNull($cancelled->fresh()->number, 'cancelled unpaid: no number, no gap');

        $draft = $this->invoice(Invoice::STATUS_DRAFT);
        $draft->update(['status' => Invoice::STATUS_UNPAID]);
        $this->assertNull($draft->fresh()->number, 'issued, still a proforma (it used to take a number here)');
        $this->assertSame(6818, $this->counter());

        Carbon::setTestNow('2027-01-02 09:00:00');
        $paid = $this->pay($draft);
        $this->assertSame(Invoice::STATUS_PAID, $paid->status);
        $this->assertSame('2027-6819', $paid->number, 'the year of its payment');
        $this->assertSame(6819, $this->counter());
    }

    public function test_the_number_is_its_id_and_the_counter_is_untouched(): void
    {
        // myip: as WHMCS without sequential numbering; proforma does not apply then.
        $this->settings(['invoice_number_is_id' => [true, 'boolean'], 'invoice_proforma' => [true, 'boolean'], 'invoice_number' => [500, 'string']]);

        $invoice = $this->invoice();
        $this->assertSame((string) $invoice->id, $invoice->number);
        $this->assertSame((string) $invoice->id, Invoice::query()->whereKey($invoice->id)->value('number'), 'stored, not only on the model');

        $draft = $this->invoice(Invoice::STATUS_DRAFT);
        $this->assertNull($draft->number);
        $draft->update(['status' => Invoice::STATUS_UNPAID]);
        $this->assertSame((string) $draft->id, $draft->fresh()->number);
        $this->assertSame(500, $this->counter());
    }

    public function test_a_number_is_kept_for_good(): void
    {
        $this->settings(['invoice_proforma' => [true, 'boolean'], 'invoice_number_format' => ['{year}-{number}', 'string'], 'invoice_number' => [10, 'string']]);
        Carbon::setTestNow('2026-10-20 10:00:00');

        $paid = $this->pay($this->invoice());
        $this->assertSame('2026-11', $paid->number);
        // Refunded goes through Refund: the listener, asked again on a status change, leaves a number as it is.
        $paid->status = Invoice::STATUS_REFUNDED;
        (new InvoiceNumberListener)->handle(new Updating($paid));
        $this->assertSame('2026-11', $paid->number);
        $this->assertSame(11, $this->counter(), 'nothing given twice');
    }
}
