<?php

namespace Tests\Feature;

use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Contact;
use App\Models\EkdosiDocument;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\Staff;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Invoice\ChangeOpenLine;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\SplitInvoice;
use App\Services\Invoice\StaffLineEdit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Adversarial review of #459, round 2: on the fixes of a9a1db75. Written without the author's context.
 *
 * - test_r2_* FAIL on a9a1db75: each demonstrates a break (see the PR comment for the table).
 * - test_held_* pass: attacks that did not work, kept to pin the guarantee.
 *
 * The common thread of R2-1 and R2-2: InvoiceLineMove::refusal guards merge, split and move only. Every other path that adds,
 * reprices or removes a line of an unpaid invoice Ekdosi already issued a document for (on credit) still does: the
 * proforma no longer matches the fiscal document, and since the invoice is «filed», a line added after the issue is
 * never offered to Ekdosi (a sale with no document) and a line removed or lowered leaves a document for what was not sold.
 *
 * Not proven here (needs MariaDB and two connections; this file runs on SQLite): refusal()'s document check is a plain
 * consistent read. InvoiceLineMove::lock's first read (pluck client_id) opens the REPEATABLE READ snapshot before any lock,
 * so a `mark` committed between that read and the invoice locks is not seen and the merge goes ahead (R2-8).
 */
