<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * «Add Funds» never paid (a hook of myip's WHMCS): with cronjob_add_funds_cancel days, an open invoice that only buys
 * account credit is cancelled after them; never one billing anything else, one with money on it, a newer one, or with
 * the setting off (the default, as WHMCS).
 */
class AddFundsCancelTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $this->user = User::factory()->create();
    }

    private function invoice(int $daysOld, array $lines): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => now()->subDays($daysOld)]);
        foreach ($lines as $line) {
            $invoice->items()->create($line + ['quantity' => 1, 'price' => 50]);
        }
        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => now()->subDays($daysOld)]);

        return $invoice->fresh();
    }

    public function test_old_unpaid_credit_purchases_are_cancelled_and_nothing_else(): void
    {
        config(['settings.cronjob_add_funds_cancel' => 30]);
        $old = $this->invoice(31, [['reference_type' => Credit::class, 'description' => 'Add Funds']]);
        $recent = $this->invoice(10, [['reference_type' => Credit::class, 'description' => 'Add Funds']]);
        $mixed = $this->invoice(31, [['reference_type' => Credit::class, 'description' => 'Add Funds'], ['description' => 'Hosting']]);
        $hosting = $this->invoice(31, [['description' => 'Hosting']]);
        $paidInPart = $this->invoice(31, [['reference_type' => Credit::class, 'description' => 'Add Funds']]);
        ExtensionHelper::addPayment($paidInPart->id, null, 10.00, null, 'part-1', currency: 'USD');

        $this->artisan('app:cron-job');

        $this->assertSame(Invoice::STATUS_CANCELLED, $old->fresh()->status);
        $this->assertSame([Invoice::STATUS_UNPAID, Invoice::STATUS_UNPAID, Invoice::STATUS_UNPAID, Invoice::STATUS_UNPAID],
            [$recent->fresh()->status, $mixed->fresh()->status, $hosting->fresh()->status, $paidInPart->fresh()->status]);
    }

    public function test_off_by_default(): void
    {
        $old = $this->invoice(90, [['reference_type' => Credit::class, 'description' => 'Add Funds']]);

        $this->artisan('app:cron-job');

        $this->assertSame(Invoice::STATUS_UNPAID, $old->fresh()->status);
    }
}
