<?php

namespace Tests\Feature;

use App\Admin\Actions\SendToEkdosi;
use App\Exceptions\DisplayException;
use App\Helpers\ExtensionHelper;
use App\Mail\SystemMail;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Staff;
use App\Models\TaxRate;
use App\Models\User;
use App\Services\Clients\MoveToClient;
use App\Services\Invoice\CancelOpenInvoices;
use App\Services\Invoice\RecordPaymentService;
use App\Services\Invoice\SplitInvoice;
use App\Services\Invoice\StaffLineEdit;
use App\Services\Payment\ItemPaymentMethod;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Independent and adversarial review of #778 («an invoice that is Ekdosi's is not split, merged or moved; a split no longer
 * refuses the MARK», S7-TAX-EKDOSI-1). Each test failed on the PR's head as reviewed (88c139b7). Invented data only.
 *
 * The PR's rule holds only while MyCS still knows the invoice is Ekdosi's. Each of -1, -2, -3 is a way that knowledge is
 * lost (or never was) while Ekdosi keeps the copy it staged. After the review (owner 2026-11-10: no read time, no
 * fingerprint; a person issues each document in Ekdosi and compares its total): -1 and -2 are accepted and pinned as
 * documented gaps; -3 tells staff; -4 is fixed; -5 and -6 are the review's smaller notes, with a test each.
 */