class EkdosiBridgeAdversarialRound2Test extends TestCase
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

    private function signed(string $raw): TestResponse
    {
        $headers = ['X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $raw, self::SECRET), 'Content-Type' => 'application/json'];

        return $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars($headers), $raw);
    }

    /** @param array<string, mixed> $body */
    private function bridge(array $body): TestResponse
    {
        return $this->signed(json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function unpaidInvoice(?Client $client = null, string $price = '100.00', ?Service $service = null, string $status = Invoice::STATUS_UNPAID): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => ($client ?? $this->client)->id, 'status' => $status,
            'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]
            + ($service === null ? [] : ['reference_type' => Service::class, 'reference_id' => $service->id]));

        return $invoice->fresh();
    }

    /** An unpaid invoice Ekdosi issued a document for (on credit, E3's case). */
    private function filedOnCredit(string $mark = '400001234567890'): Invoice
    {
        $invoice = $this->unpaidInvoice();
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => $mark])->assertOk();

        return $invoice->fresh();
    }

    private function pay(Invoice $invoice, string $txn): void
    {
        $invoice = $invoice->fresh();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', (string) $invoice->remaining, transactionId: $txn);
    }

    /** @return list<int> */
    private function inbox(): array
    {
        return collect($this->bridge(['op' => 'invoices', 'limit' => 200])->assertOk()->json('invoices'))->pluck('id')->all();
    }

    private function apiToken(): string
    {
        $token = 'EKD2' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'test', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token),
            'permissions' => ['admin.invoice_items.create', 'admin.invoice_items.update', 'admin.invoice_items.delete']]);

        return $token;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // R2: breaks (these fail on a9a1db75)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * R2-1. The admin API (`/api/v1/admin/invoice-items`) adds, reprices and deletes lines of an unpaid invoice with an
     * active Ekdosi document. StaffLineEdit only refuses an invoice with payments; one issued on credit has none.
     */
    public function test_r2_1_the_admin_api_does_not_change_the_lines_of_an_invoice_with_a_fiscal_document(): void
    {
        $invoice = $this->filedOnCredit();
        $line = $invoice->items()->sole();
        $token = $this->apiToken();

        $added = $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'),
            ['invoice_id' => $invoice->id, 'description' => 'Extra, after the document', 'price' => 50, 'quantity' => 1])->status();
        $repriced = $this->withToken($token)->putJson(route('api.v1.admin.invoice-items.update', $line->id), ['price' => 1, 'quantity' => 1, 'description' => 'Hosting'])->status();
        $deleted = $this->withToken($token)->deleteJson(route('api.v1.admin.invoice-items.destroy', $line->id))->status();

        $this->assertSame([422, 422, 422], [$added, $repriced, $deleted], 'the API changed the lines of an invoice whose fiscal document is issued');
        $this->assertSame('100.00', number_format((float) InvoiceItem::query()->where('invoice_id', $invoice->id)->sum('price'), 2, '.', ''));
    }

    /**
     * R2-1b. The consequence: the line added after the issue is paid, but the invoice is «filed», so it never reaches the
     * Inbox. A sale of 50 with no fiscal document, and nobody is told.
     */
    public function test_r2_1b_a_line_added_after_the_document_is_never_issued(): void
    {
        $invoice = $this->filedOnCredit();

        try {
            StaffLineEdit::run($invoice->id, true, fn () => InvoiceItem::create(['invoice_id' => $invoice->id, 'description' => 'Extra', 'price' => 50, 'quantity' => 1]));
        } catch (DisplayException) {
            return; // refused: fine
        }
        $this->pay($invoice, 'TX-R2-1b');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertContains($invoice->id, $this->inbox(),
            'a paid line added after the fiscal document (the admin form and the API, via StaffLineEdit) is never offered to Ekdosi');
    }

    /**
     * R2-2. The app's own line changes (ChangeOpenLine: the cron's new recurring price, an upgrade's price, a domain's
     * years) reprice a line of an unpaid invoice with an active document; a price brought to nothing even settles it.
     */
    public function test_r2_2_change_open_line_does_not_reprice_an_invoice_with_a_fiscal_document(): void
    {
        $invoice = $this->filedOnCredit();
        $line = $invoice->items()->sole();

        $changed = ChangeOpenLine::handle($line, fn (InvoiceItem $locked) => $locked->price = '60.00');

        $this->assertFalse($changed, 'the app repriced a line already on a fiscal document');
        $this->assertSame('100.00', number_format((float) $line->fresh()->price, 2, '.', ''));
    }

    /**
     * R2-3. The write-back arrives after a merge (Ekdosi read invoice A, issued at AADE, and staff merged A into B before
     * the `mark` came back; under MariaDB the INSERT's FK check even waits for the merge to commit, then succeeds). The
     * MARK is recorded on A, cancelled and empty; B carries A's lines with no document and, once paid, is in the Inbox:
     * the same sale issued twice. record() accepts a MARK on any invoice, whatever happened to its lines.
     */
    public function test_r2_3_a_mark_on_a_merged_away_invoice_does_not_leave_its_lines_unfiled(): void
    {
        $a = $this->unpaidInvoice();
        $b = $this->unpaidInvoice(price: '50.00');
        $read = $this->bridge(['op' => 'invoice', 'invoice_id' => $a->id])->assertOk()->json('invoice.total');
        $this->assertSame('100.00', $read);

        app(MergeInvoices::class)->handle($b->id, [$a->id]);
        $status = $this->bridge(['op' => 'mark', 'invoice_id' => $a->id, 'mark' => '400001234567890'])->status();

        if ($status === 200) {
            $this->pay($b, 'TX-R2-3');
            $this->assertNotContains($b->id, $this->inbox(),
                'A\'s lines, already on a fiscal document, come back to the Inbox on B: a second document for the same sale');
        }
        $this->assertNotSame(200, $status, 'a MARK on a merged-away invoice is taken silently; Ekdosi is never told its lines moved');
    }

    /** R2-3b. The same with a split: the MARK lands on the source, the lines split off are on a new invoice with none. */
    public function test_r2_3b_a_mark_after_a_split_does_not_leave_the_split_lines_unfiled(): void
    {
        $a = $this->unpaidInvoice();
        $a->items()->create(['description' => 'Second line', 'quantity' => 1, 'price' => '20.00']);
        $this->bridge(['op' => 'invoice', 'invoice_id' => $a->id])->assertOk()->assertJsonPath('invoice.total', '120.00');

        $new = app(SplitInvoice::class)->handle($a->id, [(int) $a->items()->orderByDesc('id')->value('id')]);
        $status = $this->bridge(['op' => 'mark', 'invoice_id' => $a->id, 'mark' => '400001234567890'])->status();

        $this->assertNotSame(200, $status, 'the document covers 120.00 but the invoice is now 100.00; the 20.00 split off has no document');
        // Fix of #459 (author): refused (409 invoice_changed), so Ekdosi knows the document it issued is for an invoice that
        // no longer is (it cancels it); the split-off lines have no document, so once paid they are rightly offered.
        $this->assertSame(409, $status);
        $this->pay($new, 'TX-R2-3b');
        $this->assertContains($new->id, $this->inbox());
        $this->assertSame(0, EkdosiDocument::query()->count());
    }

    /**
     * R2-4. MoveToClient never asks InvoiceLineMove::refusal about a draft (only whether it is whole and unpaid). A draft
     * with an active document (record() takes a MARK on any status, and `op=invoice` serves drafts) moves whole to the
     * other account, carrying a document in the first account's ΑΦΜ.
     */
    public function test_r2_4_a_draft_with_a_fiscal_document_does_not_move_to_another_account(): void
    {
        $service = Service::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'EUR', 'price' => 100, 'quantity' => 1,
            'status' => Service::STATUS_ACTIVE]);
        $draft = $this->unpaidInvoice(service: $service, status: Invoice::STATUS_DRAFT);
        $this->bridge(['op' => 'mark', 'invoice_id' => $draft->id, 'mark' => '400001234567890'])->assertOk();
        $to = User::factory()->create()->client();

        try {
            app(MoveToClient::class)->service($service, $to, Staff::factory()->create());
        } catch (DisplayException) {
        }

        $this->assertSame($this->client->id, (int) $draft->fresh()->client_id, 'a draft with a document in one ΑΦΜ moved to another account');
    }

    /**
     * R2-5. The PDF link's path: only `/../` is refused, but a browser (WHATWG URL) also resolves a trailing `/..` and the
     * percent-encoded dot segments `%2e%2e`, `.%2e`, `%2e.`. Each of these leaves Ekdosi's path for the host's root, which
     * docs/ekdosi-bridge.md says cannot happen («μόνο ο host και το path του ekdosi_url»).
     */
    public function test_r2_5_a_pdf_link_never_leaves_ekdosis_path_by_dot_segments(): void
    {
        $invoice = $this->unpaidInvoice();
        $tricks = [
            'https://ekdosi.example.test/ekdosi/..',
            'https://ekdosi.example.test/ekdosi/%2e%2e/admin/p.pdf',
            'https://ekdosi.example.test/ekdosi/.%2E/admin/p.pdf',
            'https://ekdosi.example.test/ekdosi/%2E./admin/p.pdf',
            'https://ekdosi.example.test/ekdosi/x/%2e%2e/%2e%2e/admin/p.pdf',
        ];
        foreach ($tricks as $i => $url) {
            $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '50000000000000' . $i, 'pdf_url' => $url])->assertOk();
        }

        $this->assertSame([null], EkdosiDocument::query()->pluck('pdf_url')->unique()->values()->all(),
            'a dot segment the browser resolves takes the link out of /ekdosi/');
    }

    /**
     * R2-6. Ekdosi on a port (`https://ekdosi.example.test:8443/ekdosi`): a link with no port passes the port rule
     * (`!isset($parts['port']) || …`) and is rebuilt without one, i.e. on 443, another service of that host.
     */
    public function test_r2_6_a_pdf_link_without_the_port_of_ekdosi_is_left_out(): void
    {
        config(['settings.ekdosi_url' => 'https://ekdosi.example.test:8443/ekdosi']);
        $invoice = $this->unpaidInvoice();

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400001234567890',
            'pdf_url' => 'https://ekdosi.example.test/ekdosi/p.pdf'])->assertOk();

        $this->assertNull(EkdosiDocument::query()->sole()->pdf_url, 'a link to port 443 kept, while Ekdosi is on 8443');
    }

    /**
     * R2-7. `since` is «validated strictly» only when it is a string: a number, a list or an object is silently dropped,
     * and the Inbox then starts at the beginning of time instead of the cut-over (every invoice WHMCS already sent to
     * Ekdosi is offered again). Likewise `mark: true` is taken as MARK «1».
     */
    public function test_r2_7_a_since_or_a_mark_of_the_wrong_type_is_a_400_not_ignored(): void
    {
        $old = $this->unpaidInvoice();
        $this->pay($old, 'TX-R2-7');
        Invoice::query()->whereKey($old->id)->update(['paid_at' => '2020-01-01 10:00:00', 'created_at' => '2020-01-01 10:00:00']);

        $this->bridge(['op' => 'invoices', 'since' => '2026-10-01'])->assertOk()->assertJsonPath('count', 0);
        $this->bridge(['op' => 'invoices', 'since' => 20261001])->assertStatus(400);
        $this->bridge(['op' => 'invoices', 'since' => ['2026-10-01']])->assertStatus(400);
        $this->bridge(['op' => 'mark', 'invoice_id' => $old->id, 'mark' => true])->assertStatus(400);
    }

    // ---------------------------------------------------------------------------------------------------------------
    // HELD: attacks that did not work (these pass; they pin the guarantee)
    // ---------------------------------------------------------------------------------------------------------------

    /** A merge refuses an unpaid invoice with an active document, whether it is the target or a source. */
    public function test_held_a_merge_refuses_a_filed_invoice_whichever_side_it_is(): void
    {
        $filed = $this->filedOnCredit();
        $other = $this->unpaidInvoice(price: '50.00');
        foreach ([fn () => app(MergeInvoices::class)->handle($filed->id, [$other->id]), fn () => app(MergeInvoices::class)->handle($other->id, [$filed->id])] as $merge) {
            try {
                $merge();
                $this->fail('merged');
            } catch (DisplayException $e) {
                $this->assertStringContainsString('Ekdosi', $e->getMessage());
            }
        }
        $this->assertSame(1, $filed->items()->count());
        $this->assertSame(Invoice::STATUS_UNPAID, $filed->fresh()->status);
    }

    /** Once the only document is cancelled, the invoice is merged again (it is unfiled; nothing issued twice). */
    public function test_held_a_cancelled_document_no_longer_blocks_a_merge(): void
    {
        $filed = $this->filedOnCredit();
        $this->bridge(['op' => 'mark', 'invoice_id' => $filed->id, 'mark' => '400001234567890', 'state' => 'cancelled'])->assertOk();
        $other = $this->unpaidInvoice(price: '50.00');

        app(MergeInvoices::class)->handle($other->id, [$filed->id]);

        $this->assertSame(Invoice::STATUS_CANCELLED, $filed->fresh()->status);
    }

    /**
     * A paid invoice split between recipients: one of two documents cancelled keeps it out of the Inbox (documented: only
     * «its only document» brings it back). Pinned as the contract; note it leaves the cancelled part's reissue to Ekdosi.
     */
    public function test_held_mixed_active_and_cancelled_documents_keep_it_filed(): void
    {
        $invoice = $this->unpaidInvoice();
        $this->pay($invoice, 'TX-mixed');
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000001'])->assertOk();
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000002'])->assertOk();
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000002', 'state' => 'cancelled'])->assertOk();
        $this->assertNotContains($invoice->id, $this->inbox());

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000001', 'state' => 'CANCELLED '])->assertOk();
        $this->assertContains($invoice->id, $this->inbox());
        // Cancelled is final, whatever the case or spacing of a later «active».
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '0400000000000001', 'state' => ' Active'])->assertOk()
            ->assertJsonPath('state', EkdosiDocument::CANCELLED);
        $this->assertSame(2, EkdosiDocument::query()->count());
    }

    /** The rebuilt link: scheme and host lowercased, fragment dropped, query kept, prefix confusion and case refused. */
    public function test_held_pdf_link_rebuild(): void
    {
        $invoice = $this->unpaidInvoice();
        $cases = [
            'HTTPS://EKDOSI.example.test/ekdosi/p.pdf?t=1#frag' => 'https://ekdosi.example.test/ekdosi/p.pdf?t=1',
            'https://ekdosi.example.test:443/ekdosi/p.pdf' => 'https://ekdosi.example.test:443/ekdosi/p.pdf',
            'https://ekdosi.example.test/ekdosiX/p.pdf' => null,
            'https://ekdosi.example.test/EKDOSI/p.pdf' => null,
            'https://ekdosi.example.test/ekdosi' => null,
            'https://ekdosi.example.test/ekdosi/../p.pdf' => null,
            'https://ekdosi.example.test/ekdosi/%5C..%5C/p.pdf' => 'https://ekdosi.example.test/ekdosi/%5C..%5C/p.pdf', // %5C is not decoded by browsers
            'https://ekdosi.example.test%40evil.example/ekdosi/p.pdf' => null,
            'https://xn--ekdsi-5ya.example.test/ekdosi/p.pdf' => null,
            'https://ekdosi.example.test:8443/ekdosi/p.pdf' => null,
            'http://ekdosi.example.test/ekdosi/p.pdf' => null,
            "https://ekdosi.example.test/ekdosi/p.pdf\t" => 'https://ekdosi.example.test/ekdosi/p.pdf', // trimmed first
            "https://ekdosi.example.test/ekdosi/p\t.pdf" => null,
        ];
        $i = 0;
        foreach ($cases as $url => $expected) {
            $mark = '6000000000000' . str_pad((string) $i++, 2, '0', STR_PAD_LEFT);
            $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => $mark, 'pdf_url' => $url])->assertOk();
            $this->assertSame($expected, EkdosiDocument::query()->where('mark', $mark)->value('pdf_url'), $url);
        }
    }

    /** `since`: strict on strings, no rollover, no 500. */
    public function test_held_since_strings_are_strict(): void
    {
        foreach (['2026-02-29', '2026-02-30', '2026-04-31', '2026-10-01 24:00', '2026-10-01 23:60', '2026-10-01 10:00:60',
            '2026-10-01T10:00', '26-10-01', '2026-1-01', ' 2026-10-01x', '٢٠٢٦-١٠-٠١'] as $since) {
            $this->bridge(['op' => 'invoices', 'since' => $since])->assertStatus(400)->assertJsonPath('message', 'Invalid since.');
        }
        foreach (['2024-02-29', ' 2026-10-01 ', '2026-10-01 00:00', '2026-10-01 23:59:59'] as $since) {
            $this->bridge(['op' => 'invoices', 'since' => $since])->assertOk();
        }
    }

    /** References that are gone (a deleted service, an upgrade of a deleted service) neither 500 nor route anywhere. */
    public function test_held_dangling_references_route_to_the_account(): void
    {
        $agency = Contact::query()->forceCreate(['client_id' => $this->client->id, 'company_name' => 'Πελάτης ΟΕ', 'tax_id' => '088888888']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'contact_id' => $agency->id, 'currency_code' => 'EUR', 'price' => 10, 'quantity' => 1, 'status' => Service::STATUS_ACTIVE]);
        $product = Product::factory()->create(['server_id' => null, 'stock' => null]);
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        $upgrade = ServiceUpgrade::query()->forceCreate(['service_id' => $service->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'status' => 'pending']);
        $invoice = $this->unpaidInvoice();
        InvoiceItem::query()->whereKey($invoice->items()->sole()->id)->update(['reference_type' => Service::class, 'reference_id' => 987654]);
        $invoice->items()->create(['description' => 'Upgrade', 'quantity' => 1, 'price' => '5.00', 'reference_type' => ServiceUpgrade::class, 'reference_id' => $upgrade->id]);
        $invoice->items()->create(['description' => 'Gone upgrade', 'quantity' => 1, 'price' => '5.00', 'reference_type' => ServiceUpgrade::class, 'reference_id' => 987654]);

        $lines = collect($this->bridge(['op' => 'resolve', 'invoice_id' => $invoice->id])->assertOk()->json('lines'));

        $this->assertSame([false, true, false], $lines->pluck('routed')->all());
        $this->assertSame([987654, $service->id, 987654], $lines->pluck('relid')->all());
    }

    /** An archived related contact is not a recipient (the account is). */
    public function test_held_an_archived_related_contact_is_no_recipient(): void
    {
        $agency = Contact::query()->forceCreate(['client_id' => $this->client->id, 'company_name' => 'Πελάτης ΟΕ', 'tax_id' => '088888888']);
        $service = Service::factory()->create(['client_id' => $this->client->id, 'contact_id' => $agency->id, 'currency_code' => 'EUR', 'price' => 10, 'quantity' => 1, 'status' => Service::STATUS_ACTIVE]);
        $invoice = $this->unpaidInvoice(service: $service);
        $agency->forceFill(['archived_at' => now()])->save();

        $json = $this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->assertOk()->json('invoice');

        $this->assertFalse($json['third_party']['lines'][0]['routed']);
        $this->assertStringNotContainsString('088888888', json_encode($json));
    }

    /** A short secret is refused even when it would sign correctly; the 500 never echoes internals. */
    public function test_held_short_secret_and_no_internals(): void
    {
        config(['settings.ekdosi_secret' => str_repeat('k', 31)]);
        $raw = '{"op":"ping"}';
        $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars(['X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $raw, str_repeat('k', 31)), 'Content-Type' => 'application/json']), $raw)
            ->assertStatus(422);
        config(['settings.ekdosi_secret' => self::SECRET]);
        $this->bridge(['op' => 'invoices', 'status' => 'paid'])->assertStatus(400)->assertExactJson(['error' => 'bad_request', 'message' => 'Invalid status.']);
    }
}
