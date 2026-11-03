<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Livewire\Client\PaymentMethods;
use App\Livewire\Invoices\Show;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The pay flow's words (docs/pay-flow-ux.md, PR 1): no English written into the pages, the amount on the pay button,
 * and one name for automatic payments.
 */
class PayFlowWordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_invoice_pay_button_shows_the_amount_and_no_hard_coded_english(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20.00]);
        $invoice = $invoice->fresh();

        $page = $this->livewireAs($user)->test(Show::class, ['invoice' => $invoice]);
        $page->assertSee(__('invoices.pay_amount', ['amount' => $invoice->formattedRemaining]))
            ->assertDontSee('Processing...')
            ->assertDontSee(':amount');

        // Its payment window: the button with the amount, the close confirmation in the customer's language.
        $page->set('showPayModal', true)
            ->assertSee(__('invoices.pay_amount', ['amount' => $invoice->formattedRemaining]))
            ->assertSeeHtml('wire:confirm="' . e(__('invoices.close_payment_confirm')) . '"')
            ->assertDontSeeHtml('Are you sure?');
    }

    public function test_the_payment_methods_page_lists_payments_in_the_customers_language(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid']);
        DB::table('invoice_transactions')->insert(['invoice_id' => $invoice->id, 'gateway_id' => null, 'amount' => 10, 'is_credit_transaction' => true,
            'status' => InvoiceTransactionStatus::Succeeded->value, 'transaction_id' => null, 'created_at' => now(), 'updated_at' => now()]);

        $this->livewireAs($user)->test(PaymentMethods::class)
            ->assertSee(__('account.transaction_ref_none'))
            ->assertSee(__('invoices.paid_with_credits'))
            ->assertDontSee('Transaction ID N/A')
            ->assertDontSee(' using ');
    }

    public function test_automatic_payments_have_one_name_in_greek(): void
    {
        app()->setLocale('el');

        $this->assertSame('Αυτόματη πληρωμή ανανεώσεων', __('account.auto_charge'));
        $this->assertSame('Αυτόματη πληρωμή', __('services.auto_pay'));
        $this->assertStringContainsString('αυτόματα', __('invoices.use_for_recurring'));
        $this->assertSame('Απλήρωτο', __('invoices.payment_pending'));
    }
}
