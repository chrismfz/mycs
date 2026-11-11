<?php

namespace Tests\Feature;

use App\Admin\Actions\SendToEkdosi;
use App\Console\Commands\ImportFromWhmcs;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Invoice\MergeInvoices;
use App\Services\Invoice\SplitInvoice;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Sweep 7, adversarial verifier, «tax-ekdosi».
 *
 * S7-TAX-EKDOSI-1: EkdosiDocuments::changed() read «split since Ekdosi read it» as «was ever split»: an invoice that
 * staff split while it was unpaid refused its document's MARK for good once it was paid and issued (K1 sends no fingerprint).
 *
 * Closed by the owner's rule (2026-11-10, «issued is final»; docs/ekdosi-bridge.md): an invoice that is Ekdosi's (an
 * active document, an active «Send to Ekdosi» request, an account with «immediate invoicing») is not split, merged or
 * moved at all (InvoiceLineMove::refusal), so a split is always older than what Ekdosi read and the write-back no longer
 * asks about one. The first test is the finding's own; the rest pin the rule. Invented data only.
 */
class Sweep7TaxEkdosiTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-test-secret-of-at-least-thirty-two-chars';

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.ekdosi_enabled' => true, 'settings.ekdosi_secret' => self::SECRET, 'settings.ekdosi_url' => 'https://ekdosi.example.test/ekdosi',
            'settings.tax_enabled' => true, 'settings.tax_type' => 'exclusive']);
        TaxRate::create(['name' => 'VAT', 'rate' => 24, 'country' => 'GR']);
        Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->client = User::factory()->create()->client();
        $this->client->primaryContact->forceFill(['type' => 'business', 'company_name' => 'Test', 'tax_id' => '099999999', 'country' => 'GR'])->save();
    }

    private function bridge(array $body): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/ekdosi', [], [], [], $this->transformHeadersToServerVars([
            'X-Webhook-Signature' => 'sha256=' . hash_hmac('sha256', $raw, self::SECRET), 'Content-Type' => 'application/json']), $raw);
    }

    /** @return array{Invoice, int} an unpaid invoice of two lines (100 + 20, net), and the id of the second line */
    private function invoice(?Service $second = null): array
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        // With a service on the second line, one on the first too: a line of no item would go with the item that moves.
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']
            + ($second === null ? [] : ['reference_type' => Service::class, 'reference_id' => $this->service()->id]));
        $b = $invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => '20.00']
            + ($second === null ? [] : ['reference_type' => Service::class, 'reference_id' => $second->id]));

        return [$invoice->fresh(), (int) $b->id];
    }

    private function service(): Service
    {
        return Service::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'EUR', 'price' => 20, 'quantity' => 1,
            'status' => Service::STATUS_ACTIVE]);
    }

    /** What staff are told when the lines do not move, or null when they did. */
    private function refused(callable $move): ?string
    {
        try {
            $move();
        } catch (DisplayException $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function test_s7_tax_ekdosi_1_an_invoice_split_before_it_was_paid_takes_its_document(): void
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $a = $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']);
        $b = $invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => '20.00']);
        // Staff split it while open: «cut and sew».
        $new = (new SplitInvoice)->handle($invoice->id, [$b->id]);
        // Both are paid, days later.
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', (string) $invoice->fresh()->total, transactionId: 'TX-1');
        ExtensionHelper::addPayment($new->fresh(), 'BankTransfer', (string) $new->fresh()->total, transactionId: 'TX-2');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // Ekdosi reads the Inbox now (after the split), issues, and writes back as K1 does: without a fingerprint.
        $ids = array_column($this->bridge(['op' => 'invoices'])->assertOk()->json('invoices'), 'id');
        $this->assertContains($invoice->id, $ids);
        $this->assertContains($new->id, $ids);

        $second = $this->bridge(['op' => 'mark', 'invoice_id' => $new->id, 'mark' => '400000000000002', 'code' => 'TPY-2']);
        $first = $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000001', 'code' => 'TPY-1']);
        $second->assertOk();
        // Was 409 invoice_changed, «Split since», though the split was before Ekdosi ever read it.
        $first->assertOk();
        // And it leaves the Inbox.
        $this->assertNotContains($invoice->id, array_column($this->bridge(['op' => 'invoices'])->json('invoices'), 'id'));
    }

    /** Split first, sent to Ekdosi after (on credit, both unpaid): each half takes the MARK of its own document. */
    public function test_an_invoice_split_before_it_was_sent_to_ekdosi_takes_its_document_and_so_does_the_split_off_half(): void
    {
        [$invoice, $line] = $this->invoice();
        $new = (new SplitInvoice)->handle($invoice->id, [$line]);
        $this->travel(1)->days();
        $this->assertSame(2, SendToEkdosi::request([$invoice->fresh(), $new->fresh()], true));

        $read = collect($this->bridge(['op' => 'invoices', 'status' => 'requested'])->assertOk()->json('invoices'))->pluck('total', 'id');
        $this->assertEqualsWithDelta(124.00, (float) $read[$invoice->id], 0.001);
        $this->assertEqualsWithDelta(24.80, (float) $read[$new->id], 0.001);

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000011', 'code' => 'TIM-11'])->assertOk();
        $this->bridge(['op' => 'mark', 'invoice_id' => $new->id, 'mark' => '400000000000012', 'code' => 'TIM-12'])->assertOk();
        $this->assertSame([], $this->bridge(['op' => 'invoices', 'status' => 'requested'])->json('invoices'));
    }

    /**
     * A service moved to another account before the invoice was sent (MoveToClient: its line on a new invoice of the other
     * account, split_from_id as a split): the invoice that kept the rest takes its document.
     */
    public function test_an_invoice_a_service_was_moved_off_before_it_was_sent_takes_its_document(): void
    {
        $service = $this->service();
        [$invoice] = $this->invoice($service);
        $to = User::factory()->create()->client();
        $moved = app(MoveToClient::class)->service($service, $to, Staff::factory()->create());
        $this->assertArrayHasKey($invoice->id, $moved['split'], 'precondition: its line left for an invoice of the other account');
        SendToEkdosi::request([$invoice->fresh()], true);

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000021', 'code' => 'TIM-21'])->assertOk();
    }

    /**
     * The rule: once staff sent it to Ekdosi, it is not split, merged (either side) or moved, and staff are told the way
     * through. The request stays (before: a split or a merge withdrew it silently, and Ekdosi kept what it had read).
     * Withdrawn, it is split again.
     */
    public function test_an_invoice_sent_to_ekdosi_is_not_split_merged_or_moved_until_the_request_is_withdrawn(): void
    {
        $service = $this->service();
        [$invoice, $line] = $this->invoice($service);
        $other = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $other->items()->create(['description' => 'Other', 'quantity' => 1, 'price' => '5.00']);
        SendToEkdosi::request([$invoice], true);

        $moves = [
            'split' => fn () => (new SplitInvoice)->handle($invoice->id, [$line]),
            'merge into it' => fn () => (new MergeInvoices)->handle($invoice->id, [$other->id]),
            'merge away' => fn () => (new MergeInvoices)->handle($other->id, [$invoice->id]),
            'move' => fn () => app(MoveToClient::class)->service($service->fresh(), User::factory()->create()->client(), Staff::factory()->create()),
        ];
        foreach ($moves as $name => $move) {
            $why = $this->refused($move);
            $this->assertNotNull($why, $name . ': done on an invoice Ekdosi was asked to issue');
            $this->assertStringContainsString('withdraw the request', $why, $name);
            $this->assertStringContainsString('inbox', $why, $name);
        }
        $this->assertNotNull($invoice->fresh()->ekdosi_requested_at, 'the request stays: nothing moved');
        $this->assertSame(2, $invoice->items()->count());
        $this->assertSame(Invoice::STATUS_UNPAID, $other->fresh()->status);
        $this->assertSame($this->client->id, (int) $service->fresh()->client_id);

        SendToEkdosi::request([$invoice->fresh()], false);
        $this->assertNull($this->refused($moves['split']), 'withdrawn: split as any other');
    }

    /** Its document issued (on credit): «issued is final», for a split, a merge and a move. */
    public function test_an_invoice_with_an_issued_document_is_not_split_merged_or_moved_issued_is_final(): void
    {
        $service = $this->service();
        [$invoice, $line] = $this->invoice($service);
        $other = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $other->items()->create(['description' => 'Other', 'quantity' => 1, 'price' => '5.00']);
        SendToEkdosi::request([$invoice], true);
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000041', 'code' => 'TIM-41'])->assertOk();
        $this->assertNull($invoice->fresh()->ekdosi_requested_at, 'precondition: issued, the request is done');

        foreach ([
            fn () => (new SplitInvoice)->handle($invoice->id, [$line]),
            fn () => (new MergeInvoices)->handle($other->id, [$invoice->id]),
            fn () => app(MoveToClient::class)->service($service->fresh(), User::factory()->create()->client(), Staff::factory()->create()),
        ] as $move) {
            $this->assertStringContainsString('issued is final', (string) $this->refused($move));
        }
        $this->assertSame(2, $invoice->items()->count());
    }

    /**
     * A paid invoice is in Ekdosi's Inbox with no request (`paid_unfiled`): it was never split or merged (only unpaid
     * ones are), pinned here because the rule leans on it.
     */
    public function test_a_paid_invoice_is_not_split_or_merged(): void
    {
        [$invoice, $line] = $this->invoice();
        [$other] = $this->invoice();
        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', (string) $invoice->total, transactionId: 'TX-PAID');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertContains($invoice->id, array_column($this->bridge(['op' => 'invoices'])->assertOk()->json('invoices'), 'id'));

        foreach ([
            fn () => (new SplitInvoice)->handle($invoice->id, [$line]),
            fn () => (new MergeInvoices)->handle($invoice->id, [$other->id]),
            fn () => (new MergeInvoices)->handle($other->id, [$invoice->id]),
        ] as $move) {
            $this->assertStringContainsString('is not unpaid', (string) $this->refused($move));
        }
        $this->assertSame(2, $invoice->items()->count());
    }

    /**
     * An invoice as the importer brings it from WHMCS (importInvoices, importInvoiceItems: its lines have no period, and
     * nothing on it is ever marked applied, paid or not), split here before it is sent: both halves take their MARK,
     * unpaid on credit, and then paid. A paid one comes into the Inbox as it is, takes its MARK, and is never split.
     */
    public function test_an_imported_whmcs_invoice_split_before_it_is_sent_takes_its_document(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, currency INT)');
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INT, invoicenum TEXT, date TEXT, duedate TEXT, datepaid TEXT,
            status TEXT, total REAL, credit REAL, taxrate REAL)');
        $whmcs->exec('CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INT, userid INT, type TEXT, relid INT, description TEXT,
            amount REAL, taxed INT)');
        $whmcs->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, userid INT, invoiceid INT, gateway TEXT, date TEXT, amountin REAL,
            amountout REAL, fees REAL, transid TEXT)');
        $whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $whmcs->exec("INSERT INTO tblclients VALUES ({$this->client->id}, 1)");
        $service = $this->service();
        $whmcs->exec("INSERT INTO tblinvoices VALUES (7001, {$this->client->id}, '', '2026-10-20', '2026-10-30', '0000-00-00 00:00:00', 'Unpaid', 148.80, 0, 24),
            (7002, {$this->client->id}, '', '2026-10-01', '2026-10-10', '2026-10-05 10:00:00', 'Paid', 148.80, 0, 24)");
        $whmcs->exec("INSERT INTO tblinvoiceitems VALUES (9001, 7001, {$this->client->id}, 'Hosting', {$service->id}, 'Hosting example.com', 100.00, 1),
            (9002, 7001, {$this->client->id}, '', 0, 'Setup of a mailbox', 20.00, 1),
            (9003, 7002, {$this->client->id}, 'Hosting', {$service->id}, 'Hosting example.com', 100.00, 1),
            (9004, 7002, {$this->client->id}, '', 0, 'Setup of a mailbox', 20.00, 1)");
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        foreach (['importInvoices', 'importInvoiceItems'] as $step) {
            (new ReflectionMethod($command, $step))->invoke($command);
        }
        $invoice = Invoice::query()->findOrFail(7001);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame(4, DB::table('invoice_items')->whereIn('invoice_id', [7001, 7002])->whereNull('applied_at')->whereNull('period_start')
            ->whereNull('period_end')->count(), 'precondition: as imported, no period and nothing applied, on the paid one too');
        $this->assertSame(Service::class, DB::table('invoice_items')->where('id', 9001)->value('reference_type'));

        $new = (new SplitInvoice)->handle($invoice->id, [9002]);
        SendToEkdosi::request([$invoice->fresh(), $new->fresh()], true);
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000000051', 'code' => 'TIM-51'])->assertOk();
        $this->bridge(['op' => 'mark', 'invoice_id' => $new->id, 'mark' => '400000000000052', 'code' => 'TIM-52'])->assertOk();

        ExtensionHelper::addPayment($invoice->fresh(), 'BankTransfer', (string) $invoice->fresh()->total, transactionId: 'TX-IMP');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $inbox = array_column($this->bridge(['op' => 'invoices'])->assertOk()->json('invoices'), 'id');
        $this->assertNotContains($invoice->id, $inbox, 'filed: out of the Inbox');

        // The paid one: Ekdosi's with no request, never split, its MARK taken.
        $this->assertContains(7002, $inbox);
        $this->assertStringContainsString('is not unpaid', (string) $this->refused(fn () => (new SplitInvoice)->handle(7002, [9004])));
        $this->bridge(['op' => 'mark', 'invoice_id' => 7002, 'mark' => '400000000000053', 'code' => 'TIM-53'])->assertOk();
    }
}