class Sweep7EkdosiReviewTest extends TestCase
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

    private function service(): Service
    {
        return Service::factory()->create(['client_id' => $this->client->id, 'currency_code' => 'EUR', 'price' => 20, 'quantity' => 1,
            'status' => Service::STATUS_ACTIVE]);
    }

    /** @return array{Invoice, int} unpaid, 100.00 + 20.00 net = 148.80 with VAT 24%; and the id of the 20.00 line */
    private function invoice(bool $services = false): array
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00']
            + ($services ? ['reference_type' => Service::class, 'reference_id' => $this->service()->id] : []));
        $b = $invoice->items()->create(['description' => 'Domain', 'quantity' => 1, 'price' => '20.00']
            + ($services ? ['reference_type' => Service::class, 'reference_id' => $this->service()->id] : []));

        return [$invoice->fresh(), (int) $b->id];
    }

    /**
     * REV-778-1, a DOCUMENTED GAP (docs/ekdosi-bridge.md, «Ό,τι δεν καλύπτει ο κανόνας»). A paid invoice is «Ekdosi's»
     * only while it is paid. Paid 148.80 → in the Inbox, Ekdosi stages it → the payment was a mistake and staff delete it:
     * unpaid again, no payment, no request, no document, so nothing here remembers Ekdosi has it → split 124.00 + 24.80 →
     * Ekdosi issues what it staged. The MARK of a 148.80 document is taken for an invoice of 124.00.
     *
     * The reviewer asked for a refusal (main: 409 invoice_changed, by the «ever split» check, which also refused every
     * right document of a split invoice). Accepted by the owner (2026-11-10): no read time and no fingerprint is built;
     * a person issues each document from Ekdosi's inbox and compares its total with MyCS's (an entry whose total differs
     * is archived and fetched again). Pinned at 200 so that whoever changes it sees the case and its amounts.
     */
    public function test_rev_778_1_documented_gap_a_paid_invoice_ekdosi_read_then_set_back_to_unpaid_and_split_takes_the_old_document(): void
    {
        [$invoice, $line] = $this->invoice();
        ExtensionHelper::addPayment($invoice, 'BankTransfer', '148.80', transactionId: 'TX-MISTAKE');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        // Ekdosi reads its Inbox: this is the copy it will issue.
        $staged = collect($this->bridge(['op' => 'invoices'])->assertOk()->json('invoices'))->firstWhere('id', $invoice->id);
        $this->assertSame('148.80', $staged['total']);

        (new RecordPaymentService)->deleteTransaction($invoice->transactions()->firstOrFail());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'precondition: the payment deleted, it is unpaid again');
        (new SplitInvoice)->handle($invoice->id, [$line]);
        $this->assertSame('124.00', number_format((float) $invoice->fresh()->total, 2, '.', ''), 'precondition: it is now 124.00');

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000007781', 'code' => 'TIM-1'])->assertOk();
        // What the person in Ekdosi compares before issuing: MyCS says 124.00 now, by the same read.
        $this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->assertOk()->assertJsonPath('invoice.total', '124.00');
    }

    /**
     * REV-778-2, a DOCUMENTED GAP (the same list). Ekdosi pulls an unpaid invoice by id (its own «send to Ekdosi of one
     * invoice», op=invoice, which leaves no trace here), staff split it, Ekdosi issues its copy: 148.80 filed on 124.00.
     *
     * The reviewer asked for a refusal (main: 409 invoice_changed; what test_r2_3b guarded before #778). Accepted by the
     * owner for the same reason as REV-778-1: the way to an unpaid invoice's document is «Send to Ekdosi» from MyCS (then
     * the split is refused), and the person who issues compares the total. Pinned at 200.
     */
    public function test_rev_778_2_documented_gap_an_unpaid_invoice_ekdosi_pulled_by_id_and_staff_then_split_takes_the_old_document(): void
    {
        [$invoice, $line] = $this->invoice();
        $this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->assertOk()->assertJsonPath('invoice.total', '148.80');

        (new SplitInvoice)->handle($invoice->id, [$line]);

        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000007782'])->assertOk();
        $this->bridge(['op' => 'invoice', 'invoice_id' => $invoice->id])->assertOk()->assertJsonPath('invoice.total', '124.00');
    }

    /** As staff and the customer read it: its number, or its id while it has none (ids differ from numbers on MariaDB). */
    private function label(Invoice $invoice): string
    {
        return '#' . ($invoice->fresh()->number ?: $invoice->id);
    }

    /** @return list<string> the bodies of the mails to staff about a withdrawn request */
    private function toldByMail(): array
    {
        return collect([...Mail::sent(SystemMail::class), ...Mail::queued(SystemMail::class)])
            ->filter(fn (SystemMail $mail) => str_contains($mail->envelope()->subject, 'Request to Ekdosi withdrawn'))
            ->map(fn (SystemMail $mail) => $mail->render())->values()->all();
    }

    /**
     * REV-778-3. A service ending takes its line off a shared invoice that staff SENT (CancelOpenInvoices, no staff at
     * the keyboard): the request was withdrawn without a word (an audit row) while Ekdosi keeps the 148.80 it staged.
     *
     * The reviewer offered «the line stays for staff, or staff are told». The owner chose the second (no refusal, no new
     * state): staff are mailed once, when the request is withdrawn, to archive the copy in Ekdosi's inbox and send the
     * invoice again. The test's last part (the old MARK refused) is not kept: it is the documented gap of REV-778-1/-2,
     * and the mail is what stands between the copy and its document.
     */
    public function test_rev_778_3_a_service_ending_takes_its_line_off_an_invoice_sent_to_ekdosi_and_staff_are_told_once(): void
    {
        Mail::fake();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        [$invoice, $line] = $this->invoice(services: true);
        SendToEkdosi::request([$invoice], true);
        $this->bridge(['op' => 'invoices', 'status' => 'requested'])->assertOk()->assertJsonPath('invoices.0.total', '148.80');
        $ended = Service::query()->findOrFail($invoice->items()->findOrFail($line)->reference_id);

        CancelOpenInvoices::handle([$invoice->fresh()], 'service_ended', $ended);

        $this->assertSame(1, $invoice->items()->count(), 'precondition: the ended service\'s line is off it');
        $this->assertNull($invoice->fresh()->ekdosi_requested_at, 'precondition: the request is withdrawn');
        $told = $this->toldByMail();
        $this->assertCount(1, $told, 'the invoice staff sent at 148.80 is now 124.00, its request gone, nobody told');
        $this->assertStringContainsString($this->label($invoice) . ' was sent to Ekdosi and has changed since (its lines changed)', $told[0]);
        $this->assertStringContainsString('archive its copy', $told[0]);

        // Once: with no request left, another change says nothing more.
        $invoice->items()->firstOrFail()->update(['price' => '90.00']);
        $this->assertCount(1, $this->toldByMail());
    }

    /** REV-778-3, the cron's other ways: the invoice cancelled (the last service ended), and never when its document is issued. */
    public function test_rev_778_3_a_cancelled_invoice_sent_to_ekdosi_tells_staff_and_an_issued_document_does_not(): void
    {
        Mail::fake();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        [$cancelled] = $this->invoice();
        [$issued] = $this->invoice();
        SendToEkdosi::request([$cancelled, $issued], true);

        $this->bridge(['op' => 'mark', 'invoice_id' => $issued->id, 'mark' => '400000000007783'])->assertOk();
        $this->assertSame([], $this->toldByMail(), 'its document issued: the request is done, nothing to archive');

        $cancelled->fresh()->update(['status' => Invoice::STATUS_CANCELLED]);
        $told = $this->toldByMail();
        $this->assertCount(1, $told);
        $this->assertStringContainsString('(now cancelled)', $told[0]);
    }

    /** REV-778-3, staff at the keyboard: the same sentence on their screen, no mail. */
    public function test_rev_778_3_a_change_by_staff_at_the_keyboard_warns_them_on_screen_instead_of_a_mail(): void
    {
        Mail::fake();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->actingAs(Staff::factory()->create(), 'staff');
        [$invoice] = $this->invoice();
        SendToEkdosi::request([$invoice], true);

        StaffLineEdit::run($invoice->id, true, fn (Invoice $locked) => $locked->items()->create(['description' => 'Extra', 'quantity' => 1, 'price' => '5.00']));

        $this->assertNull($invoice->fresh()->ekdosi_requested_at);
        $this->assertSame([], $this->toldByMail());
        Notification::assertNotified(Notification::make()->warning()->persistent()->title('Request to Ekdosi withdrawn')
            ->body('Invoice ' . $this->label($invoice) . ' was sent to Ekdosi and has changed since (its lines changed): archive its copy in Ekdosi\'s inbox, and send it again when it is ready.'));
    }

    /**
     * REV-778-4. What the customer of an «immediate invoicing» account gets when they change the payment method of ONE
     * service that shares an open invoice with another (ItemPaymentMethod::followOnOpenInvoices): the line's way was
     * changed first, then the split was refused by the new rule and swallowed: one invoice of two ways, which no saved
     * method charges, and nobody told. The same for an invoice staff sent to Ekdosi.
     *
     * Fixed as the reviewer's first option: refusal() is asked before the line changes. The open invoice stays exactly as
     * it is (one way, charged as before), the service takes the new method for its next invoices, and the customer is told.
     */
    public function test_rev_778_4_a_payment_method_change_on_an_immediate_invoicing_account_leaves_one_invoice_of_two_ways(): void
    {
        $this->client->forceFill(['immediate_invoicing' => true])->save();
        [$invoice, $line] = $this->invoice(services: true);
        $other = Gateway::query()->create(['name' => 'Card', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $service = Service::query()->findOrFail($invoice->items()->findOrFail($line)->reference_id);

        $service->update(['payment_gateway_id' => $other->id]);

        $ways = $invoice->items()->get()->map(fn ($item) => $item->payment_gateway_id)->unique()->count();
        $this->assertTrue(Invoice::query()->count() === 2 || $ways === 1, 'one invoice, lines of two payment ways: no saved method charges it, and the customer saw nothing');
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame([null], $invoice->items()->pluck('payment_gateway_id')->unique()->all(), 'the open invoice is as it was');
        $this->assertSame($other->id, (int) $service->fresh()->payment_gateway_id, 'the service has the new method, for its next invoices');
        $notice = ItemPaymentMethod::notice('saved', 'services.payment_method_saved_open_kept');
        $this->assertStringContainsString($this->label($invoice) . ' keeps its payment method', $notice);
        $this->assertStringContainsString('from the next invoice', $notice);
        $this->assertSame('saved', ItemPaymentMethod::notice('saved', 'services.payment_method_saved_open_kept'), 'told once');
    }

    /** REV-778-4, the same for an invoice staff sent to Ekdosi; one that can be split is split as before, with the plain message. */
    public function test_rev_778_4_a_payment_method_change_leaves_an_invoice_sent_to_ekdosi_as_it_is_and_splits_any_other(): void
    {
        $other = Gateway::query()->create(['name' => 'Card', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        [$sent, $line] = $this->invoice(services: true);
        SendToEkdosi::request([$sent], true);
        $service = Service::query()->findOrFail($sent->items()->findOrFail($line)->reference_id);

        ItemPaymentMethod::set($service, $other->id);

        $this->assertSame([null], $sent->items()->pluck('payment_gateway_id')->unique()->all());
        $this->assertSame(2, $sent->items()->count());
        $this->assertNotNull($sent->fresh()->ekdosi_requested_at, 'untouched: its request stands');
        $this->assertStringContainsString($this->label($sent), ItemPaymentMethod::notice('saved', 'services.payment_method_saved_open_kept'));

        [$free, $second] = $this->invoice(services: true);
        ItemPaymentMethod::set(Service::query()->findOrFail($free->items()->findOrFail($second)->reference_id), $other->id);
        $this->assertSame(1, $free->items()->count(), 'split as before: its line on an invoice of its own');
        $this->assertSame('saved', ItemPaymentMethod::notice('saved', 'services.payment_method_saved_open_kept'));
    }

    /**
     * REV-778-4, «Do not charge automatically» on one service of a shared invoice that cannot be split (keepApart). The
     * coordinator asked for «the setting applies from the next invoice»; kept instead as it already was for any refused
     * split (a payment on it): the invoice stays whole and NONE of it is charged automatically, because charging an invoice
     * that bills a service the customer just took off automatic payment is the one thing they asked not to happen, and
     * the confirmation they accepted says «the open invoice included». New: the customer is told so, with the invoice.
     */
    public function test_rev_778_4_do_not_charge_automatically_on_an_invoice_that_cannot_be_split_keeps_it_whole_and_says_so(): void
    {
        $this->client->forceFill(['immediate_invoicing' => true])->save();
        [$invoice, $line] = $this->invoice(services: true);
        $service = Service::query()->findOrFail($invoice->items()->findOrFail($line)->reference_id);

        ItemPaymentMethod::setAutoCharge($service, false);

        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(2, $invoice->items()->count());
        $notice = ItemPaymentMethod::notice('off', 'services.auto_charge_turned_off_open_whole');
        $this->assertStringContainsString($this->label($invoice), $notice);
        $this->assertStringContainsString('none of it is charged automatically', $notice);
    }

    /** REV-778-5. An account with «ignore invoicing» too is never listed by the feed: its invoices are split as any other. */
    public function test_rev_778_5_an_account_with_ignore_and_immediate_invoicing_is_not_refused(): void
    {
        $this->client->forceFill(['immediate_invoicing' => true, 'ignore_invoicing' => true])->save();
        [$invoice, $line] = $this->invoice();
        $this->assertNotContains($invoice->id, array_column($this->bridge(['op' => 'invoices', 'status' => 'Unpaid', 'userids' => [$this->client->id]])->assertOk()->json('invoices'), 'id'),
            'precondition: the feed does not list it');

        $this->assertInstanceOf(Invoice::class, (new SplitInvoice)->handle($invoice->id, [$line]));
    }

    /** REV-778-6. A draft with a document is told what an unpaid one is: «issued is final». */
    public function test_rev_778_6_a_draft_with_a_document_is_refused_with_the_same_words(): void
    {
        $service = $this->service();
        $invoice = Invoice::factory()->create(['client_id' => $this->client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '100.00', 'reference_type' => Service::class, 'reference_id' => $service->id]);
        $this->bridge(['op' => 'mark', 'invoice_id' => $invoice->id, 'mark' => '400000000007786'])->assertOk();
        Invoice::query()->whereKey($invoice->id)->update(['status' => Invoice::STATUS_DRAFT]);

        try {
            app(MoveToClient::class)->service($service, User::factory()->create()->client(), Staff::factory()->create());
            $this->fail('moved');
        } catch (DisplayException $e) {
            $this->assertStringContainsString('issued is final', $e->getMessage());
        }
    }
}
