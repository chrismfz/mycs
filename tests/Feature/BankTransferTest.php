<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Livewire\Invoices\Show;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Money\BankTransferDetails;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Bank transfer (owner 2026-10-21): an offline method that shows the company's accounts and asks for the invoice's number
 * in the transfer's reference; the same on an unpaid invoice's PDF and new invoice mail. Staff record the payment.
 */
class BankTransferTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNTS = "Example Bank\nBeneficiary: Example Hosting IKE\nIBAN: GR00 0000 0000 0000 0000 0000 000\n\nOther Bank\nIBAN: GR11 1111 1111 1111 1111 1111 111";

    private User $user;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->gateway = Gateway::query()->create(['extension' => 'BankTransfer', 'name' => 'Bank transfer', 'type' => 'gateway']);
        $this->gateway->settings()->create(['key' => 'accounts', 'value' => self::ACCOUNTS]);
    }

    private function invoice(string $status = Invoice::STATUS_UNPAID, ?string $number = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'number' => $number]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 50]);
        Invoice::query()->whereKey($invoice->id)->update(['status' => $status]); // a paid one is paid by a payment: as stored

        return $invoice->fresh();
    }

    public function test_the_customer_sees_the_accounts_the_amount_and_the_reference(): void
    {
        $invoice = $this->invoice();

        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $this->gateway->id)
            ->call('processPayment')
            ->assertSee('IBAN: GR00 0000 0000 0000 0000 0000 000')
            ->assertSee('Other Bank')
            ->assertSee(__('invoices.bank_transfer_reference', ['reference' => $invoice->number]));

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'nothing is paid until staff record it');
    }

    public function test_the_reference_is_the_number_on_the_invoice(): void
    {
        config(['settings.invoice_proforma' => true]);

        $this->assertSame('2026-0042', BankTransferDetails::reference($this->invoice(number: '2026-0042')));
        $proforma = $this->invoice();
        $this->assertNull($proforma->number);
        $this->assertSame((string) $proforma->id, BankTransferDetails::reference($proforma), 'a proforma: its id, as its heading says');
    }

    public function test_an_unpaid_invoices_pdf_carries_the_accounts_a_paid_ones_does_not(): void
    {
        $html = view('pdf.invoice', ['invoice' => $this->invoice()])->render();
        $this->assertStringContainsString(e(__('invoices.bank_transfer')), $html);
        $this->assertStringContainsString('IBAN: GR11 1111 1111 1111 1111 1111 111', $html);

        $html = view('pdf.invoice', ['invoice' => $this->invoice(Invoice::STATUS_PAID)])->render();
        $this->assertStringNotContainsString('IBAN: GR11', $html);
        $html = view('pdf.invoice', ['invoice' => $this->invoice(Invoice::STATUS_CANCELLED)])->render();
        $this->assertStringNotContainsString('IBAN: GR11', $html);
    }

    public function test_staff_can_keep_them_off_the_invoices_and_without_the_method_there_is_nothing(): void
    {
        $this->gateway->settings()->create(['key' => 'show_on_invoices', 'value' => '0']);
        $this->assertNull(BankTransferDetails::for($this->invoice()));

        $this->gateway->settings()->delete();
        $this->gateway->delete();
        $this->assertNull(BankTransferDetails::for($this->invoice()));
    }

    public function test_the_new_invoice_mail_lists_the_accounts_and_the_reference(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        $invoice = $this->invoice();

        NotificationHelper::invoiceCreatedNotification($this->user->client(), $invoice);

        $body = EmailLog::query()->latest('id')->firstOrFail()->body;
        $this->assertStringContainsString('IBAN: GR00 0000 0000 0000 0000 0000 000', $body);
        $this->assertStringContainsString('Other Bank', $body);
        $this->assertStringContainsString('<strong>' . e($invoice->number) . '</strong>', $body);
    }

    public function test_an_invoice_without_the_method_mails_without_the_block(): void
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        $this->gateway->settings()->delete();
        $this->gateway->delete();

        NotificationHelper::invoiceCreatedNotification($this->user->client(), $this->invoice());

        $this->assertStringNotContainsString('bank transfer', EmailLog::query()->latest('id')->firstOrFail()->body);
    }

    public function test_the_mail_template_takes_the_block_only_where_it_is_still_the_default(): void
    {
        $migration = require database_path('migrations/2026_12_14_000000_bank_transfer_in_invoice_mail.php');
        $default = EmailTemplateSeeder::mapping['new_invoice_created'];
        $block = '/\n *\{% if bank_transfer %\}.*?\{% endif %\}\n/s';
        $old = preg_replace($block, '', $default['body']);
        $oldEl = preg_replace($block, '', EmailTemplateSeeder::translations['new_invoice_created']['el']['body']);
        $translations = ['el' => ['body' => $oldEl] + EmailTemplateSeeder::translations['new_invoice_created']['el']];
        DB::table('notification_templates')->where('key', 'new_invoice_created')
            ->update(['body' => $old, 'translations' => json_encode($translations, JSON_UNESCAPED_UNICODE)]);

        $migration->up();

        $row = DB::table('notification_templates')->where('key', 'new_invoice_created')->first();
        $this->assertSame($default['body'], $row->body);
        $this->assertStringContainsString('{% if bank_transfer %}', json_decode($row->translations, true)['el']['body']);

        // Rewritten by staff: kept as it is.
        DB::table('notification_templates')->where('key', 'new_invoice_created')->update(['body' => 'Our own text']);
        $migration->up();
        $this->assertSame('Our own text', DB::table('notification_templates')->where('key', 'new_invoice_created')->value('body'));
    }
}
