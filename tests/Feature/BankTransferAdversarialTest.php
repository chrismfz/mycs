<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Livewire\Client\Credits;
use App\Livewire\Invoices\PayTogether;
use App\Livewire\Invoices\Show;
use App\Models\ChargeAttempt;
use App\Models\Credit;
use App\Models\EmailLog;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\Service;
use App\Models\User;
use App\Services\Invoice\MassPay;
use App\Services\Money\BankTransferDetails;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Adversarial review of #287 (bank transfer gateway), written without the author's context.
 *
 * FAILING tests are findings (BT-ADV-n). Tests named test_guard_* pass and pin behaviour the attacks did not break.
 *
 * Noted without a test:
 * - BT-ADV-4 (Low, design): no currency on the method (no canUseGateway): a USD invoice or credit top-up is told to wire
 *   USD to the one set of accounts, likely EUR IBANs.
 * - BT-ADV-5 (Info): the mail migration knows only the default right before #287 (57651ec7). A database seeded between
 *   3d920e8d and 57651ec7 (2026-10-14) holds an older default (en md5 03ec555b…/68d38afc…, el 438b9a38…) and is
 *   logged as «rewritten by staff», so it silently gets no bank transfer block.
 */
class BankTransferAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNTS = "Example Bank\nBeneficiary: Example Hosting IKE\nIBAN: GR00 0000 0000 0000 0000 0000 000";

    private User $user;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->gateway = Gateway::query()->create(['extension' => 'BankTransfer', 'name' => 'Bank transfer', 'type' => 'gateway']);
        $this->gateway->settings()->create(['key' => 'accounts', 'value' => self::ACCOUNTS]);
    }

    private function invoice(float $price = 50, string $status = Invoice::STATUS_UNPAID, ?Service $service = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $invoice->items()->create(array_filter([
            'description' => 'Hosting', 'quantity' => 1, 'price' => $price,
            'reference_type' => $service ? Service::class : null, 'reference_id' => $service?->id,
        ], fn ($value) => $value !== null));
        if ($status !== Invoice::STATUS_UNPAID) {
            Invoice::query()->whereKey($invoice->id)->update(['status' => $status]);
        }

        return $invoice->fresh();
    }

    private function service(string $status, ?string $subscription = null): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => $status, 'expires_at' => now()->addMonth(), 'currency_code' => 'USD', 'price' => 50.00,
            'subscription_id' => $subscription,
        ]);
    }

    private function setAccounts(string $accounts): void
    {
        $this->gateway->settings()->where('key', 'accounts')->update(['value' => $accounts]);
    }

    private function newInvoiceMail(Invoice $invoice): string
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        NotificationHelper::invoiceCreatedNotification($this->user->client(), $invoice);

        return EmailLog::query()->latest('id')->firstOrFail()->body;
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Findings (failing)
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * BT-ADV-1 (Medium). An invoice that bills an ended (cancelled/terminated) service: Show::processPayment refuses
     * every method ("bills a service that has ended: please contact us"), yet BankTransferDetails::for() does not check
     * billsEndedService(), so the PDF tells the customer to wire the money with the reference. Money arrives for an
     * invoice that "would buy nothing" and staff must refund it by hand.
     */
    public function test_bt_adv_1_the_pdf_of_an_invoice_billing_an_ended_service_asks_for_no_transfer(): void
    {
        $invoice = $this->invoice(service: $this->service(Service::STATUS_CANCELLED));
        $this->assertTrue($invoice->billsEndedService(), 'precondition');

        $this->assertNull(BankTransferDetails::for($invoice), 'the payment window refuses it; the PDF must not invite a transfer');
        $this->assertStringNotContainsString('IBAN: GR00', view('pdf.invoice', ['invoice' => $invoice])->render());
    }

    /** BT-ADV-1b (Medium). Same, the new invoice mail (e.g. a renewal invoice raised just before the service was terminated). */
    public function test_bt_adv_1b_the_mail_of_an_invoice_billing_an_ended_service_asks_for_no_transfer(): void
    {
        $invoice = $this->invoice(service: $this->service(Service::STATUS_TERMINATED));

        $this->assertStringNotContainsString('IBAN: GR00', $this->newInvoiceMail($invoice));
    }

    /**
     * BT-ADV-2 (Low). Staff free text goes through Str::markdown (mail layout) with unsafe links allowed: HTML is
     * escaped, but a Markdown link is not, so the accounts text puts a javascript: link (or a remote tracking image)
     * into every new-invoice mail. The PDF and the payment window print it as text.
     */
    public function test_bt_adv_2_markdown_in_the_accounts_cannot_inject_a_javascript_link_into_the_mail(): void
    {
        $this->setAccounts("Example Bank\n[Pay here](javascript:alert(document.cookie))\n![](https://tracker.example/p.gif)");

        $body = $this->newInvoiceMail($this->invoice());

        $this->assertStringNotContainsString('href="javascript:', $body);
        $this->assertStringNotContainsString('<img src="https://tracker.example', $body);
    }

    /**
     * BT-ADV-3 (Low, design). The PR keeps the accounts off the PDF and mail of an invoice a saved method pays on its own
     * (PaymentReminders::chargedOnItsOwn) to avoid a double payment, but the payment window still offers the method on
     * that same invoice with no warning: the customer wires the money (1-2 working days) and the cron charges the card
     * meanwhile; the transfer ends up as an overpayment/credit.
     */
    public function test_bt_adv_3_the_payment_window_does_not_offer_a_transfer_for_an_invoice_charged_on_its_own(): void
    {
        $invoice = $this->invoice(service: $this->service(Service::STATUS_ACTIVE, 'I-SUB1'));
        $this->assertNull(BankTransferDetails::for($invoice), 'precondition: PDF/mail already hide it');

        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $this->gateway->id)
            ->call('processPayment')
            ->assertDontSee('IBAN: GR00');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Guards (passing)
    // ---------------------------------------------------------------------------------------------------------------

    /** Choosing the method writes no transaction, no charge attempt, no status change, also for a partly paid invoice. */
    public function test_guard_choosing_it_records_nothing_and_shows_what_is_left(): void
    {
        $invoice = $this->invoice(50);
        ExtensionHelper::addPayment($invoice->id, null, 20);
        $invoice = $invoice->fresh();
        $before = InvoiceTransaction::query()->count();

        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $this->gateway->id)
            ->call('processPayment')
            ->assertSee(__('invoices.bank_transfer_intro', ['total' => (string) $invoice->formattedRemaining]))
            ->assertDontSee(__('invoices.bank_transfer_intro', ['total' => (string) $invoice->formattedTotal]));

        $this->assertSame($before, InvoiceTransaction::query()->count());
        $this->assertSame(0, ChargeAttempt::query()->count());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    /** Not offered in a Mass Pay group: it does not carry the group (supportsPaymentGroups false). */
    public function test_guard_not_offered_for_a_mass_pay_group(): void
    {
        $a = $this->invoice(10);
        $b = $this->invoice(20);
        $group = (new MassPay)->open($this->user->client(), [$a->id, $b->id], $this->user);

        $component = $this->livewireAs($this->user)->test(PayTogether::class, ['paymentGroup' => $group->fresh()]);
        $instance = $component->instance();
        $this->assertInstanceOf(PayTogether::class, $instance);
        $this->assertNotContains($this->gateway->id, array_column($instance->gateways(), 'id'));

        $component->set('selectedMethod', 'gateway-' . $this->gateway->id)->call('processPayment')->assertDontSee('IBAN: GR00');
    }

    /** The payment window refuses an invoice that bills an ended service before reaching the gateway. */
    public function test_guard_the_payment_window_refuses_an_invoice_billing_an_ended_service(): void
    {
        $invoice = $this->invoice(service: $this->service(Service::STATUS_CANCELLED));

        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $this->gateway->id)
            ->call('processPayment')
            ->assertDontSee('IBAN: GR00');
    }

    /** Draft, refunded, paid, cancelled: nothing; an unpaid one with nothing left either. */
    public function test_guard_nothing_on_settled_or_draft_invoices(): void
    {
        foreach ([Invoice::STATUS_DRAFT, Invoice::STATUS_REFUNDED, Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED] as $status) {
            $this->assertNull(BankTransferDetails::for($this->invoice(status: $status)), $status);
        }
        $zero = $this->invoice(0);
        $this->assertNull(BankTransferDetails::for($zero), 'nothing left to pay');
    }

    /** The checkbox saved by the admin form as false (stored as a string setting) turns it off. */
    public function test_guard_unticking_show_on_invoices_through_the_admin_save_turns_it_off(): void
    {
        ExtensionHelper::saveSettings($this->gateway->fresh(), ['accounts' => self::ACCOUNTS, 'show_on_invoices' => false]);

        $this->assertNull(BankTransferDetails::for($this->invoice()));
    }

    /** HTML/script in the staff text is escaped in the PDF, the payment window and the mail. */
    public function test_guard_html_in_the_accounts_is_escaped_everywhere(): void
    {
        $this->setAccounts("Example Bank\n<script>alert(1)</script><img src=x onerror=alert(2)>");
        $invoice = $this->invoice();

        $this->assertStringNotContainsString('<script>alert(1)', view('pdf.invoice', ['invoice' => $invoice])->render());
        $this->assertStringNotContainsString('<img src=x', view('pdf.invoice', ['invoice' => $invoice])->render());
        $this->livewireAs($this->user)->test(Show::class, ['invoice' => $invoice])
            ->set('selectedMethod', 'gateway-' . $this->gateway->id)
            ->call('processPayment')
            ->assertDontSee('<script>alert(1)', false)
            ->assertSee('&lt;script&gt;alert(1)', false);
        $body = $this->newInvoiceMail($invoice);
        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringNotContainsString('<img src=x', $body);
    }

    /** Another account's invoice cannot be opened in the payment window (the component's route/policy). */
    public function test_guard_another_accounts_invoice_does_not_show_the_window(): void
    {
        $other = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $other->client()->id, 'status' => Invoice::STATUS_UNPAID]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 50]);

        $this->actingAs($this->user)->withSession($this->loginUser($this->user))
            ->get(route('invoices.show', $invoice))->assertNotFound();
    }

    /** A credits top-up by transfer: the invoice is raised, unpaid, with no transaction; credit only when staff record it. */
    public function test_guard_a_credits_top_up_by_transfer_adds_no_credit(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_minimum_deposit' => 5, 'settings.credits_maximum_deposit' => 1000,
            'settings.credits_maximum_credit' => 10000, 'settings.default_currency' => 'USD']);

        $this->livewireAs($this->user)->test(Credits::class)
            ->set('currency', 'USD')->set('amount', 100)->set('gateway', $this->gateway->id)
            ->call('addCredit');

        $invoice = Invoice::query()->where('client_id', $this->user->client()->id)->latest('id')->firstOrFail();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertTrue($invoice->items->contains('reference_type', Credit::class));
        $this->assertSame(0, InvoiceTransaction::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(0, (int) Credit::query()->where('client_id', $this->user->client()->id)->sum('amount'));
    }

    /** Refunding a payment staff recorded on this gateway fails cleanly before any money moves (no refund support). */
    public function test_guard_a_refund_through_it_is_refused_before_anything_is_written(): void
    {
        $invoice = $this->invoice(50);
        $transaction = ExtensionHelper::addPayment($invoice->id, 'BankTransfer', 50, transactionId: 'bank-1');
        $this->assertInstanceOf(InvoiceTransaction::class, $transaction);

        try {
            ExtensionHelper::refund($transaction->fresh(), 10);
            $this->fail('refunded through an offline gateway');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not support refunds', $e->getMessage());
        }
        $this->assertEquals(0, (float) $transaction->fresh()->refunded_amount);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /** The migration with null, malformed, or a non-object translations column: no crash, English still updated. */
    public function test_guard_the_migration_survives_null_or_malformed_translations(): void
    {
        $migration = require database_path('migrations/2026_12_14_000000_bank_transfer_in_invoice_mail.php');
        $default = EmailTemplateSeeder::mapping['new_invoice_created'];
        // As it was then: without the credit line the seeder has since (2026_12_29, «Total»); the migration writes today's.
        $old = preg_replace(['/\n *\{% if bank_transfer %\}.*?\{% endif %\}\n/s', '/\n *\{% if credit %\}.*?\{% endif %\}/s'], ['', ''], $default['body']);

        // Malformed JSON only where the column can hold it: MariaDB's json column refuses it (its CHECK), so it never is there.
        $values = DB::connection()->getDriverName() === 'sqlite'
            ? [null, '{not json', '"a string"', '[1,2]', '{"el":"text"}', '{"el":{"body":null}}']
            : [null, '"a string"', '[1,2]', '{"el":"text"}', '{"el":{"body":null}}'];
        foreach ($values as $translations) {
            DB::table('notification_templates')->where('key', 'new_invoice_created')->update(['body' => $old, 'translations' => $translations]);
            $migration->up();
            $row = DB::table('notification_templates')->where('key', 'new_invoice_created')->first();
            $this->assertSame($default['body'], $row->body, var_export($translations, true));
            $this->assertSame($translations, $row->translations, 'a translations value it does not recognise is left as it is');
        }
    }

    /** The migration on a staff-edited English text and a still-default Greek one: English kept, Greek updated. */
    public function test_guard_the_migration_treats_each_language_on_its_own(): void
    {
        $migration = require database_path('migrations/2026_12_14_000000_bank_transfer_in_invoice_mail.php');
        $block = '/\n *\{% if bank_transfer %\}.*?\{% endif %\}\n/s';
        $el = EmailTemplateSeeder::translations['new_invoice_created']['el'];
        // As it was then: without the credit line the seeder has since (2026_12_29, «Total»).
        $translations = ['el' => ['body' => preg_replace([$block, '/\n *\{% if credit %\}.*?\{% endif %\}/s'], ['', ''], $el['body'])] + $el, 'de' => ['body' => 'Deutsch']];
        DB::table('notification_templates')->where('key', 'new_invoice_created')
            ->update(['body' => 'Staff text', 'translations' => json_encode($translations, JSON_UNESCAPED_UNICODE)]);

        $migration->up();
        $migration->up(); // re-runnable

        $row = DB::table('notification_templates')->where('key', 'new_invoice_created')->first();
        $this->assertSame('Staff text', $row->body);
        $stored = json_decode($row->translations, true);
        $this->assertSame($el['body'], $stored['el']['body']);
        $this->assertSame('Deutsch', $stored['de']['body']);
    }

    /** Every gateway loop (checkout lists, billing agreements, subscriptions) works with it installed. */
    public function test_guard_gateway_loops_survive_it(): void
    {
        $invoice = $this->invoice();
        $this->assertContains($this->gateway->id, array_column(ExtensionHelper::getCheckoutGateways(50, 'USD', 'invoice', $invoice->items), 'id'));
        $this->assertNotContains($this->gateway->id, array_column(ExtensionHelper::getBillingAgreementGateways(), 'id'));
        $this->assertFalse(ExtensionHelper::supportsPaymentGroups($this->gateway));
        $this->assertFalse(ExtensionHelper::cancelSubscription($this->service(Service::STATUS_ACTIVE)));
    }

    /** The reference matches the heading in every numbering mode (proforma, number = id, counter). */
    public function test_guard_the_reference_is_what_the_heading_shows(): void
    {
        foreach ([
            ['settings.invoice_proforma' => true, 'settings.invoice_number_is_id' => false],
            ['settings.invoice_proforma' => false, 'settings.invoice_number_is_id' => true],
            ['settings.invoice_proforma' => false, 'settings.invoice_number_is_id' => false],
            ['settings.invoice_proforma' => true, 'settings.invoice_number_is_id' => true],
        ] as $settings) {
            config($settings);
            $invoice = $this->invoice();
            $this->assertStringContainsString(BankTransferDetails::reference($invoice), $invoice->displayTitle(), json_encode($settings));
        }
    }
}
