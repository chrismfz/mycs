<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** An unpaid invoice is «unpaid» in the database too, as WHMCS calls it (docs/importer.md); no other word gets in. */
class InvoiceStatusUnpaidTest extends TestCase
{
    use RefreshDatabase;

    private function rawInvoice(array $attributes = []): int
    {
        return DB::table('invoices')->insertGetId(array_merge([
            'user_id' => User::factory()->create()->id, 'currency_code' => 'USD', 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    public function test_unpaid_is_the_word_and_pending_is_gone(): void
    {
        $this->assertSame('unpaid', Invoice::STATUS_UNPAID);
        $this->assertNotContains('pending', Invoice::STATUSES);
    }

    public function test_an_unknown_status_is_refused_on_create(): void
    {
        $this->expectException(\LogicException::class);
        Invoice::factory()->create(['user_id' => User::factory()->create()->id, 'status' => 'pending', 'currency_code' => 'USD']);
    }

    public function test_an_unknown_status_is_refused_on_update(): void
    {
        $invoice = Invoice::factory()->create(['user_id' => User::factory()->create()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);

        try {
            $invoice->update(['status' => 'pending']);
            $this->fail('pending is not an invoice status any more');
        } catch (\LogicException) {
        }

        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    public function test_the_migration_renames_old_pending_rows_and_only_those(): void
    {
        $old = $this->rawInvoice(['status' => 'pending']);
        $paid = $this->rawInvoice(['status' => Invoice::STATUS_PAID]);
        $migration = require database_path('migrations/2026_10_21_000000_rename_invoice_status_pending_to_unpaid.php');

        $migration->up();

        $this->assertSame(Invoice::STATUS_UNPAID, DB::table('invoices')->where('id', $old)->value('status'));
        $this->assertSame(Invoice::STATUS_PAID, DB::table('invoices')->where('id', $paid)->value('status'));
    }
}
