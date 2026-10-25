<?php

namespace Tests\Feature;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Enums\AdjustmentNoteType;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Ekdosi bridge, E1 (docs/ekdosi-bridge.md): Ekdosi reads the paid invoices in its WHMCS plugin's shape, with each
 * line's recipient and the account's fiscal details outright, and writes back the documents it issued; signed both ways
 * with the shared secret, and nothing at all while the knob is off.
 */
class EkdosiBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-test-secret-of-at-least-thirty-two-chars';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.ekdosi_enabled' => true, 'settings.ekdosi_secret' => self::SECRET, 'settings.ekdosi_url' => 'https://ekdosi.example.test/ekdosi']);
        Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->client = User::factory()->create()->client();
        $this->client->primaryContact->forceFill(['type' => 'business', 'company_name' => 'Δοκιμή ΑΕ', 'tax_id' => '099999999',
            'tax_office' => 'Α΄ Αθηνών', 'activity' => 'Hosting', 'document_type' => 'invoice', 'country' => 'GR'])->save();
    }

    /** @param array<string, mixed> $body */
    private function bridge(array $body, ?string $secret = self::SECRET): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = $secret === null ? [] : ['X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $raw, $secret)];

        return $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']), $raw);
    }

    private function paidInvoice(?Client $client = null, string $price = '100.00'): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => ($client ?? $this->client)->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', $price, transactionId: 'TX-' . $invoice->id);

        return $invoice->fresh();
    }

    public function test_off_or_unsigned_it_answers_nothing(): void
    {
        $this->paidInvoice();
        $this->bridge(['op' => 'invoices'], 'another-secret-of-at-least-thirty-two-chars')->assertStatus(401)->assertExactJson(['error' => 'invalid_signature']);
        $this->bridge(['op' => 'invoices'], null)->assertStatus(401);

        config(['settings.ekdosi_secret' => '']);
        $this->bridge(['op' => 'invoices'])->assertStatus(422)->assertJsonPath('error', 'secret_not_configured');

        config(['settings.ekdosi_enabled' => false, 'settings.ekdosi_secret' => self::SECRET]);
        $this->bridge(['op' => 'invoices'])->assertStatus(404)->assertJsonMissingPath('invoices');
    }

    public function test_ping_and_unknown_ops(): void
    {
        $this->bridge(['op' => 'ping'])->assertOk()->assertJsonPath('source', 'mycs');
        $this->bridge(['op' => 'drop_tables'])->assertStatus(400)->assertJsonPath('error', 'unknown_op');
        $this->bridge(['op' => 'invoices', 'status' => 'Whatever'])->assertStatus(400)->assertJsonPath('error', 'bad_request');
        $this->bridge(['op' => 'invoices', 'since' => 'yesterday; DROP'])->assertStatus(400);
    }

    public function test_the_inbox_feed_gives_paid_invoices_in_the_plugins_shape_with_the_fiscal_details(): void
    {
        $paid = $this->paidInvoice();
        Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);

        $response = $this->bridge(['op' => 'invoices'])->assertOk()->assertJsonPath('count', 1);
        $invoice = $response->json('invoices.0');

        $this->assertSame($paid->id, $invoice['invoiceid']);
        $this->assertSame(['Paid', 'EUR', 'Exclusive', 'banktransfer', '100.00', '100.00'],
            [$invoice['status'], $invoice['currencycode'], $invoice['taxtype'], $invoice['paymentmethod'], $invoice['subtotal'], $invoice['total']]);
        $this->assertSame('Δοκιμή ΑΕ', $invoice['companyname']);
        $this->assertSame([], $invoice['customfields']);
        $this->assertSame(['099999999', 'Α΄ Αθηνών', 'Hosting', 'invoice'], [$invoice['mycs']['recipient']['gr_vatno'],
            $invoice['mycs']['recipient']['tax_office'], $invoice['mycs']['recipient']['description'], $invoice['mycs']['recipient']['document_type']]);
        $this->assertFalse($invoice['mycs']['immediate_invoicing']);
        $this->assertSame('TX-' . $paid->id, $invoice['transactions'][0]['transid']);
        $line = $invoice['items']['item'][0];
        $this->assertSame(['Hosting', '100.00', 1], [$line['description'], $line['amount'], $line['quantity']]);
        $this->assertSame((float) $line['total'], round((float) $line['amount'] + (float) $line['tax_amount'], 2));
        $this->assertFalse($invoice['third_party']['summary']['multi_party']);
    }

    public function test_an_account_with_ignore_invoicing_is_never_in_the_feed_and_immediate_invoicing_is_said(): void
    {
        $this->paidInvoice();
        $quiet = User::factory()->create()->client();
        $quiet->forceFill(['ignore_invoicing' => true])->save();
        $hidden = $this->paidInvoice($quiet);
        $this->client->forceFill(['immediate_invoicing' => true])->save();

        $response = $this->bridge(['op' => 'invoices'])->assertOk()->assertJsonPath('count', 1);
        $this->assertTrue($response->json('invoices.0.mycs.immediate_invoicing'));
        // Asked for by id, it comes, with the flag: Ekdosi decides.
        $this->bridge(['op' => 'invoice', 'invoice_id' => $hidden->id])->assertOk()->assertJsonPath('invoice.mycs.ignore_invoicing', true);
    }

    public function test_since_bounds_the_feed_by_the_payment_date(): void
    {
        $old = $this->paidInvoice();
        $old->forceFill(['paid_at' => now()->subMonths(2)])->save();
        $new = $this->paidInvoice();

        $ids = collect($this->bridge(['op' => 'invoices', 'since' => now()->subWeek()->toDateString()])->json('invoices'))->pluck('id')->all();
        $this->assertSame([$new->id], $ids);
    }

    public function test_each_line_goes_to_the_related_contact_of_its_service_or_domain(): void
    {
        $agencyCustomer = Contact::query()->forceCreate(['client_id' => $this->client->id, 'type' => 'business', 'company_name' => 'Πελάτης Πρακτορείου ΟΕ',
            'tax_id' => '088888888', 'tax_office' => 'Β΄ Πειραιά', 'document_type' => 'receipt']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'contact_id' => $agencyCustomer->id, 'currency_code' => 'EUR', 'price' => 10, 'quantity' => 1]);
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'contact_id' => $agencyCustomer->id]);
        $own = Service::factory()->create(['client_id' => $this->client->id, 'contact_id' => $this->client->primaryContact->id, 'currency_code' => 'EUR', 'price' => 10, 'quantity' => 1]);

        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'Hosting for the agency\'s customer', 'quantity' => 1, 'price' => '10.00', 'reference_type' => Service::class, 'reference_id' => $service->id]);
        $invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => '12.00', 'reference_type' => Domain::class, 'reference_id' => $domain->id]);
        $invoice->items()->create(['description' => 'Own hosting', 'quantity' => 1, 'price' => '10.00', 'reference_type' => Service::class, 'reference_id' => $own->id]);
        $invoice->items()->create(['description' => 'A fee', 'quantity' => 1, 'price' => '1.00']);

        $routing = $this->bridge(['op' => 'resolve', 'invoice_id' => $invoice->id])->assertOk()->json();
        $lines = collect($routing['lines']);
        $this->assertSame([true, true, false, false], $lines->pluck('routed')->all());
        $this->assertSame(['hosting', 'domain', 'hosting', null], $lines->pluck('service_type')->all());
        $this->assertSame(['Hosting', 'Domain', 'Hosting', ''], $lines->pluck('type')->all());
        $this->assertSame('088888888', $lines[0]['contact']['gr_vatno']);
        $this->assertTrue($lines[0]['is_receipt'], 'the contact asks for a receipt');
        $this->assertSame(['line_count' => 4, 'routed_lines' => 2, 'unrouted_lines' => 2, 'distinct_parties' => 2, 'multi_party' => true], $routing['summary']);

        // Not a contact of the invoice's account (left from before a move): not a recipient.
        $stranger = Contact::query()->forceCreate(['client_id' => User::factory()->create()->client()->id, 'company_name' => 'Ξένος']);
        Service::query()->whereKey($service->id)->update(['contact_id' => $stranger->id]); // past the observer's check, as old data
        $this->assertFalse($this->bridge(['op' => 'resolve', 'invoice_id' => $invoice->id])->json('lines.0.routed'));
    }

    public function test_staff_set_a_domains_related_contact_only_among_its_accounts_contacts(): void
    {
        $contact = Contact::query()->forceCreate(['client_id' => $this->client->id, 'company_name' => 'Κάτοχος']);
        $stranger = Contact::query()->forceCreate(['client_id' => User::factory()->create()->client()->id, 'company_name' => 'Ξένος']);
        $domain = Domain::factory()->create(['client_id' => $this->client->id]);
        $staff = fn (array $permissions) => Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        $action = TestAction::make('relatedContact')->schemaComponent('contacts_related_actions', 'infolist');
        $page = fn (Staff $as) => $this->livewireAs($as)->test(ViewDomain::class, ['record' => $domain->getRouteKey()]);

        $page($staff(['admin.domains.viewAny', 'admin.domains.update']))->callAction($action, ['contact_id' => (string) $contact->id]);
        $this->assertSame($contact->id, $domain->fresh()->contact_id);
        $this->assertNull($domain->fresh()->registrant_contact_id, 'not a role at the registrar');

        $page($staff(['admin.domains.viewAny', 'admin.domains.update']))->callAction($action, ['contact_id' => (string) $stranger->id]);
        $this->assertSame($contact->id, $domain->fresh()->contact_id, 'another account\'s contact is refused');

        // Without the permission the action is not there at all.
        try {
            $page($staff(['admin.domains.viewAny']))->callAction($action, ['contact_id' => '']);
        } catch (ActionNotResolvableException) {
        }
        $this->assertSame($contact->id, $domain->fresh()->contact_id);
    }

    /** Review of #459: an archived contact is retired, the account is billed; and a page costs the same few queries. */
    public function test_an_archived_contact_is_no_recipient_and_a_page_reads_in_a_few_queries(): void
    {
        $contact = Contact::query()->forceCreate(['client_id' => $this->client->id, 'company_name' => 'Παλιός πελάτης']);
        $make = function () use ($contact) {
            $service = Service::factory()->create(['client_id' => $this->client->id, 'contact_id' => $contact->id, 'currency_code' => 'EUR', 'price' => 10, 'quantity' => 1]);
            $domain = Domain::factory()->create(['client_id' => $this->client->id, 'contact_id' => $contact->id]);
            $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
            $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '10.00', 'reference_type' => Service::class, 'reference_id' => $service->id]);
            $invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => '12.00', 'reference_type' => Domain::class, 'reference_id' => $domain->id]);
            ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', '22.00');
        };
        $make();
        $queries = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->bridge(['op' => 'invoices'])->assertOk();

            return count(DB::getQueryLog());
        };
        $one = $queries();
        foreach (range(1, 5) as $ignored) {
            $make();
        }
        $this->assertSame($one, $queries(), 'six invoices read in as many queries as one');

        $this->assertTrue($this->bridge(['op' => 'invoices'])->json('invoices.0.third_party.lines.0.routed'));
        $contact->forceFill(['archived_at' => now()])->save();
        $this->assertFalse($this->bridge(['op' => 'invoices'])->json('invoices.0.third_party.summary.multi_party'));
    }

    public function test_a_domain_moved_to_another_account_leaves_its_related_contact(): void
    {
        $contact = Contact::query()->forceCreate(['client_id' => $this->client->id, 'company_name' => 'Κάτοχος']);
        $domain = Domain::factory()->create(['client_id' => $this->client->id, 'contact_id' => $contact->id]);
        $to = User::factory()->create()->client();

        app(MoveToClient::class)->domain($domain, $to, Staff::factory()->create());

        $this->assertNull($domain->fresh()->contact_id);
    }

    public function test_the_write_back_takes_the_invoice_out_of_the_feed_and_is_idempotent(): void
    {
        $invoice = $this->paidInvoice();
        $body = ['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890', 'invcode' => 'ΤΠΥ423',
            'pdf_url' => 'https://ekdosi.example.test/ekdosi/p/abc.pdf'];

        $this->bridge($body)->assertOk()->assertJsonPath('created', true);
        $this->bridge($body)->assertOk()->assertJsonPath('created', false);
        $document = EkdosiDocument::query()->sole();
        $this->assertSame([$invoice->id, 'ΤΠΥ423', 'active', 'https://ekdosi.example.test/ekdosi/p/abc.pdf'],
            [$document->invoice_id, $document->code, $document->state, $document->pdf_url]);
        $this->bridge(['op' => 'invoices'])->assertOk()->assertJsonPath('count', 0);

        // Cancelled at AADE: the same MARK again; what is not sent stays.
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890', 'state' => 'cancelled'])->assertOk();
        $document->refresh();
        $this->assertSame(['cancelled', 'ΤΠΥ423'], [$document->state, $document->code]);

        // A split: a second document for the same invoice.
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567891'])->assertOk();
        $this->assertSame(2, $invoice->ekdosiDocuments()->count());
        $this->assertCount(2, $this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->json('invoice.mycs.documents'));
    }

    /** R2-3 of #459: a new document is taken only for the invoice as Ekdosi read it (its fingerprint). */
    public function test_a_document_for_an_invoice_changed_since_it_was_read_is_refused(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
        $read = $this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->json('invoice.mycs.fingerprint');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $read);

        $invoice->items()->create(['description' => 'Added after', 'quantity' => 1, 'price' => '5.00']);
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890', 'fingerprint' => $read])
            ->assertStatus(409)->assertJsonPath('error', 'invoice_changed');

        $again = $this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->json('invoice.mycs.fingerprint');
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890', 'fingerprint' => $again])->assertOk();

        // Filed: its lines stay as they are, by staff, the API or the app.
        $this->expectException(DisplayException::class);
        $invoice->items()->create(['description' => 'Later still', 'quantity' => 1, 'price' => '1.00']);
    }

    /** Final review of #459: a filed invoice keeps its customer, currency and total; a note changes its fingerprint. */
    public function test_a_filed_invoice_keeps_its_customer_currency_and_total(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
        $read = $this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->json('invoice.mycs.fingerprint');
        $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -40, 'description' => 'Goodwill']);
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890', 'fingerprint' => $read])
            ->assertStatus(409)->assertJsonPath('error', 'invoice_changed');
        $again = $this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->json('invoice.mycs.fingerprint');
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890', 'fingerprint' => $again])->assertOk();

        $refused = 0;
        foreach ([
            fn () => $invoice->fresh()->update(['client_id' => User::factory()->create()->client()->id]),
            fn () => $invoice->fresh()->update(['currency_code' => 'USD']),
            fn () => $invoice->adjustmentNotes()->create(['type' => AdjustmentNoteType::Credit->value, 'amount' => -10, 'description' => 'More']),
        ] as $change) {
            try {
                $change();
            } catch (DisplayException) {
                $refused++;
            }
        }
        $this->assertSame(3, $refused);
        $this->assertSame([$this->client->id, 'EUR', 1], [(int) $invoice->fresh()->client_id, $invoice->fresh()->currency_code, $invoice->adjustmentNotes()->count()]);
    }

    public function test_the_write_back_refuses_what_does_not_add_up(): void
    {
        $invoice = $this->paidInvoice();
        $other = $this->paidInvoice();
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890'])->assertOk();

        $this->bridge(['op' => 'mark', 'invoice_id' => $other->id, 'mark' => '400001234567890'])->assertStatus(409)
            ->assertJsonPath('error', 'mark_on_another_invoice');
        $this->bridge(['op' => 'mark', 'invoice_id' => 999999, 'mark' => '400001234567892'])->assertStatus(404);
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '40000<script>'])->assertStatus(400);
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id])->assertStatus(400);
        $this->assertSame(1, EkdosiDocument::query()->count());
    }

    public function test_a_pdf_link_elsewhere_than_ekdosi_is_left_out(): void
    {
        $invoice = $this->paidInvoice();
        foreach (['https://evil.example.test/p.pdf', 'http://ekdosi.example.test/ekdosi/p.pdf', 'javascript:alert(1)',
            'https://ekdosi.example.test/elsewhere/p.pdf', 'https://ekdosi.example.test:8443/ekdosi/p.pdf'] as $i => $url) {
            $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '50000000000000' . $i, 'pdf_url' => $url])->assertOk();
        }
        $this->assertSame([null], EkdosiDocument::query()->pluck('pdf_url')->unique()->values()->all());

        config(['settings.ekdosi_url' => '']);
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '600000000000000', 'pdf_url' => 'https://ekdosi.example.test/p.pdf'])->assertOk();
        $this->assertNull(EkdosiDocument::query()->where('mark', '600000000000000')->value('pdf_url'), 'no address set: no link kept');
    }
}
