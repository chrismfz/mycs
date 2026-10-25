<?php

namespace Tests\Feature;

use App\Admin\Resources\InvoiceResource\Pages\EditInvoice;
use App\Admin\Resources\InvoiceResource\Pages\ListInvoices;
use App\Admin\Resources\InvoiceResource\RelationManagers\EkdosiDocumentsRelationManager;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Support\CurrentClient;
use App\Support\EkdosiNotice;
use Filament\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Ekdosi bridge, E2 (docs/ekdosi-bridge.md): the fiscal documents Ekdosi wrote back, shown to staff (the invoice's
 * tab, a list column and filter) and to the customer; staff told before they cancel or refund; the invoice never deleted.
 */
class EkdosiDocumentsUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.ekdosi_enabled' => true, 'settings.default_currency' => 'USD']);
        Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->user = User::factory()->create();
        $this->client = $this->user->client();
    }

    private function staff(): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'E2' . uniqid(), 'permissions' => ['*']])->id]);
    }

    private function asCustomer(User $user, Client $client): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $client->id]));
    }

    private function paidInvoice(): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', '100.00', transactionId: 'TX-' . $invoice->id);

        return $invoice->fresh();
    }

    private function document(Invoice $invoice, array $attributes = []): EkdosiDocument
    {
        return EkdosiDocument::query()->create($attributes + ['invoice_id' => $invoice->id, 'mark' => '400001234567890', 'code' => 'ΤΠΥ423',
            'state' => EkdosiDocument::ACTIVE, 'pdf_url' => 'https://ekdosi.example.test/p/abc.pdf']);
    }

    public function test_staff_see_the_documents_on_the_invoice(): void
    {
        $invoice = $this->paidInvoice();
        $this->document($invoice);
        $this->document($invoice, ['mark' => '400001234567891', 'code' => 'ΤΠΥ424', 'state' => EkdosiDocument::CANCELLED, 'pdf_url' => null]);

        $this->livewireAs($this->staff())
            ->test(EkdosiDocumentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => EditInvoice::class])
            ->assertSuccessful()
            ->assertSee('ΤΠΥ423')->assertSee('400001234567890')
            ->assertSee('ΤΠΥ424')->assertSee('https://ekdosi.example.test/p/abc.pdf', false);
    }

    public function test_the_tab_shows_only_where_the_bridge_is_on_or_there_are_documents(): void
    {
        $invoice = $this->paidInvoice();
        $this->actingAs($this->staff(), 'staff');
        $this->assertTrue(EkdosiDocumentsRelationManager::canViewForRecord($invoice, EditInvoice::class));

        config(['settings.ekdosi_enabled' => false]);
        $this->assertFalse(EkdosiDocumentsRelationManager::canViewForRecord($invoice, EditInvoice::class), 'nothing to show for a nixpal');
        $this->document($invoice);
        $this->assertTrue(EkdosiDocumentsRelationManager::canViewForRecord($invoice, EditInvoice::class), 'documents stay visible');
    }

    public function test_the_list_shows_and_filters_the_document(): void
    {
        $issued = $this->paidInvoice();
        $this->document($issued);
        $waiting = $this->paidInvoice();
        $page = $this->livewireAs($this->staff())->test(ListInvoices::class);

        $page->assertTableColumnExists('ekdosi_document')->assertSee('ΤΠΥ423');
        $open = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $page->filterTable('ekdosi_document', 'without')->assertCanSeeTableRecords([$waiting])->assertCanNotSeeTableRecords([$issued, $open]);
        $page->filterTable('ekdosi_document', 'with')->assertCanSeeTableRecords([$issued])->assertCanNotSeeTableRecords([$waiting]);
    }

    public function test_the_customer_sees_the_issued_document_and_its_pdf(): void
    {
        $invoice = $this->paidInvoice();
        $this->document($invoice);
        $this->document($invoice, ['mark' => '400001234567891', 'code' => 'ΤΠΥ424', 'state' => EkdosiDocument::CANCELLED,
            'pdf_url' => 'https://ekdosi.example.test/p/cancelled.pdf']);

        $this->asCustomer($this->user, $this->client)->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('ΤΠΥ423')->assertSee('400001234567890')
            ->assertSee('https://ekdosi.example.test/p/abc.pdf', false)
            ->assertSee('ΤΠΥ424')
            ->assertDontSee('https://ekdosi.example.test/p/cancelled.pdf', false);

        // Another account's customer never sees it.
        $stranger = User::factory()->create();
        $this->asCustomer($stranger, $stranger->client())->get(route('invoices.show', $invoice))->assertNotFound();
    }

    public function test_no_documents_no_section_for_the_customer(): void
    {
        $invoice = $this->paidInvoice();

        $this->asCustomer($this->user, $this->client)->get(route('invoices.show', $invoice))->assertOk()->assertDontSee(__('invoices.fiscal_documents'));
    }

    /** Review of #462: the admin's Delete says why, never a 500; a customer with such an invoice is not deleted. */
    public function test_the_admin_delete_and_the_customers_delete_refuse_with_a_message(): void
    {
        $unpaid = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $unpaid->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
        $this->document($unpaid);

        $this->livewireAs($this->staff())->test(EditInvoice::class, ['record' => $unpaid->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified(__('Invoice not deleted'));
        $this->assertNotNull($unpaid->fresh());

        try {
            $this->client->delete();
            $this->fail('the customer was deleted, its invoice left with no one');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('Ekdosi', $e->getMessage());
        }
        $this->assertSame($this->client->id, (int) $unpaid->fresh()->client_id);
    }

    public function test_staff_are_told_before_they_cancel_or_refund_and_the_invoice_is_never_deleted(): void
    {
        $invoice = $this->paidInvoice();
        $this->assertNull(EkdosiNotice::forInvoice($invoice));
        $this->document($invoice);
        $this->assertStringContainsString('ΤΠΥ423', (string) EkdosiNotice::forInvoice($invoice));
        $this->document($invoice, ['mark' => '400001234567891', 'code' => 'ΤΠΥ424', 'state' => EkdosiDocument::CANCELLED]);
        $this->assertStringNotContainsString('ΤΠΥ424', (string) EkdosiNotice::forInvoice($invoice), 'a cancelled one needs no credit note');

        $unpaid = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $this->document($unpaid, ['mark' => '400001234567899']);
        $this->expectException(DisplayException::class);
        $unpaid->delete();
    }
}
