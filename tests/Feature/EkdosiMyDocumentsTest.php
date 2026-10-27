<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Tests\TestCase;

/**
 * «Τα παραστατικά μου» (E2b, docs/ekdosi-bridge.md), as the WHMCS plugin's page: the account's fiscal documents from
 * Ekdosi in one list, each with its MARK, its PDF and the invoice it came from; in the menu once there is one.
 */
class EkdosiMyDocumentsTest extends TestCase
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

    private function asCustomer(User $user, Client $client): static
    {
        return $this->actingAs($user)->withSession($this->loginUser($user, [CurrentClient::SESSION_KEY => $client->id]));
    }

    private function paidInvoice(Client $client): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', '100.00', transactionId: 'TX-' . $invoice->id);

        return $invoice->fresh();
    }

    private function document(Invoice $invoice, array $attributes = []): EkdosiDocument
    {
        return EkdosiDocument::query()->create($attributes + ['invoice_id' => $invoice->id, 'mark' => '400001234567890', 'code' => 'ΤΠΥ423',
            'state' => EkdosiDocument::ACTIVE, 'pdf_url' => 'https://ekdosi.example.test/p/abc.pdf']);
    }

    public function test_the_customer_sees_every_document_of_the_account(): void
    {
        $first = $this->paidInvoice($this->client);
        $second = $this->paidInvoice($this->client);
        $this->document($first);
        $this->document($second, ['mark' => '400001234567891', 'code' => 'ΑΛΠ12', 'pdf_url' => 'https://ekdosi.example.test/p/def.pdf']);
        $this->document($second, ['mark' => '400001234567892', 'code' => 'ΤΠΥ424', 'state' => EkdosiDocument::CANCELLED,
            'pdf_url' => 'https://ekdosi.example.test/p/cancelled.pdf']);

        $this->asCustomer($this->user, $this->client)->get(route('documents'))->assertOk()
            ->assertSee(__('invoices.my_documents'))
            ->assertSeeInOrder(['ΤΠΥ424', 'ΑΛΠ12', 'ΤΠΥ423']) // newest first
            ->assertSee('400001234567890')->assertSee('400001234567891')
            ->assertSee('https://ekdosi.example.test/p/abc.pdf', false)
            ->assertSee('https://ekdosi.example.test/p/def.pdf', false)
            ->assertDontSee('https://ekdosi.example.test/p/cancelled.pdf', false) // a cancelled one is not the legal document
            ->assertSee(route('invoices.show', $first), false)
            ->assertSee(route('invoices.show', $second), false);
    }

    public function test_another_accounts_documents_are_never_listed(): void
    {
        $this->document($this->paidInvoice($this->client));
        $stranger = User::factory()->create();

        $this->asCustomer($stranger, $stranger->client())->get(route('documents'))->assertOk()
            ->assertDontSee('400001234567890')
            ->assertSee(__('invoices.no_documents'));
    }

    public function test_it_is_in_the_menu_once_there_is_a_document_and_with_the_bridge_on(): void
    {
        // The menu is read once per request (once()); a test's requests share one process.
        $dashboard = function () {
            Once::flush();

            return $this->asCustomer($this->user, $this->client)->get(route('dashboard'))->assertOk();
        };

        $dashboard()->assertDontSee(route('documents'), false);

        $this->document($this->paidInvoice($this->client));
        $dashboard()->assertSee(route('documents'), false);

        config(['settings.ekdosi_enabled' => false]);
        $dashboard()->assertDontSee(route('documents'), false);
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get(route('documents'))->assertRedirect();
    }
}
