<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Helpers\TemplateRenderer;
use App\Mail\Mail;
use App\Models\BillingAgreement;
use App\Models\Client;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\AutoCharge;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Tests\TestCase;

/**
 * The invoice emails (owner 2026-11-01): the Greek ones show the subtotal and VAT, as the English ones and WHMCS's did,
 * and «paid automatically» names the saved method that pays it (#568). The migration changes only text still as seeded.
 */
class InvoiceMailTaxMethodTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_EL = "- Προτιμολόγιο: **#{{ invoice_number }}**\n{% if credit %}\n- Πίστωση: **{{ credit }}**\n{% endif %}\n- Σύνολο: **{{ total }}**\n\n"
        . "{% if has_subscription %}\nΘα εξοφληθεί αυτόματα με τον αποθηκευμένο τρόπο πληρωμής σας: δεν χρειάζεται να κάνετε κάτι.\n{% endif %}";

    private function migrate(): void
    {
        (require database_path('migrations/2027_01_30_000000_invoice_mails_tax_in_greek_and_the_charging_method.php'))->up();
    }

    /** @param array<string, mixed> $with */
    private function render(string $template, array $with = []): string
    {
        return TemplateRenderer::render($template, $with + ['invoice_number' => '7275', 'credit' => '', 'total' => '24,80 €',
            'subtotal' => '20,00 €', 'tax' => '4,80 €', 'has_subscription' => true, 'auto_charge_method' => 'Visa 4242']);
    }

    public function test_the_migration_adds_the_greek_vat_and_names_the_method_where_the_text_is_as_seeded(): void
    {
        DB::table('notification_templates')->where('key', 'new_invoice_created')->delete();
        DB::table('notification_templates')->insert(['key' => 'new_invoice_created', 'subject' => 's', 'enabled' => true,
            'body' => 'It will be paid automatically with your saved payment method: there is nothing you need to do.',
            'translations' => json_encode(['el' => ['subject' => 's', 'body' => self::OLD_EL]], JSON_UNESCAPED_UNICODE)]);
        // Staff's own: an invoice template whose Greek body they rewrote (no seeded invoice line, no seeded sentence).
        DB::table('notification_templates')->where('key', 'invoice_overdue')->delete();
        DB::table('notification_templates')->insert(['key' => 'invoice_overdue', 'subject' => 's', 'enabled' => true,
            'body' => 'We will charge you, dear customer.', 'translations' => json_encode(['el' => ['body' => "Προτιμολόγιο #{{ invoice_number }}\nΣύνολο {{ total }}"]], JSON_UNESCAPED_UNICODE)]);

        $this->migrate();
        $this->migrate(); // twice: the same

        $template = DB::table('notification_templates')->where('key', 'new_invoice_created')->first();
        $el = json_decode($template->translations, true)['el']['body'];
        $this->assertSame(1, substr_count($el, '{% if tax %}'), 'once, also run twice');
        $this->assertStringContainsString('- ΦΠΑ: **4,80 €**', $this->render($el));
        $this->assertStringContainsString('Θα εξοφληθεί αυτόματα με Visa 4242', $this->render($el));
        $this->assertStringContainsString('με τον αποθηκευμένο τρόπο πληρωμής σας', $this->render($el, ['auto_charge_method' => '']), 'no method known: as before');
        $this->assertStringContainsString('paid automatically with Visa 4242', $this->render((string) $template->body));
        $custom = DB::table('notification_templates')->where('key', 'invoice_overdue')->first();
        $this->assertSame('We will charge you, dear customer.', $custom->body, 'staff\'s own text: as it is');
        $this->assertSame("Προτιμολόγιο #{{ invoice_number }}\nΣύνολο {{ total }}", json_decode($custom->translations, true)['el']['body'], 'staff\'s own Greek: as it is');
    }

    /** @return array<string, string> template key => the mail's text, as sent for this invoice */
    private function mails(Client $client, Invoice $invoice): array
    {
        MailFacade::fake();
        NotificationHelper::invoiceNotification($client, $invoice, 'card_payment_due');
        NotificationHelper::invoiceNotification($client, $invoice, 'new_invoice_created');
        $texts = [];
        foreach (MailFacade::queued(Mail::class)->merge(MailFacade::sent(Mail::class)) as $mail) {
            $texts[$mail->templateKey] = html_entity_decode(strip_tags($mail->renderedHtml));
        }

        return $texts;
    }

    /** @return array{0: Client, 1: Invoice} */
    private function invoiceOf(?string $subscription = null): array
    {
        config(['settings.mail_disable' => false]);
        $client = User::factory()->create()->client();
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Stripe'], ['name' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        BillingAgreement::query()->create(['client_id' => $client->id, 'gateway_id' => $gateway->id, 'name' => 'Visa **** 4242', 'external_reference' => 'pm_x', 'type' => 'card']);
        $client->refresh();
        $product = Product::factory()->create();
        $plan = Plan::factory()->create(['priceable_id' => $product->id, 'priceable_type' => Product::class, 'billing_unit' => 'month', 'billing_period' => 1, 'type' => 'recurring']);
        Price::factory()->create(['plan_id' => $plan->id, 'price' => 10, 'currency_code' => 'USD']);
        $service = Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id, 'plan_id' => $plan->id, 'price' => 10, 'quantity' => 1,
            'currency_code' => 'USD', 'status' => Service::STATUS_ACTIVE, 'subscription_id' => $subscription, 'expires_at' => now()->addDays(3)]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addDays(3)]);
        $invoice->items()->create(['description' => 'Renewal', 'price' => 10, 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id]);

        return [$client, $invoice->fresh()];
    }

    public function test_the_mails_name_the_card_the_cron_charges(): void
    {
        [$client, $invoice] = $this->invoiceOf();

        $mails = $this->mails($client, $invoice);

        $this->assertStringContainsString('with Visa **** 4242', $mails['card_payment_due']);
        $this->assertStringContainsString('paid automatically with Visa **** 4242', $mails['new_invoice_created']);
    }

    /** Review F1 of #573: a gateway subscription pays it, the cron never charges the saved card: not named. */
    public function test_an_invoice_a_subscription_pays_names_no_card(): void
    {
        [$client, $invoice] = $this->invoiceOf('I-PAYPALSUB');
        $this->assertNotNull(AutoCharge::agreementFor($invoice), 'precondition: the account has a saved card');

        $mails = $this->mails($client, $invoice);

        $this->assertStringNotContainsString('4242', implode("\n", $mails));
        $this->assertStringContainsString('with your saved payment method', $mails['new_invoice_created']);
    }

    /** Review F3 of #573: nothing left to pay (credit paid it before the mail went): no card named. */
    public function test_an_invoice_with_nothing_left_names_no_card(): void
    {
        [$client, $invoice] = $this->invoiceOf();
        ExtensionHelper::addPayment($invoice->id, null, '10.00', null, 'paid-' . $invoice->id, currency: 'USD');

        $this->assertStringNotContainsString('4242', implode("\n", $this->mails($client, $invoice->fresh())));
    }

    public function test_a_fresh_install_seeds_them_so(): void
    {
        DB::table('notification_templates')->whereIn('key', ['new_invoice_created', 'card_payment_due', 'invoice_overdue'])->delete();
        EmailTemplateSeeder::seedKeys(['new_invoice_created', 'card_payment_due', 'invoice_overdue']);

        $templates = DB::table('notification_templates')->whereIn('key', ['new_invoice_created', 'card_payment_due', 'invoice_overdue'])->get()->keyBy('key');
        $el = fn (string $key) => json_decode($templates[$key]->translations, true)['el']['body'];
        $this->assertStringContainsString('- Μερικό σύνολο: **20,00 €**', $this->render($el('new_invoice_created')));
        $this->assertStringContainsString('- ΦΠΑ: **4,80 €**', $this->render($el('invoice_overdue')));
        $this->assertStringContainsString('με **Visa 4242**', $this->render($el('card_payment_due')));
        $this->assertStringContainsString('with **Visa 4242**', $this->render((string) $templates['card_payment_due']->body));
    }
}
