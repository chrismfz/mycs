<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Helpers\NotificationHelper;
use App\Livewire\Client\Credits;
use App\Livewire\Invoices\Show;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\Extension;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/** Adversarial review of #539: the preferred payment method per invoice. */
class InvoicePreferredMethodAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Gateway $bank;

    private Gateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->bank = Gateway::query()->create(['name' => 'Bank Transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        $this->stripe = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
    }

    private function card(int $clientId): BillingAgreement
    {
        // The account's first method: its default (BillingAgreement::created).
        return BillingAgreement::query()->create(['ulid' => (string) Str::ulid(), 'client_id' => $clientId, 'gateway_id' => $this->stripe->id,
            'name' => 'Visa **** 4242', 'type' => 'visa', 'external_reference' => 'pm_' . Str::random(8)]);
    }

    /**
     * ADV-539-1. A customer with a saved card adds funds and chooses Bank Transfer on the credits page. The invoice made
     * there is not given that gateway, so the observer gives it the saved card's (Stripe); the customer is sent to the
     * invoice (Credits::addCredit redirects there with ?gateway=…&pay, which Show ignores), whose payment window has the
     * saved card chosen for them: one click on «Pay now» charges the card, not the transfer they picked.
     */
    public function test_adv_539_1_add_funds_keeps_the_gateway_the_customer_chose(): void
    {
        config(['settings.credits_enabled' => true, 'settings.credits_minimum_deposit' => 5, 'settings.credits_maximum_deposit' => 1000,
            'settings.credits_maximum_credit' => 10000, 'settings.default_currency' => 'USD']);
        $user = User::factory()->create();
        $client = $user->client();
        $card = $this->card($client->id);
        $this->assertSame($card->id, $client->fresh()->default_billing_agreement_id);

        $this->livewireAs($user)->test(Credits::class)
            ->set('currency', 'USD')->set('amount', 100)->set('gateway', $this->bank->id)
            ->call('addCredit');

        $invoice = Invoice::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $page = $this->livewireAs($user)->test(Show::class, ['invoice' => $invoice->fresh()]);
        $this->assertNotSame($card->ulid, $page->get('selectedMethod'), 'the saved card is chosen for a top-up the customer asked to pay by transfer');
        $this->assertSame($this->bank->id, $invoice->preferred_gateway_id, 'the gateway chosen on the credits page is not the invoice\'s preferred one');
    }

    /**
     * ADV-539-2. The import of the invoices' preferred methods installs a gateway for any module an invoice ever had, even
     * one only old, paid invoices used ('mollie' here) or the bank transfer when none is set up. A gateway row has no
     * «enabled» gate (ExtensionHelper::getCheckoutGateways offers every one), so an unconfigured gateway is offered to every
     * customer at checkout and on every invoice, just to label invoices nobody will pay again.
     */
    public function test_adv_539_2_the_import_installs_no_gateway_for_a_preference(): void
    {
        $client = User::factory()->create()->client();
        $old = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid']);
        Invoice::query()->whereKey($old->id)->update(['status' => 'paid']);
        $gatewaysBefore = Extension::query()->where('type', 'gateway')->count();

        $whmcs = new \PDO('sqlite::memory:');
        $whmcs->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, paymentmethod TEXT)');
        $whmcs->prepare("INSERT INTO tblinvoices VALUES (?, 'mollie')")->execute([$old->id]);
        $command = app(ImportFromWhmcs::class);
        (new \ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new \ReflectionMethod($command, 'importInvoicePreferences'))->invoke($command);

        $this->assertSame($gatewaysBefore, Extension::query()->where('type', 'gateway')->count(),
            'a Mollie gateway was installed (and is now offered at checkout) only to label an old paid invoice');
    }

    /**
     * ADV-539-3. The email's button names the preferred method even when it cannot take the invoice (here the bank
     * transfer takes only EUR, the invoice is in USD). The page chooses nothing and does not even offer it, but the email
     * says «Pay with Bank Transfer».
     */
    public function test_adv_539_3_the_email_names_no_method_that_cannot_take_the_invoice(): void
    {
        MailFacade::fake();
        config(['settings.mail_disable' => false]);
        $this->bank->settings()->create(['key' => 'currencies', 'value' => 'EUR']);
        $client = User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'preferred_gateway_id' => $this->bank->id]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20.00]);

        NotificationHelper::invoiceNotification($client, $invoice->fresh(), 'new_invoice_created');

        MailFacade::assertQueued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'new_invoice_created');
        MailFacade::assertQueued(Mail::class, fn (Mail $mail) => $mail->templateKey === 'new_invoice_created'
            && !str_contains($mail->renderedHtml, 'Pay with Bank Transfer'));
    }

    /**
     * ADV-539-4. Staff set an invoice to be paid by bank transfer. The customer tries the saved card and the charge is
     * refused before anything is sent (here: no Stripe customer). Nothing was paid, yet the invoice now prefers Stripe:
     * staff's choice is gone, the next reminder says «Pay with Stripe» and its page chooses the card that just failed.
     */
    public function test_adv_539_4_a_refused_attempt_does_not_replace_the_preferred_method(): void
    {
        $user = User::factory()->create();
        $client = $user->client();
        $card = $this->card($client->id);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'preferred_gateway_id' => $this->bank->id]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20.00]);

        $this->livewireAs($user)->test(Show::class, ['invoice' => $invoice->fresh()])
            ->set('selectedMethod', $card->ulid)->call('processPayment');

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
        $this->assertSame($this->bank->id, $invoice->fresh()->preferred_gateway_id, 'a refused charge replaced staff\'s preferred method');
    }

    /**
     * ADV-539-5. The button migration runs a /u regex over every template. On a body that is not valid UTF-8 (an old,
     * Latin-1 or Windows-1253 text, kept by SQLite) preg_replace returns null, which the migration casts to '' and writes
     * back: the template's body is wiped.
     */
    public function test_adv_539_5_the_button_migration_never_wipes_a_template(): void
    {
        // MariaDB / MySQL (utf8mb4) refuse invalid UTF-8 on insert: such a body cannot exist there (as this review noted).
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('utf8mb4 stores no invalid UTF-8: the case exists on SQLite only.');
        }
        $body = "<p>Αγαπητέ πελάτη \xE1\xE3\xE1\xF0\xE7\xF4\xDD</p><a href=\"{{ urls.invoice }}\">View & pay invoice</a>";
        DB::table('notification_templates')->insert(['key' => 'legacy_latin', 'subject' => 's', 'enabled' => true, 'body' => $body, 'translations' => null]);

        (require database_path('migrations/2027_01_23_000001_invoice_buttons_name_preferred_method.php'))->up();

        $this->assertNotSame('', (string) DB::table('notification_templates')->where('key', 'legacy_latin')->value('body'), 'the template body was wiped');
    }
}
