<?php

namespace Tests\Feature;

use App\Livewire\Services\Show as ServiceShow;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial area review 6: what the import brings for logins and who may do what (closed accounts, grants, staff
 * roles, 2FA, sessions). Each test fails on the current code and names the defect. Fake data only.
 */
class AdvArea6Test extends TestCase
{
    use RefreshDatabase;

    /** A login that owns an open account and a closed one (WHMCS «Closed», WG-8), signed in on the open one. */
    private function openAndClosed(): array
    {
        $user = User::factory()->create();
        $open = $user->clients()->sole();
        $closed = Client::factory()->ownedBy($user)->create(['closed_at' => now()]);
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $open->id]));

        return [$user, $open, $closed];
    }

    /**
     * ADV-A6-1: a closed account is «not worked on» (CurrentClient, review of #651), but its records are still reached
     * by id: the policies' `owns()` checks only the live grant, never `clients.closed_at`. A login with another, open
     * account opens the closed account's invoices.
     */
    public function test_adv_a6_1_a_closed_accounts_invoice_is_not_reached_by_id(): void
    {
        [, , $closed] = $this->openAndClosed();
        $invoice = Invoice::factory()->create(['client_id' => $closed->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);

        $status = $this->get(route('invoices.show', $invoice))->status();
        $this->assertContains($status, [403, 404], "the closed account's invoice opened by id (HTTP {$status})");
    }

    /** ADV-A6-1b: the same by-id path gives the closed account's service password (Show::revealPassword, `update`). */
    public function test_adv_a6_1b_a_closed_accounts_service_password_is_not_revealed(): void
    {
        [, , $closed] = $this->openAndClosed();
        config(['settings.default_currency' => 'USD']);
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $closed->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => Service::STATUS_ACTIVE, 'currency_code' => 'USD', 'price' => 5, 'quantity' => 1, 'expires_at' => now()->addMonth(),
            'username' => 'closedacct', 'password' => 'Closed$ecret1']);

        $returned = Livewire::test(ServiceShow::class, ['service' => $service])->call('revealPassword')->effects['returns'][0] ?? null;
        $this->assertNotSame('Closed$ecret1', $returned, "the closed account's service password revealed");
    }
}
