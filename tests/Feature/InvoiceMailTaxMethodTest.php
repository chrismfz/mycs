<?php

namespace Tests\Feature;

use App\Helpers\TemplateRenderer;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        DB::table('notification_templates')->insert(['key' => 'custom_mail', 'subject' => 's', 'enabled' => true,
            'body' => 'We will charge you, dear customer.', 'translations' => json_encode(['el' => ['body' => '- Προτιμολόγιο: **#{{ invoice_number }}**\n']], JSON_UNESCAPED_UNICODE)]);

        $this->migrate();
        $this->migrate(); // twice: the same

        $template = DB::table('notification_templates')->where('key', 'new_invoice_created')->first();
        $el = json_decode($template->translations, true)['el']['body'];
        $this->assertSame(1, substr_count($el, '{% if tax %}'), 'once, also run twice');
        $this->assertStringContainsString('- ΦΠΑ: **4,80 €**', $this->render($el));
        $this->assertStringContainsString('Θα εξοφληθεί αυτόματα με Visa 4242', $this->render($el));
        $this->assertStringContainsString('με τον αποθηκευμένο τρόπο πληρωμής σας', $this->render($el, ['auto_charge_method' => '']), 'no method known: as before');
        $this->assertStringContainsString('paid automatically with Visa 4242', $this->render((string) $template->body));
        $custom = DB::table('notification_templates')->where('key', 'custom_mail')->first();
        $this->assertSame('We will charge you, dear customer.', $custom->body, 'staff\'s own text: as it is');
        $this->assertStringNotContainsString('ΦΠΑ', (string) $custom->translations, 'not an invoice template');
    }

    public function test_a_fresh_install_seeds_them_so(): void
    {
        DB::table('notification_templates')->whereIn('key', ['new_invoice_created', 'card_payment_due', 'invoice_overdue'])->delete();
        EmailTemplateSeeder::seedKeys(['new_invoice_created', 'card_payment_due', 'invoice_overdue']);

        $templates = DB::table('notification_templates')->whereIn('key', ['new_invoice_created', 'card_payment_due', 'invoice_overdue'])->get()->keyBy('key');
        $el = fn (string $key) => json_decode($templates[$key]->translations, true)['el']['body'];
        $this->assertStringContainsString('- Υποσύνολο: **20,00 €**', $this->render($el('new_invoice_created')));
        $this->assertStringContainsString('- ΦΠΑ: **4,80 €**', $this->render($el('invoice_overdue')));
        $this->assertStringContainsString('με **Visa 4242**', $this->render($el('card_payment_due')));
        $this->assertStringContainsString('with **Visa 4242**', $this->render((string) $templates['card_payment_due']->body));
    }
}
