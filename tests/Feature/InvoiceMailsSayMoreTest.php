<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Helpers\TemplateRenderer;
use App\Mail\Mail;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail as MailFacade;
use Tests\TestCase;

/**
 * The invoice mails say what, until when and what if unpaid (nixpal 2026-11-17): «what is this?» answered in the mail.
 */
class InvoiceMailsSayMoreTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2027_03_08_000000_invoice_mails_say_more.php';

    private const KEYS = ['new_invoice_created', 'invoice_paid', 'invoice_payment_reminder', 'card_payment_due', 'invoice_overdue', 'invoice_modified'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->user = User::factory()->create();
    }

    private function service(): Service
    {
        $product = $this->createProduct();

        return Service::factory()->create(['client_id' => $this->user->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => 'active', 'price' => 10, 'quantity' => 1, 'currency_code' => 'USD',
            'domain' => 'shop.example.gr', 'expires_at' => now()->addDays(7)->startOfDay()]);
    }

    private function invoice(Service $service, string $status): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID,
            'currency_code' => 'USD', 'due_at' => now()->addDays(7)->startOfDay()]);
        $invoice->items()->create(['description' => $service->describeLine(now()->addDays(7)->startOfDay(), now()->addDays(7)->startOfDay()->addYear()),
            'price' => 10, 'quantity' => 1, 'reference_type' => Service::class, 'reference_id' => $service->id,
            'period_start' => now()->addDays(7)->startOfDay(), 'period_end' => now()->addDays(7)->startOfDay()->addYear()]);
        if ($status === Invoice::STATUS_PAID) {
            $invoice->transactions()->create(['amount' => '10.00', 'status' => 'succeeded', 'is_credit_transaction' => true]);
            $invoice->update(['status' => Invoice::STATUS_PAID, 'paid_at' => now()]);
        }

        return $invoice->fresh();
    }

    private function body(Invoice $invoice, string $key): string
    {
        config(['settings.mail_disable' => false]);
        MailFacade::fake();
        NotificationHelper::invoiceNotification($invoice->client, $invoice, $key);
        $mail = MailFacade::queued(Mail::class)->merge(MailFacade::sent(Mail::class))->first(fn (Mail $mail) => $mail->templateKey === $key);
        $this->assertNotNull($mail, $key);

        return html_entity_decode(strip_tags($mail->renderedHtml, '<a>'));
    }

    public function test_each_line_links_to_its_service(): void
    {
        $service = $this->service();
        $html = $this->body($this->invoice($service, Invoice::STATUS_UNPAID), 'new_invoice_created');

        $this->assertStringContainsString('href="' . route('services.show', $service->id) . '"', $html);
    }

    public function test_a_new_invoice_says_when_its_services_are_suspended_unpaid(): void
    {
        config(['settings.cronjob_suspend_enabled' => true, 'settings.cronjob_order_suspend' => 3]);
        $invoice = $this->invoice($this->service(), Invoice::STATUS_UNPAID);
        $on = now()->addDays(7 + 3 + 1)->format('d/m/Y');

        $this->assertStringContainsString("suspended on {$on}", $this->body($invoice, 'new_invoice_created'));
        $this->assertStringContainsString("suspended on {$on}", $this->body($invoice, 'invoice_payment_reminder'));

        config(['settings.cronjob_suspend_enabled' => false]);
        $this->assertStringNotContainsString('suspended', $this->body($invoice, 'new_invoice_created'), 'no suspensions: not a word');
    }

    public function test_payment_received_says_until_when_it_paid(): void
    {
        $service = $this->service();
        $text = $this->body($this->invoice($service, Invoice::STATUS_PAID), 'invoice_paid');

        $this->assertStringContainsString('Paid until:', $text);
        $this->assertStringContainsString($service->fresh()->mailName . ': ' . now()->addDays(7)->addYear()->format('d/m/Y'), $text);
        $this->assertStringNotContainsString('suspended', $text);
    }

    public function test_a_new_invoice_charged_by_the_cron_says_when_and_with_what(): void
    {
        $body = (string) DB::table('notification_templates')->where('key', 'new_invoice_created')->value('body');
        $invoice = $this->invoice($this->service(), Invoice::STATUS_UNPAID);
        $data = ['invoice' => $invoice, 'items' => $invoice->items, 'has_subscription' => true, 'invoice_number' => $invoice->id];

        $html = TemplateRenderer::renderMarkdown($body, $data + ['auto_charge_method' => 'Visa 4242']);
        $this->assertStringContainsString('charged automatically on <strong>' . $invoice->due_at->format('d/m/Y') . '</strong> with <strong>Visa 4242</strong>', $html);
        $html = TemplateRenderer::renderMarkdown($body, $data + ['auto_charge_method' => '']);
        $this->assertStringContainsString('paid automatically with your saved payment method', $html, 'a gateway subscription: no date of ours');
    }

    public function test_the_migration_changes_only_text_as_seeded_twice_the_same_and_down_undoes_it(): void
    {
        $seeded = DB::table('notification_templates')->whereIn('key', self::KEYS)->pluck('body', 'key')->all();
        $translations = DB::table('notification_templates')->whereIn('key', self::KEYS)->pluck('translations', 'key')->all();
        $this->assertCount(count(self::KEYS), $seeded);
        $migration = require database_path(self::MIGRATION);

        $migration->down(); // as an instance seeded before it
        $old = DB::table('notification_templates')->where('key', 'invoice_paid')->value('body');
        $this->assertStringNotContainsString('paid_until', (string) $old);
        $this->assertStringNotContainsString('serviceUrl', (string) $old);
        DB::table('notification_templates')->where('key', 'invoice_overdue')->update(['body' => 'Staff\'s own: | {{ item.description }} |x']);

        $migration->up();
        $migration->up(); // twice: the same

        foreach (DB::table('notification_templates')->whereIn('key', self::KEYS)->get() as $row) {
            if ($row->key === 'invoice_overdue') {
                $this->assertStringContainsString('Staff\'s own:', $row->body);

                continue;
            }
            $this->assertSame($seeded[$row->key], $row->body, $row->key);
            $this->assertSame(json_decode((string) $translations[$row->key], true), json_decode((string) $row->translations, true), $row->key . ' (el)');
        }
    }
}
