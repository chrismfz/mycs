<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\Payment\SavedMethodCharge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adversarial verification, sweep 6, payments.
 */
class Sweep6PaymentsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * S6-PAYMENTS-1: Stripe retries a late payment_intent.processing after payment_intent.payment_failed was accepted.
     * The failed row must stay failed; today it goes back to processing and holds the invoice from every way of paying.
     */
    public function test_a_late_processing_event_does_not_revive_a_failed_payment(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $invoice = $invoice->fresh();

        ExtensionHelper::addFailedPayment($invoice, 'Stripe', 10, null, 'pi_S6P1', 'EUR');
        ExtensionHelper::addProcessingPayment($invoice, 'Stripe', 10, null, 'pi_S6P1', 'EUR');

        $rows = InvoiceTransaction::query()->where('transaction_id', 'pi_S6P1')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(InvoiceTransactionStatus::Failed, $rows->first()->status, 'a failed payment went back to processing');
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()), 'no money is on its way, yet the invoice is held');
    }

    /** The rule's other half: a failed payment may still become succeeded (the gateway's last word), and pays the invoice. */
    public function test_a_failed_payment_still_becomes_succeeded(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $invoice = $invoice->fresh();

        ExtensionHelper::addFailedPayment($invoice, 'Stripe', 10, null, 'pi_S6P1b', 'EUR');
        ExtensionHelper::addProcessingPayment($invoice, 'Stripe', 10, null, 'pi_S6P1b', 'EUR');
        ExtensionHelper::addPayment($invoice->id, 'Stripe', 10, transactionId: 'pi_S6P1b', currency: 'EUR');

        $row = InvoiceTransaction::query()->where('transaction_id', 'pi_S6P1b')->sole();
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $row->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // Succeeded never changes.
        ExtensionHelper::addProcessingPayment($invoice, 'Stripe', 10, null, 'pi_S6P1b', 'EUR');
        ExtensionHelper::addFailedPayment($invoice, 'Stripe', 10, null, 'pi_S6P1b', 'EUR');
        $this->assertSame(InvoiceTransactionStatus::Succeeded, $row->fresh()->status);
    }

    /** A processing payment still fails, and a failed one that fails again stays one failed row. */
    public function test_a_processing_payment_still_becomes_failed(): void
    {
        Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway']);
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $invoice = $invoice->fresh();

        ExtensionHelper::addProcessingPayment($invoice, 'Stripe', 10, null, 'pi_S6P1c', 'EUR');
        $this->assertTrue(SavedMethodCharge::onItsWay($invoice->fresh()));
        ExtensionHelper::addFailedPayment($invoice, 'Stripe', 10, null, 'pi_S6P1c', 'EUR');
        ExtensionHelper::addFailedPayment($invoice, 'Stripe', 10, null, 'pi_S6P1c', 'EUR');

        $this->assertSame(InvoiceTransactionStatus::Failed, InvoiceTransaction::query()->where('transaction_id', 'pi_S6P1c')->sole()->status);
        $this->assertFalse(SavedMethodCharge::onItsWay($invoice->fresh()));
    }
}
