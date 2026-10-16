<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Pages\ClientSummaryPage;
use App\Admin\Resources\InvoiceResource;
use App\Admin\Resources\InvoiceResource\Pages\CreateInvoice;
use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Enums\AdjustmentNoteType;
use App\Models\Contact;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Admin\ClientSummary;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of PR #154 (the client's «Summary»). F1–F3 failed on dd09386 and are fixed; the others are
 * regression tests of what was checked and holds.
 */
class ClientSummaryAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->user = User::factory()->create(['first_name' => 'Eve', 'last_name' => 'Adversary']);
        DB::table('invoices')->where('client_id', $this->user->client()->id)->delete();
    }

    private function staff(array $permissions, bool $active = true): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id, 'active' => $active]);
    }

    private function invoice(float $price, string $currency = 'USD'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => $currency, 'due_at' => now()]);
        $invoice->items()->create(['description' => 'Line', 'quantity' => 1, 'price' => $price]);

        return $invoice->fresh();
    }

    // ---- Findings ---------------------------------------------------------------------------------------------------

    /** F1: a cancelled invoice gets a cancellation credit note of its whole total, so the Summary sums 0.00 for it. */
    public function test_cancelled_invoices_are_summed_as_issued(): void
    {
        $invoice = $this->invoice(50);
        $invoice->update(['status' => Invoice::STATUS_CANCELLED]); // as staff cancel it: the observer adds the note

        $line = ClientSummary::for($this->user->client()->fresh(), $this->staff(['*']))['invoices']['statuses'][Invoice::STATUS_CANCELLED];

        $this->assertSame(1, $line['count']);
        $this->assertStringContainsString('50.00', implode(' ', $line['total']), 'WHMCS shows the cancelled invoices\' amount, not 0.00');
    }

    /** F1: a refunded invoice carries refund credit notes of everything paid, so the Summary sums 0.00 for it. */
    public function test_refunded_invoices_are_summed_as_issued(): void
    {
        $invoice = $this->invoice(30);
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -30, 'description' => 'Refund', 'is_refund' => true]);
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => Invoice::STATUS_REFUNDED]); // as afterRefund leaves it

        $line = ClientSummary::for($this->user->client()->fresh(), $this->staff(['*']))['invoices']['statuses'][Invoice::STATUS_REFUNDED];

        $this->assertSame(1, $line['count']);
        $this->assertStringContainsString('30.00', implode(' ', $line['total']), 'the refunded amount, not 0.00');
    }

    /**
     * F2: «Login as client» moved onto a page that needs only clients.viewAny. Before, it was on Edit Client
     * (clients.update) and Edit User (users.update); now a view-only role with users.impersonate acts as the customer.
     */
    public function test_view_only_staff_cannot_login_as_client_from_the_summary(): void
    {
        $client = $this->user->client();
        $this->assertNotNull($client->owner());

        $this->livewireAs($this->staff(['admin.clients.viewAny', 'admin.users.impersonate']))
            ->test(ClientSummaryPage::class, ['record' => $client->getRouteKey()])
            ->assertActionHidden('impersonate'); // impersonating needs what the Edit page needed (clients.update)
        $this->livewireAs($this->staff(['admin.clients.viewAny', 'admin.clients.update', 'admin.users.impersonate']))
            ->test(ClientSummaryPage::class, ['record' => $client->getRouteKey()])
            ->assertActionVisible('impersonate');

        $this->assertNull(session('impersonating'));
    }

    /** F3: a legacy account without a primary contact shows «—» everywhere although its owner is known (Edit makes one). */
    public function test_a_client_without_primary_contact_shows_its_owners_details(): void
    {
        $client = $this->user->client();
        Contact::query()->where('primary_of_client_id', $client->id)->delete();

        $details = ClientSummary::for($client->fresh(), $this->staff(['*']))['details'];

        $this->assertSame($this->user->email, $details['email'], 'falls back to the owner, as Client::name/email do');
    }

    // ---- Checked and holds (regression) -------------------------------------------------------------------------------

    public function test_hidden_actions_cannot_be_called_by_view_only_staff(): void
    {
        $client = $this->user->client();
        $page = $this->livewireAs($this->staff(['admin.clients.viewAny']))->test(ClientSummaryPage::class, ['record' => $client->getRouteKey()]);
        $page->call('mountAction', 'impersonate')->call('callMountedAction'); // a crafted Livewire request
        $this->assertNull(session('impersonating'));
        foreach (['newInvoice', 'newOrder', 'newTicket', 'credit', 'edit'] as $action) {
            $page->assertActionHidden($action);
        }
    }

    public function test_inactive_staff_and_staff_without_clients_view_are_refused(): void
    {
        $url = ClientResource::getUrl('view', ['record' => $this->user->client()]);
        $this->actingAs($this->staff(['*'], active: false))->get($url)->assertForbidden();
        $this->actingAs($this->staff(['admin.invoices.viewAny', 'admin.credits.viewAny']))->get($url)->assertForbidden();
    }

    public function test_client_controlled_fields_are_escaped(): void
    {
        $client = $this->user->client();
        $xss = '<script>alert(1)</script>';
        $client->primaryContact()->update(['first_name' => $xss, 'company_name' => $xss, 'address1' => $xss, 'city' => $xss]);
        $product = $this->createProduct();
        Service::factory()->create(['client_id' => $client->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 1, 'quantity' => 1, 'domain' => $xss]);
        EmailLog::query()->create(['subject' => $xss, 'to' => 'e@example.test', 'body' => 'b', 'status' => 'failed', 'client_id' => $client->id]);

        $this->actingAs($this->staff(['*']))->get(ClientResource::getUrl('view', ['record' => $client]))
            ->assertOk()->assertDontSee($xss, false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_a_client_without_owner_or_contact_renders_and_cannot_be_impersonated(): void
    {
        $client = $this->user->client();
        Contact::query()->where('primary_of_client_id', $client->id)->delete();
        DB::table('client_user')->where('client_id', $client->id)->delete();

        $this->actingAs($this->staff(['*']))->get(ClientResource::getUrl('view', ['record' => $client]))->assertOk();
        $this->livewireAs($this->staff(['*']))->test(ClientSummaryPage::class, ['record' => $client->getRouteKey()])
            ->assertActionDisabled('impersonate')->call('mountAction', 'impersonate')->call('callMountedAction');
        $this->assertNull(session('impersonating'));
    }

    public function test_amounts_are_kept_per_currency_and_due_is_net_of_partial_payments_and_refunds(): void
    {
        $client = $this->user->client();
        $usd = $this->invoice(20);
        $usd->transactions()->create(['amount' => 8, 'refunded_amount' => 3]); // 5 kept
        $this->invoice(10, 'EUR');
        Credit::query()->create(['client_id' => $client->id, 'currency_code' => 'USD', 'amount' => '1.10']);
        Credit::query()->create(['client_id' => $client->id, 'currency_code' => 'EUR', 'amount' => '2.20']);

        $summary = ClientSummary::for($client->fresh(), $this->staff(['*']));

        $due = implode(' | ', $summary['invoices']['due']);
        $this->assertStringContainsString('15.00', $due);
        $this->assertStringContainsString('10.00', $due);
        $this->assertCount(2, $summary['invoices']['due'], 'never summed across currencies');
        $this->assertCount(2, $summary['credit']);
    }

    public function test_client_id_prefill_does_not_override_an_edit_page_and_an_unknown_id_is_not_accepted(): void
    {
        $other = User::factory()->create()->client();
        $invoice = $this->invoice(5);

        request()->merge(['client_id' => $other->id]); // as ?client_id= on the Edit page's URL
        $edit = $this->livewireAs($this->staff(['*']))->test(EditInvoice::class, ['record' => $invoice->getKey()]);
        $this->assertEquals($this->user->client()->id, $edit->get('data.client_id'));
        request()->offsetUnset('client_id');

        Livewire::withQueryParams(['client_id' => 999999])->actingAs($this->staff(['*']), 'staff')->test(CreateInvoice::class)
            ->call('create')->assertHasFormErrors(['client_id']);
        $this->actingAs($this->staff(['*']))->get(InvoiceResource::getUrl('create', ['client_id' => 999999]))->assertOk();
    }
}
