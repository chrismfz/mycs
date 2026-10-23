<?php

namespace Tests\Feature;

use App\Livewire\Invoices\Show;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One «Total» (ROADMAP): the portal shows what the PDF and the email do, the account credit used on the invoice and the
 * total after it, translated; the automatic cancellation note in the customer's language.
 */
class InvoiceTotalWordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_portal_shows_the_credit_used_and_the_total_after_it(): void
    {
        config(['settings.credits_enabled' => true, 'settings.app_language' => 'el', 'settings.allowed_languages' => ['el', 'en']]);
        app()->setLocale('el');
        $user = User::factory()->create();
        $user->client()->credits()->create(['currency_code' => 'USD', 'amount' => 5.00]);
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20.00]);
        (new RecordPaymentService)->payWithCredit($invoice->fresh());

        $statement = $invoice->fresh()->statement();
        $this->assertEquals([5.0, 15.0], [$statement['credit'], $statement['total']]);
        $this->livewireAs($user)->test(Show::class, ['invoice' => $invoice->fresh()])
            ->assertSee(__('invoices.credit_applied'))->assertSee(__('invoices.total'))
            ->assertSee($invoice->fresh()->formattedTotal->format(15.0))->assertDontSee('>Total<', false);
    }

    public function test_the_automatic_cancellation_note_is_in_the_customers_language(): void
    {
        config(['settings.app_language' => 'el', 'settings.allowed_languages' => ['el', 'en']]);
        $user = User::factory()->create();
        $user->client()->forceFill(['language' => 'en'])->save();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 20.00]);

        $invoice->fresh()->createCancellationCreditNote();

        $this->assertSame(__('invoices.cancellation_reasons.automatic', [], 'en'), $invoice->adjustmentNotes()->sole()->description);
    }

    /** Code review of #388: the mails say the credit used before the total, in both languages; partly paid is not it. */
    public function test_the_mails_say_the_credit_used_before_the_total(): void
    {
        $id = DB::table('notification_templates')->where('key', 'new_invoice_created')->value('id');
        DB::table('notification_templates')->where('id', $id)->update([
            'body' => "- Total: **{{ total }}**\n",
            'translations' => json_encode(['el' => ['body' => "- Σύνολο: **{{ total }}**\n"]], JSON_UNESCAPED_UNICODE),
        ]);

        (require base_path('database/migrations/2026_12_29_000000_invoice_mail_credit_line.php'))->up();

        $row = DB::table('notification_templates')->where('id', $id)->first();
        $this->assertStringContainsString("- Credit applied: **{{ credit }}**\n{% endif %}\n- Total: **{{ total }}**", $row->body);
        $this->assertStringContainsString('- Πίστωση: **{{ credit }}**', json_decode($row->translations, true)['el']['body']);
        (require base_path('database/migrations/2026_12_29_000000_invoice_mail_credit_line.php'))->up();
        $this->assertSame(1, substr_count((string) DB::table('notification_templates')->where('id', $id)->value('body'), '{{ credit }}'), 'once');
    }
}
