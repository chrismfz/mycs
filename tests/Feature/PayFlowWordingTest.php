<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Livewire\Client\PaymentMethods;
use App\Livewire\Invoices\Show;
use App\Models\BillingAgreement;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use MyCS\Extensions\Gateways\WordingPay\WordingPay;
use Tests\TestCase;

/**
 * The pay flow's words (docs/pay-flow-ux.md, PR 1): no English written into the pages, the amount on the pay button,
 * and one name for automatic payments.
 */
class PayFlowWordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_invoice_pay_buttons_show_the_amount_and_no_hard_coded_english(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20.00]);
        $invoice = $invoice->fresh();
        $label = e(__('invoices.pay_amount', ['amount' => $invoice->formattedRemaining]));

        // The pay panel's button, on the page since PR 3 (the window holds only a gateway's own form).
        $page = $this->livewireAs($user)->test(Show::class, ['invoice' => $invoice]);
        $this->assertSame(1, substr_count($page->html(), $label), 'the pay panel\'s button');
        $page->assertDontSee('Processing...')->assertDontSee(':amount');

        // That window asks to close in the customer's language.
        $window = file_get_contents(resource_path('views/invoices/partials/payment-modal.blade.php'));
        $this->assertStringContainsString("wire:confirm=\"{{ __('invoices.close_payment_confirm') }}\"", $window);
        $this->assertStringNotContainsString('Are you sure?', $window);
    }

    public function test_the_payment_methods_page_speaks_greek_with_one_name_for_automatic_payments(): void
    {
        if (!class_exists(WordingPay::class)) {
            eval('namespace MyCS\Extensions\Gateways\WordingPay; class WordingPay { public function __construct($config = []) {}
                public function supportsBillingAgreements() { return true; } public function cancelBillingAgreement($agreement): bool { return true; } }');
        }
        $user = User::factory()->create();
        $client = $user->client();
        $gateway = Gateway::query()->create(['extension' => 'WordingPay', 'name' => 'WordingPay', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa **** 4242',
            'type' => 'visa', 'external_reference' => 'pm_wording']);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid']);
        DB::table('invoice_transactions')->insert([
            ['invoice_id' => $invoice->id, 'gateway_id' => null, 'amount' => 10, 'is_credit_transaction' => true,
                'status' => InvoiceTransactionStatus::Succeeded->value, 'transaction_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['invoice_id' => $invoice->id, 'gateway_id' => $gateway->id, 'amount' => 5, 'is_credit_transaction' => false,
                'status' => InvoiceTransactionStatus::Succeeded->value, 'transaction_id' => 'ch_1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        app()->setLocale('el');

        $this->livewireAs($user)->test(PaymentMethods::class)
            ->assertSee('Αυτόματη πληρωμή ανανεώσεων')
            ->assertSee('Συναλλαγή χωρίς αριθμό')
            ->assertSee('Συναλλαγή ch_1')
            ->assertSee('από το υπόλοιπο')
            ->assertSee('με WordingPay')
            ->assertDontSee('Πληρώθηκε από το υπόλοιπο')
            ->assertDontSee('Transaction')
            ->assertDontSee('using')
            ->assertDontSee('N/A');
    }
}
