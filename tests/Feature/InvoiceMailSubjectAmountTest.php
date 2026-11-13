<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Mail\Mail;
use App\Models\Invoice;
use App\Models\User;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Tests\TestCase;

/**
 * «New invoice» and «Payment received» name the invoice's own amount in their subject (owner 2026-11-13): an invoice
 * the account credit paid said «€0,00» there (nixpal 2026-6892). The body keeps «Credit applied» and the total after it.
 */
class InvoiceMailSubjectAmountTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2027_02_28_000000_invoice_mail_subjects_show_the_amount.php';

    private const OLD = [
        'new_invoice_created' => ['New invoice #{{ invoice_number }} | Due {{ invoice.due_at|date("d/m/Y") }} | {{ total }}',
            'Νέο προτιμολόγιο #{{ invoice_number }} | Λήξη {{ invoice.due_at|date("d/m/Y") }} | {{ total }}'],
        'invoice_paid' => ['Payment received: invoice #{{ invoice_number }} | {{ total }}',
            'Επιβεβαίωση πληρωμής: προτιμολόγιο #{{ invoice_number }} | {{ total }}'],
    ];

    /** @return array<string, string> template key => subject, as sent */
    private function subjects(Invoice $invoice): array
    {
        config(['settings.mail_disable' => false]);
        MailFacade::fake();
        NotificationHelper::invoiceNotification($invoice->client, $invoice, 'new_invoice_created');
        NotificationHelper::invoiceNotification($invoice->client, $invoice, 'invoice_paid');
        $subjects = [];
        foreach (MailFacade::queued(Mail::class)->merge(MailFacade::sent(Mail::class)) as $mail) {
            $subjects[$mail->templateKey] = $mail->envelope()->subject;
        }

        return $subjects;
    }

    private function creditPaid(): Invoice
    {
        $client = User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addDays(7)]);
        $invoice->items()->create(['description' => 'Agent', 'price' => 1.80, 'quantity' => 1]);
        $invoice->transactions()->create(['amount' => '1.80', 'status' => 'succeeded', 'is_credit_transaction' => true]);
        $invoice->update(['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);

        return $invoice->fresh();
    }

    public function test_an_invoice_credit_paid_names_its_amount_in_the_subject(): void
    {
        $subjects = $this->subjects($this->creditPaid());

        foreach (['new_invoice_created', 'invoice_paid'] as $key) {
            $this->assertMatchesRegularExpression('/1[.,]80/', $subjects[$key], $key);
            $this->assertDoesNotMatchRegularExpression('/\b0[.,]00/', $subjects[$key], $key);
        }
    }

    public function test_the_migration_changes_only_subjects_as_seeded_and_down_undoes_it(): void
    {
        foreach (self::OLD as $key => [$en, $el]) {
            DB::table('notification_templates')->where('key', $key)->update(['subject' => $en,
                'translations' => json_encode(['el' => ['subject' => $el, 'body' => 'σώμα']], JSON_UNESCAPED_UNICODE)]);
        }
        // Staff's own subject in English: kept; its Greek still as seeded: changed.
        DB::table('notification_templates')->where('key', 'invoice_paid')->update(['subject' => 'Thanks! {{ total }}']);
        $migration = require database_path(self::MIGRATION);

        $migration->up();
        $migration->up(); // twice: the same

        $rows = DB::table('notification_templates')->whereIn('key', array_keys(self::OLD))->get()->keyBy('key');
        $this->assertSame('New invoice #{{ invoice_number }} | Due {{ invoice.due_at|date("d/m/Y") }} | {{ amount }}', $rows['new_invoice_created']->subject);
        $this->assertSame('Thanks! {{ total }}', $rows['invoice_paid']->subject, 'staff\'s own: as it is');
        $this->assertSame('Επιβεβαίωση πληρωμής: προτιμολόγιο #{{ invoice_number }} | {{ amount }}', json_decode($rows['invoice_paid']->translations, true)['el']['subject']);
        $this->assertSame('σώμα', json_decode($rows['invoice_paid']->translations, true)['el']['body']);

        $migration->down();
        $rows = DB::table('notification_templates')->whereIn('key', array_keys(self::OLD))->get()->keyBy('key');
        $this->assertSame(self::OLD['new_invoice_created'][0], $rows['new_invoice_created']->subject);
        $this->assertSame(self::OLD['invoice_paid'][1], json_decode($rows['invoice_paid']->translations, true)['el']['subject']);
    }

    public function test_a_fresh_install_seeds_them_so_and_the_other_subjects_keep_the_total_left(): void
    {
        DB::table('notification_templates')->whereIn('key', ['new_invoice_created', 'invoice_paid', 'invoice_overdue'])->delete();
        EmailTemplateSeeder::seedKeys(['new_invoice_created', 'invoice_paid', 'invoice_overdue']);

        $rows = DB::table('notification_templates')->whereIn('key', ['new_invoice_created', 'invoice_paid', 'invoice_overdue'])->get()->keyBy('key');
        foreach (['new_invoice_created', 'invoice_paid'] as $key) {
            $this->assertStringEndsWith('{{ amount }}', $rows[$key]->subject);
            $this->assertStringEndsWith('{{ amount }}', json_decode($rows[$key]->translations, true)['el']['subject']);
        }
        $this->assertStringEndsWith('{{ total }}', $rows['invoice_overdue']->subject, 'what is left to pay: after credit');
    }
}
