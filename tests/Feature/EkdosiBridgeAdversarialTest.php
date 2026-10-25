<?php

namespace Tests\Feature;

use App\Admin\Resources\DomainResource\Pages\ViewDomain;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Contact;
use App\Models\Domain;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\SplitInvoice;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Adversarial review of #459 (the Ekdosi bridge, E1). Written without the author's context.
 *
 * - ADV-* tests FAIL on the PR's head: each demonstrates a break (see the PR comment for the table).
 * - HELD-* tests pass: attacks that did not work, kept to pin the guarantee.
 */
class EkdosiBridgeAdversarialTest extends TestCase
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
            'tax_office' => 'Α΄ Αθηνών', 'document_type' => 'invoice', 'country' => 'GR'])->save();
    }

    private function raw(string $raw, ?string $signature): TestResponse
    {
        $headers = $signature === null ? [] : ['X-Webhook-Signature' => $signature];

        return $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']), $raw);
    }

    private function signed(string $raw): TestResponse
    {
        return $this->raw($raw, 'sha256=' . hash_hmac('sha256', $raw, self::SECRET));
    }

    /** @param array<string, mixed> $body */
    private function bridge(array $body): TestResponse
    {
        return $this->signed(json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function unpaidInvoice(?Client $client = null, string $price = '100.00', ?Service $service = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => ($client ?? $this->client)->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]
            + ($service === null ? [] : ['reference_type' => Service::class, 'reference_id' => $service->id]));

        return $invoice->fresh();
    }

    private function paidInvoice(?Client $client = null, string $price = '100.00'): Invoice
    {
        $invoice = $this->unpaidInvoice($client, $price);
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', $price, transactionId: 'TX-' . $invoice->id);

        return $invoice->fresh();
    }

    // ---------------------------------------------------------------------------------------------------------------
    // ADV: breaks (these fail on 8ff20c1c)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * ADV-1. PHP's parse_url reads `https://evil.example\@ekdosi.example.test/p.pdf` as host ekdosi.example.test (the
     * part before `@` is userinfo), but every browser (WHATWG URL) reads the backslash as a slash: host evil.example.
     * The «only a link on Ekdosi» guarantee of EkdosiDocuments::pdfUrl is bypassed; the link E2 shows to staff and
     * customers ("Εκδοθέντα παραστατικά") would open evil.example.
     */
    public function test_adv1_a_pdf_link_a_browser_opens_elsewhere_is_left_out(): void
    {
        $invoice = $this->paidInvoice();
        $tricks = [
            'https://evil.example\\@ekdosi.example.test/p.pdf',
            'https://evil.example\\\\@ekdosi.example.test/p.pdf',
        ];
        foreach ($tricks as $i => $url) {
            $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '70000000000000' . $i, 'pdf_url' => $url])->assertOk();
        }

        $this->assertSame([null], EkdosiDocument::query()->pluck('pdf_url')->unique()->values()->all(),
            'a backslash before the userinfo sends the browser to evil.example');
    }

    /**
     * ADV-2a. An unpaid invoice Ekdosi already issued a document for (on credit: E3's case) is still merged into another
     * one (InvoiceLineMove::refusal knows nothing of ekdosi_documents). The merged invoice carries the same lines with no
     * document, so once paid it shows in the Inbox again and Ekdosi issues a second fiscal document for the same sale.
     */
    public function test_adv2a_an_invoice_with_a_fiscal_document_is_not_merged_away(): void
    {
        $filed = $this->unpaidInvoice();
        $target = $this->unpaidInvoice(price: '50.00');
        $this->bridge(['op' => 'mark', 'invoice_id' => $filed->id, 'mark' => '400001234567890'])->assertOk();

        $refused = false;
        try {
            app(MergeInvoices::class)->handle($target->id, [$filed->id]);
        } catch (DisplayException) {
            $refused = true;
        }

        if (!$refused) {
            ExtensionHelper::addPayment($target->fresh(), 'BankTransfer', '150.00', transactionId: 'TX-merged');
            $inbox = collect($this->bridge(['op' => 'invoices'])->json('invoices'))->pluck('id')->all();
            $this->assertNotContains($target->id, $inbox, 'the lines of a document already issued come back in the Inbox: a second document for the same sale');
        }
        $this->assertTrue($refused, 'an invoice with a fiscal document must not be merged');
    }

    /** ADV-2b. The same with a split: the lines moved to the new invoice were already on a fiscal document. */
    public function test_adv2b_an_invoice_with_a_fiscal_document_is_not_split(): void
    {
        $filed = $this->unpaidInvoice();
        $filed->items()->create(['description' => 'Second line', 'quantity' => 1, 'price' => '20.00']);
        $this->bridge(['op' => 'mark', 'invoice_id' => $filed->id, 'mark' => '400001234567890'])->assertOk();

        $this->expectException(DisplayException::class);
        app(SplitInvoice::class)->handle($filed->id, [(int) $filed->items()->orderByDesc('id')->value('id')]);
    }

    /**
     * ADV-2c. MoveToClient (touched by this PR) moves a whole unpaid invoice with its fiscal document to another account:
     * the new account's invoice now carries a document issued to the old account's ΑΦΜ (E2 would show it to the new
     * customer), and on a partial move the moved lines are on a new invoice with no document (a second one, once paid).
     */
    public function test_adv2c_a_service_whose_invoice_has_a_fiscal_document_does_not_carry_it_to_another_account(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'EUR', 'price' => 100, 'quantity' => 1,
            'status' => Service::STATUS_ACTIVE]);
        $filed = $this->unpaidInvoice(service: $service);
        $this->bridge(['op' => 'mark', 'invoice_id' => $filed->id, 'mark' => '400001234567890'])->assertOk();
        $to = User::factory()->create()->client();

        try {
            app(MoveToClient::class)->service($service, $to, Staff::factory()->create());
        } catch (DisplayException) {
        }

        $this->assertSame($this->client->id, (int) $filed->fresh()->client_id,
            'the invoice with a document issued to the old account moved to the new account');
    }

    /**
     * ADV-3. A document Ekdosi cancelled at AADE (`state: cancelled`) still takes the invoice out of the Inbox
     * (`whereDoesntHave('ekdosiDocuments')` counts cancelled ones): a paid invoice whose only document was cancelled (to be
     * reissued, e.g. to the right ΑΦΜ) is never offered again, and the sale is left with no valid fiscal document.
     */
    public function test_adv3_an_invoice_whose_only_document_was_cancelled_is_back_in_the_inbox(): void
    {
        $invoice = $this->paidInvoice();
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890'])->assertOk();
        $this->bridge(['op' => 'invoices'])->assertJsonPath('count', 0);

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890', 'state' => 'cancelled'])->assertOk();

        $this->assertSame([$invoice->id], collect($this->bridge(['op' => 'invoices'])->json('invoices'))->pluck('id')->all(),
            'no active document: it is unfiled again');
    }

    /**
     * ADV-4. A cancellation at AADE is final, but the same MARK with `state: active` (a replay of Ekdosi's first
     * write-back, which is signed forever: there is no timestamp or nonce in the body) brings it back to active.
     */
    public function test_adv4_a_document_cancelled_at_aade_never_comes_back_active(): void
    {
        $invoice = $this->paidInvoice();
        $first = ['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890', 'state' => 'active', 'code' => 'ΤΠΥ1'];
        $this->bridge($first)->assertOk();
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890', 'state' => 'cancelled'])->assertOk();

        $this->bridge($first); // the very same signed request again

        $this->assertSame(EkdosiDocument::CANCELLED, EkdosiDocument::query()->sole()->state);
    }

    /**
     * ADV-5. A MARK is a number; `0400001234567890` is the same MARK as `400001234567890`, yet it lands on another invoice
     * instead of the 409 `mark_on_another_invoice` (the uniqueness is on the text).
     */
    public function test_adv5_the_same_mark_zero_padded_is_still_on_another_invoice(): void
    {
        $invoice = $this->paidInvoice();
        $other = $this->paidInvoice();
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890'])->assertOk();

        $this->bridge(['op' => 'mark', 'invoice_id' => $other->id, 'mark' => '0400001234567890'])->assertStatus(409);
        $this->bridge(['op' => 'mark', 'invoice_id' => $other->id, 'mark' => '0'])->assertStatus(400);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // HELD: attacks that did not work (these pass; they pin the guarantee)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * HELD. A crafted Livewire call with an archived contact of the account (never offered by the Select) is refused: the
     * Select validates against its options. Note the action's own server-side check (abort_if) does not look at
     * archived_at, so this rests on Filament's option validation alone.
     */
    public function test_held_an_archived_contact_is_not_set_as_a_domains_related_contact(): void
    {
        $archived = Contact::query()->forceCreate(['client_id' => $this->client->id, 'company_name' => 'Παλιά επαφή']);
        $archived->forceFill(['archived_at' => now()])->save();
        $domain = Domain::factory()->create(['client_id' => $this->client->id]);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['admin.domains.viewAny', 'admin.domains.update']])->id]);
        $action = TestAction::make('relatedContact')->schemaComponent('contacts_related_actions', 'infolist');

        try {
            $this->livewireAs($staff)->test(ViewDomain::class, ['record' => $domain->getRouteKey()])
                ->callAction($action, ['contact_id' => (string) $archived->id]);
        } catch (\Throwable) {
        }

        $this->assertNull($domain->fresh()->contact_id, 'an archived contact became the recipient of the domain\'s lines');
    }

    public function test_held_signature_variants_are_refused(): void
    {
        $this->paidInvoice();
        $raw = '{"op":"invoices"}';
        $digest = hash_hmac('sha256', $raw, self::SECRET);

        foreach ([
            'SHA256=' . $digest,               // the prefix is exact
            $digest,                           // no prefix
            'sha256=' . strtoupper($digest),   // hex is compared as given
            'sha256=' . $digest . ' ',         // trailing junk
            'sha256=',                         // empty
            'sha256=' . hash_hmac('sha256', $raw, ''), // an empty key
            'sha256=' . hash('sha256', $raw),  // a plain hash
        ] as $header) {
            $this->raw($raw, $header)->assertStatus(401);
        }
        // Signed body, then a byte more (the parsed JSON would be the same).
        $this->raw($raw . ' ', 'sha256=' . $digest)->assertStatus(401);
        $this->raw(" \n" . $raw, 'sha256=' . $digest)->assertStatus(401);
    }

    public function test_held_off_says_nothing_even_signed(): void
    {
        config(['settings.ekdosi_enabled' => false]);
        $this->bridge(['op' => 'ping'])->assertStatus(404);
        config(['settings.ekdosi_enabled' => true, 'settings.ekdosi_secret' => null]);
        $this->signed('{"op":"ping"}')->assertStatus(422);
    }

    public function test_held_odd_json_never_gives_a_500(): void
    {
        $invoice = $this->paidInvoice();
        foreach (['[]', '"ping"', '1', 'null', '{"op":["ping"]}', '{"op":null}', '{"op":"invoice","invoice_id":[1]}',
            '{"op":"invoice","invoice_id":"1 OR 1=1"}', '{"op":"resolve","invoice_id":-1}', '{"op":"mark","invoice_id":{"a":1},"mark":["1"]}',
            '{"op":"invoices","status":["Paid"],"since":{"a":1},"offset":[1],"limit":"x"}', '{"op":"invoices","since":"2026-13-45"}',
            '{"op":"invoices","since":"2026-01-01 25:99"}', '{"op":"invoices","offset":-5,"limit":-1}',
            '{"op":"invoices","offset":9223372036854775807,"limit":1e30}', str_repeat('[', 600) . str_repeat(']', 600)] as $raw) {
            $status = $this->signed($raw)->status();
            $this->assertLessThan(500, $status, $raw);
        }
        $status = $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '1', 'code' => ['a' => 1], 'state' => ['x'], 'pdf_url' => ['x']])->status();
        $this->assertSame(200, $status);
        $this->signed(json_encode(['op' => 'ping', 'pad' => str_repeat('x', 70000)]))->assertStatus(413);
    }

    public function test_held_ignore_invoicing_is_never_in_any_list(): void
    {
        $quiet = User::factory()->create()->client();
        $quiet->forceFill(['ignore_invoicing' => true])->save();
        $hidden = $this->paidInvoice($quiet);
        foreach (['All', 'Paid', 'paid_unfiled', ''] as $status) {
            $ids = collect($this->bridge(['op' => 'invoices', 'status' => $status, 'limit' => 200])->assertOk()->json('invoices'))->pluck('id');
            $this->assertNotContains($hidden->id, $ids->all(), $status);
        }
    }

    public function test_held_pdf_link_host_tricks_php_and_browsers_agree_on(): void
    {
        $invoice = $this->paidInvoice();
        foreach (['https://ekdosi.example.test@evil.example/p.pdf', 'https://ekdosi.example.test:443@evil.example/p.pdf',
            'https://ekdosi.example.test%2F@evil.example/p.pdf', 'https://ekdosi.example.test./p.pdf', 'https://ekdosi.example.test.evil.example/p.pdf',
            '//ekdosi.example.test/p.pdf', 'https:/\\ekdosi.example.test/p.pdf', 'https://evil.example#@ekdosi.example.test/', 'https://evil.example?@ekdosi.example.test/',
        ] as $i => $url) {
            $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '80000000000000' . $i, 'pdf_url' => $url])->assertOk();
        }
        $this->assertSame([null], EkdosiDocument::query()->pluck('pdf_url')->unique()->values()->all());
    }

    public function test_held_an_invoice_without_a_customer_is_not_there(): void
    {
        $orphan = Invoice::factory()->create(['client_id' => null, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR']);
        $this->bridge(['op' => 'invoice', 'invoice_id' => $orphan->id])->assertStatus(404);
        $this->bridge(['op' => 'resolve', 'invoice_id' => $orphan->id])->assertStatus(404);
        $this->bridge(['op' => 'mark', 'invoice_id' => $orphan->id, 'mark' => '1'])->assertStatus(404);
        $this->bridge(['op' => 'mark', 'whmcs_invoice_id' => $orphan->id, 'mark' => '1'])->assertStatus(404);
        $this->assertSame(0, EkdosiDocument::query()->count());
    }

    public function test_held_an_upgrade_goes_to_its_services_contact_and_another_accounts_never(): void
    {
        $agency = Contact::query()->forceCreate(['client_id' => $this->client->id, 'company_name' => 'Πελάτης ΟΕ', 'tax_id' => '088888888']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'contact_id' => $agency->id, 'currency_code' => 'EUR', 'price' => 10, 'quantity' => 1, 'status' => Service::STATUS_ACTIVE]);
        $product = Product::factory()->create(['server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        $upgrade = ServiceUpgrade::query()->forceCreate(['service_id' => $service->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'status' => 'pending']);
        $strangerContact = Contact::query()->forceCreate(['client_id' => User::factory()->create()->client()->id, 'company_name' => 'Ξένος', 'tax_id' => '077777777']);
        $domain = Domain::factory()->create(['client_id' => $this->client->id]);
        Domain::query()->whereKey($domain->id)->update(['contact_id' => $strangerContact->id]); // as old or crafted data
        $invoice = $this->unpaidInvoice();
        $invoice->items()->create(['description' => 'Upgrade', 'quantity' => 1, 'price' => '5.00', 'reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id]);
        $invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => '5.00', 'reference_type' => Domain::class, 'reference_id' => $domain->id]);

        $json = $this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->assertOk()->json('invoice');
        $lines = collect($json['third_party']['lines']);
        $this->assertSame([false, true, false], $lines->pluck('routed')->all());
        $this->assertSame('088888888', $lines[1]['contact']['gr_vatno']);
        $this->assertStringNotContainsString('077777777', json_encode($json));
    }

    public function test_held_a_mark_is_bound_to_its_invoice_by_the_signature_and_never_moves(): void
    {
        $invoice = $this->paidInvoice();
        $other = $this->paidInvoice();
        $raw = json_encode(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890']);
        $signature = 'sha256=' . hash_hmac('sha256', $raw, self::SECRET);
        $this->raw($raw, $signature)->assertOk();

        // Same signature, the invoice changed in the body: refused. Duplicate keys: PHP keeps the last, but the signature
        // covers the bytes, so a forger cannot add one.
        $this->raw(str_replace((string) $invoice->id, (string) $other->id, $raw), $signature)->assertStatus(401);
        $this->raw(substr($raw, 0, -1) . ',"invoice_id":' . $other->id . '}', $signature)->assertStatus(401);
        $this->bridge(['op' => 'mark', 'invoice_id' => $other->id, 'mark' => '400001234567890'])->assertStatus(409);
        $this->assertSame($invoice->id, (int) EkdosiDocument::query()->sole()->invoice_id);
    }
}
