<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «/invoices/{invoice}» (and the PayPal capture route) bound an invoice by number OR id, so «/invoices/3» could open the
 * invoice numbered 3 instead of invoice #3 (found by the MT-2a adversarial review). A purely numeric value is now always
 * an id, anything else a number, and an invoice's URL uses its number only when that is not purely numeric.
 */
class InvoiceRouteBindingTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(?string $number): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        DB::table('invoices')->where('id', $invoice->id)->update(['number' => $number]);

        return $invoice->fresh();
    }

    public function test_a_number_that_is_another_invoices_id_does_not_take_its_url(): void
    {
        $target = $this->invoice(null);
        $other = $this->invoice((string) $target->id); // numbered with the target's id

        $this->assertTrue((new Invoice)->resolveRouteBinding((string) $target->id)->is($target));
        $this->assertSame($other->id, $other->getRouteKey(), 'a numeric number is not used in the URL');
        $this->assertTrue((new Invoice)->resolveRouteBinding((string) $other->getRouteKey())->is($other));
        $this->assertStringEndsWith('/invoices/' . $other->id, route('invoices.show', $other));
    }

    public function test_a_formatted_number_is_the_url_and_finds_its_invoice(): void
    {
        $invoice = $this->invoice('2026-15');
        $this->invoice(null);

        $this->assertSame('2026-15', $invoice->getRouteKey());
        $this->assertStringEndsWith('/invoices/2026-15', route('invoices.show', $invoice));
        $this->assertTrue((new Invoice)->resolveRouteBinding('2026-15')->is($invoice));
        $this->assertTrue((new Invoice)->resolveRouteBinding((string) $invoice->id)->is($invoice), 'its id still works');
    }

    public function test_an_unknown_or_odd_value_is_not_found(): void
    {
        $this->invoice('2026-1');

        foreach (['2026-99', '0', '-1', '1.5', '1 OR 1=1', ''] as $value) {
            try {
                (new Invoice)->resolveRouteBinding($value);
                $this->fail("«{$value}» found an invoice");
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
