<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Mail\Mail;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Tests\TestCase;

/**
 * Adversarial review of #844: `amount` (the invoice's own total, before account credit) in the subjects of «new
 * invoice» and «payment received», and the migration that rewrites only subjects still as seeded.
 */
class Rev844AdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2027_02_28_000000_invoice_mail_subjects_show_the_amount.php';

    private const NEW_EN = 'New invoice #{{ invoice_number }} | Due {{ invoice.due_at|date("d/m/Y") }} | {{ amount }}';

    private const OLD_EN = 'New invoice #{{ invoice_number }} | Due {{ invoice.due_at|date("d/m/Y") }} | {{ total }}';

    private const OLD_EL = 'Νέο προτιμολόγιο #{{ invoice_number }} | Λήξη {{ invoice.due_at|date("d/m/Y") }} | {{ total }}';

    private const NEW_EL = 'Νέο προτιμολόγιο #{{ invoice_number }} | Λήξη {{ invoice.due_at|date("d/m/Y") }} | {{ amount }}';

    /** @return array<string, string> template key => subject, as sent */
    private function subjects(Invoice $invoice, array $keys = ['new_invoice_created', 'invoice_paid']): array
    {
        config(['settings.mail_disable' => false]);
        MailFacade::fake();
        foreach ($keys as $key) {
            NotificationHelper::invoiceNotification($invoice->client, $invoice, $key);
        }
        $subjects = [];
        foreach (MailFacade::queued(Mail::class)->merge(MailFacade::sent(Mail::class)) as $mail) {
            $subjects[$mail->templateKey] = $mail->envelope()->subject;
        }

        return $subjects;
    }

    /** An invoice of 1.80, $credit of it paid by account credit, the rest ($card) by a gateway. */
    private function invoice(string $credit, string $card = '0', array $item = []): Invoice
    {
        $client = User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addDays(7)]);
        $invoice->items()->create($item + ['description' => 'Agent', 'price' => 1.80, 'quantity' => 1]);
        if ((float) $credit > 0) {
            $invoice->transactions()->create(['amount' => $credit, 'status' => 'succeeded', 'is_credit_transaction' => true]);
        }
        if ((float) $card > 0) {
            $invoice->transactions()->create(['amount' => $card, 'status' => 'succeeded', 'is_credit_transaction' => false]);
        }
        if (bcadd($credit, $card, 2) === '1.80') {
            $invoice->update(['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);
        }

        return $invoice->fresh();
    }

    /**
     * ADV-844-1: the in-app notification (and push) of the same two templates still says «of {{ total }}», the total
     * after credit: for an invoice the account credit paid, the bell says «Invoice #… of $0.00» while the mail's subject
     * now says $1.80. The very bug the PR fixes, left in the other text of the same notification.
     */
    public function test_adv_844_1_in_app_body_of_a_credit_paid_invoice_still_says_zero(): void
    {
        $invoice = $this->invoice('1.80');
        // The factory's own «new invoice» went before its lines existed (a total of 0): only the ones sent for it now.
        Notification::query()->delete();
        $this->subjects($invoice);

        $bodies = Notification::query()->where('user_id', $invoice->client->owner()->id)->pluck('body')->all();
        $this->assertNotEmpty($bodies, 'in-app notifications were made');
        foreach ($bodies as $body) {
            $this->assertMatchesRegularExpression('/1[.,]80/', $body, $body);
            $this->assertDoesNotMatchRegularExpression('/\b0[.,]00/', $body, $body);
        }
    }

    public function test_adv_844_sound_partly_credit_paid_names_the_whole_amount(): void
    {
        $subjects = $this->subjects($this->invoice('1.00', '0.80'));

        foreach (['new_invoice_created', 'invoice_paid'] as $key) {
            $this->assertMatchesRegularExpression('/1[.,]80/', $subjects[$key], $key);
            $this->assertDoesNotMatchRegularExpression('/\b0[.,]80/', $subjects[$key], $key);
        }
    }

    public function test_adv_844_sound_no_credit_amount_equals_total(): void
    {
        $subjects = $this->subjects($this->invoice('0'));

        $this->assertMatchesRegularExpression('/1[.,]80$/', $subjects['new_invoice_created']);
    }

    public function test_adv_844_sound_tax_is_in_the_amount(): void
    {
        $invoice = $this->invoice('0', '0', ['price' => 1.00, 'tax_rate' => 24, 'tax_amount' => 0.24]);
        $this->assertSame(0.24, $invoice->taxTotal, 'the line keeps its tax');
        $subjects = $this->subjects($invoice);

        $this->assertGreaterThan(1.00, $invoice->total, 'the total has the tax');
        $this->assertMatchesRegularExpression('/' . preg_quote((string) $invoice->formattedTotal->format($invoice->total), '/') . '$/', $subjects['new_invoice_created']);
    }

    public function test_adv_844_sound_a_credit_note_lowers_the_amount_a_cancelled_one_says_zero(): void
    {
        $invoice = $this->invoice('0');
        $invoice->adjustmentNotes()->create(['type' => 'credit', 'amount' => -0.80, 'description' => 'Discount']);
        $subjects = $this->subjects($invoice->fresh());
        $this->assertMatchesRegularExpression('/1[.,]00$/', $subjects['new_invoice_created'], 'what it is due, after its notes');
    }

    // ---- the migration ----

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }

    private function row(string $key): object
    {
        return DB::table('notification_templates')->where('key', $key)->first();
    }

    public function test_adv_844_sound_odd_translations_never_fail_nor_change(): void
    {
        $odd = [null, '', 'not json', '"a string"', '[]', '{}', '{"el":"just text"}', '{"el":{"subject":null}}', '{"el":{"subject":["x"]}}', '42'];
        foreach ($odd as $translations) {
            DB::table('notification_templates')->where('key', 'new_invoice_created')->update(['subject' => self::OLD_EN, 'translations' => $translations]);

            $this->migration()->up();

            $row = $this->row('new_invoice_created');
            $this->assertSame(self::NEW_EN, $row->subject, var_export($translations, true));
            $this->assertSame($translations, $row->translations, 'untouched: ' . var_export($translations, true));
        }
    }

    public function test_adv_844_sound_other_locales_staff_text_and_double_runs(): void
    {
        $translations = json_encode([
            'el' => ['subject' => self::OLD_EL . ' ', 'body' => 'x'], // staff added a space: theirs
            'de' => ['subject' => self::OLD_EN, 'body' => 'y/z'],       // a locale with the English seeded text
        ], JSON_UNESCAPED_UNICODE);
        DB::table('notification_templates')->where('key', 'new_invoice_created')->update(['subject' => self::OLD_EL, 'translations' => $translations]);

        $this->migration()->up();
        $this->migration()->up();

        $row = $this->row('new_invoice_created');
        $this->assertSame(self::NEW_EL, $row->subject, 'a Greek default subject too');
        $decoded = json_decode($row->translations, true);
        $this->assertSame(self::OLD_EL . ' ', $decoded['el']['subject']);
        $this->assertSame(self::NEW_EN, $decoded['de']['subject']);
        $this->assertSame('y/z', $decoded['de']['body']);

        $this->migration()->down();
        $this->migration()->down();
        $row = $this->row('new_invoice_created');
        $this->assertSame(self::OLD_EL, $row->subject);
        $this->assertSame(self::OLD_EN, json_decode($row->translations, true)['de']['subject']);
    }

    public function test_adv_844_sound_other_templates_with_the_same_subject_are_not_touched(): void
    {
        DB::table('notification_templates')->where('key', 'invoice_modified')->update(['subject' => self::OLD_EN]);

        $this->migration()->up();

        $this->assertSame(self::OLD_EN, $this->row('invoice_modified')->subject, 'only the two keys');
    }
}
