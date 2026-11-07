<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Audit;
use App\Models\EkdosiDocument;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Invoice\ChangeOpenLine;
use App\Support\AuditSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner 2026-11-07: a line ChangeOpenLine keeps at its price (a payment or an Ekdosi document on its invoice) is in the
 * invoice's activity for staff, whoever asked for the change, not only in payments.log. Once per line and outcome.
 */
class KeptLineActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('audit.console', true); // as the cron sets it
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        $this->user = User::factory()->create();
    }

    private function line(float $price = 10): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);

        return $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);
    }

    /** @return list<Audit> */
    private function kept(InvoiceItem $line): array
    {
        return Audit::query()->where('auditable_type', (new Invoice)->getMorphClass())->where('auditable_id', $line->invoice_id)
            ->where('event', ChangeOpenLine::AUDIT_EVENT)->orderBy('id')->get()->all();
    }

    public function test_a_line_kept_by_a_payment_is_in_the_invoice_activity(): void
    {
        $line = $this->line();
        $line->invoice->transactions()->create(['amount' => 5, 'status' => InvoiceTransactionStatus::Succeeded]);

        $this->assertFalse(ChangeOpenLine::handle($line, fn (InvoiceItem $locked) => $locked->price = 12));

        $this->assertSame('10.00', number_format((float) $line->fresh()->price, 2, '.', ''), 'the line keeps its price');
        $audits = $this->kept($line);
        $this->assertCount(1, $audits);
        $this->assertSame((int) $line->id, $audits[0]->old_values['invoice_item_id']);
        $this->assertSame(ChangeOpenLine::KEPT_PAYMENT, $audits[0]->new_values['kept_because']);
        $this->assertEquals(10, $audits[0]->old_values['price']);
        $this->assertEquals(12, $audits[0]->new_values['price']);
        $this->assertSame('Line Kept At Price', AuditSummary::verb($audits[0]->event));
        $this->assertStringContainsString('Price: ', AuditSummary::changes($audits[0]));
    }

    public function test_a_line_kept_by_an_ekdosi_document_says_so(): void
    {
        $line = $this->line();
        EkdosiDocument::query()->create(['invoice_id' => $line->invoice_id, 'mark' => '400001234567899', 'code' => 'ΤΠΥ9', 'state' => EkdosiDocument::ACTIVE]);

        $this->assertFalse(ChangeOpenLine::handle($line, fn (InvoiceItem $locked) => $locked->price = 12));

        $audits = $this->kept($line);
        $this->assertCount(1, $audits);
        $this->assertSame(ChangeOpenLine::KEPT_EKDOSI, $audits[0]->new_values['kept_because']);
    }

    public function test_the_same_outcome_tried_again_adds_no_row_but_a_new_one_does(): void
    {
        $line = $this->line();
        $line->invoice->transactions()->create(['amount' => 5, 'status' => InvoiceTransactionStatus::Succeeded]);

        // The cron tries the same change every day: one row.
        ChangeOpenLine::handle($line, fn (InvoiceItem $locked) => $locked->price = 12);
        ChangeOpenLine::handle($line, fn (InvoiceItem $locked) => $locked->price = 12);
        ChangeOpenLine::handle($line, fn (InvoiceItem $locked) => $locked->price = 12.0);
        $this->assertCount(1, $this->kept($line));

        // Another outcome (the price moved again): told.
        ChangeOpenLine::handle($line, fn (InvoiceItem $locked) => $locked->price = 14);
        $this->assertCount(2, $this->kept($line));
    }

    public function test_each_line_of_the_invoice_is_told_on_its_own(): void
    {
        $line = $this->line();
        $other = $line->invoice->items()->create(['description' => 'Addon', 'quantity' => 1, 'price' => 3]);
        $line->invoice->transactions()->create(['amount' => 5, 'status' => InvoiceTransactionStatus::Succeeded]);

        ChangeOpenLine::handle($line, fn (InvoiceItem $locked) => $locked->price = 12);
        ChangeOpenLine::handle($other, fn (InvoiceItem $locked) => $locked->price = 12);

        $this->assertCount(2, $this->kept($line));
    }

    public function test_nothing_is_told_when_the_change_would_change_nothing_or_was_made(): void
    {
        $paid = $this->line();
        $paid->invoice->transactions()->create(['amount' => 5, 'status' => InvoiceTransactionStatus::Succeeded]);
        ChangeOpenLine::handle($paid, fn (InvoiceItem $locked) => $locked->price = 10);
        $this->assertSame([], $this->kept($paid), 'the same price: nothing kept');

        $open = $this->line();
        $this->assertTrue(ChangeOpenLine::handle($open, fn (InvoiceItem $locked) => $locked->price = 12));
        $this->assertSame([], $this->kept($open), 'changed, not kept');
    }

    public function test_the_callers_kept_callback_is_still_told(): void
    {
        $line = $this->line();
        $line->invoice->transactions()->create(['amount' => 5, 'status' => InvoiceTransactionStatus::Succeeded]);
        $told = [];

        ChangeOpenLine::handle($line, fn (InvoiceItem $locked) => $locked->price = 12, function (InvoiceItem $billed, InvoiceItem $wouldBe) use (&$told) {
            $told[] = [(string) $billed->price, (string) $wouldBe->price];
        });

        $this->assertCount(1, $told);
        $this->assertCount(1, $this->kept($line));
    }
}
