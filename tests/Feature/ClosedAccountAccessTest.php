<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ADV-A6-1: a closed account's records are not reached by id, except by staff in «Login as client». Fake data only. */
class ClosedAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    private function closedInvoice(User $user): Invoice
    {
        $closed = Client::factory()->ownedBy($user)->create(['closed_at' => now()]);

        return Invoice::factory()->create(['client_id' => $closed->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
    }

    public function test_a_closed_accounts_invoice_pdf_is_not_reached_by_its_login(): void
    {
        $user = User::factory()->create();
        $open = $user->clients()->sole();
        $invoice = $this->closedInvoice($user);
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $open->id]));

        $this->assertContains($this->get(route('invoices.pdf', $invoice))->status(), [403, 404]);
    }

    public function test_staff_in_login_as_client_still_open_a_closed_accounts_invoice(): void
    {
        $user = User::factory()->create();
        $invoice = $this->closedInvoice($user);
        $this->actingAs(Staff::factory()->withPermissions(['*'])->create(), 'staff');
        $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $invoice->client_id, 'impersonating' => $user->id,
            CurrentClient::IMPERSONATING_KEY => $invoice->client_id]));

        $this->get(route('invoices.show', $invoice))->assertOk();
    }
}
