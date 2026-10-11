<?php

namespace Tests\Feature;

use App\Livewire\Invoices\Show;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * credits_enabled = off means the balance is never spent: not from the invoice page and not by
 * the cron's auto-use (upstream PR #1295 covered only the first; docs/upstream-triage.md).
 */
class CreditsEnabledTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Invoice} */
    private function userWithCreditAndInvoice(): array
    {
        $user = User::factory()->create();
        $user->credits()->create(['currency_code' => 'USD', 'amount' => 50.00]);
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => 'pending']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20.00]);

        return [$user, $invoice->fresh()];
    }

    public function test_the_invoice_page_refuses_credit_payment_when_credits_are_off(): void
    {
        config(['settings.credits_enabled' => false]);
        [$user, $invoice] = $this->userWithCreditAndInvoice();

        Livewire::actingAs($user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'credit')
            ->call('processPayment');

        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertEquals(50.00, $user->credits()->value('amount'));
    }

    public function test_the_invoice_page_pays_with_credit_when_credits_are_on(): void
    {
        config(['settings.credits_enabled' => true]);
        [$user, $invoice] = $this->userWithCreditAndInvoice();

        Livewire::actingAs($user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'credit')
            ->call('processPayment');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertEquals(30.00, $user->credits()->value('amount'));
    }

    public function test_the_cron_never_auto_spends_credit_when_credits_are_off(): void
    {
        config(['settings.credits_enabled' => false, 'settings.credits_auto_use' => true, 'settings.cronjob_invoice' => 7]);
        $user = User::factory()->create();
        $user->credits()->create(['currency_code' => 'USD', 'amount' => 10.00]);
        $product = $this->createProduct();
        Service::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'status' => 'active',
            'expires_at' => now()->addDays(2),
            'currency_code' => 'USD',
            'price' => 10.00,
        ]);

        $this->artisan('app:cron-job')->assertExitCode(0);

        $this->assertDatabaseHas('invoices', ['user_id' => $user->id, 'status' => 'pending']);
        $this->assertEquals(10.00, $user->credits()->value('amount'));
    }
}
