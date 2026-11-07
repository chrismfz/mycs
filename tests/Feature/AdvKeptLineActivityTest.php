<?php

namespace Tests\Feature;

use App\Enums\InvoiceTransactionStatus;
use App\Models\Audit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Price;
use App\Models\Service;
use App\Models\ServiceUpgrade;
use App\Models\User;
use App\Services\Invoice\ChangeOpenLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Adversarial review of fix/kept-line-activity (ChangeOpenLine writes «line_kept_at_price» on the invoice, once per line
 * and outcome). Each test is a scenario the change gets wrong; a failing one is a confirmed finding.
 */
class AdvKeptLineActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 10:00:00');
        Config::set('audit.console', true);
        config([
            'settings.cronjob_invoice' => 7,
            'settings.credits_enabled' => false,
            'settings.cronjob_order_suspend' => 2,
            'settings.cronjob_order_terminate' => 14,
        ]);
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        Cache::flush();
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function line(float $price = 10): InvoiceItem
    {
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);

        return $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);
    }

    /** @return list<Audit> */
    private function kept(int $invoiceId): array
    {
        return Audit::query()->where('auditable_type', (new Invoice)->getMorphClass())->where('auditable_id', $invoiceId)
            ->where('event', ChangeOpenLine::AUDIT_EVENT)->orderBy('id')->get()->all();
    }

    /**
     * ADV-KL-1. The commit says «the cron trying the same change every day adds no row a day». The cron's upgrade step
     * (CronJob::refreshUpgrade) reprices a pending upgrade's line to what is left of the period TODAY: on an invoice with
     * a part payment, every day is a new «outcome» (one cent or more less), so the invoice's activity gets a row a day,
     * per line (charge and discount), for as long as the upgrade waits.
     */
    public function test_adv_kl_1_the_daily_upgrade_reprice_of_a_part_paid_invoice_adds_no_row_a_day(): void
    {
        $product = $this->createProduct();
        Price::query()->where('plan_id', $product->plan->id)->update(['price' => 10]);
        $bigger = $this->createProduct();
        Price::query()->where('plan_id', $bigger->plan->id)->update(['price' => 40]);
        $service = Service::factory()->create([
            'client_id' => $this->user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'expires_at' => '2026-11-10', 'currency_code' => 'USD', 'price' => 10.00,
        ]);
        $invoice = Invoice::factory()->create(['client_id' => $this->user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD', 'due_at' => now()->addWeek()]);
        $upgrade = ServiceUpgrade::create([
            'service_id' => $service->id, 'plan_id' => $bigger->plan->id, 'product_id' => $bigger->product->id,
            'status' => 'pending', 'invoice_id' => $invoice->id,
        ]);
        $invoice->items()->create([
            'reference_id' => $upgrade->id, 'reference_type' => ServiceUpgrade::class,
            'description' => 'Upgrade', 'quantity' => 1, 'price' => 27.00,
        ]);
        $invoice->transactions()->create(['amount' => 5, 'status' => InvoiceTransactionStatus::Succeeded]);

        foreach (['2026-10-12 10:00:00', '2026-10-13 10:00:00', '2026-10-14 10:00:00', '2026-10-15 10:00:00'] as $day) {
            Carbon::setTestNow($day);
            $this->artisan('app:cron-job')->assertExitCode(0);
        }

        $this->assertSame('27.00', (string) $invoice->items()->sole()->price, 'kept at its price (setup check)');
        $this->assertNotSame([], $this->kept($invoice->id), 'told at least once (setup check)');
        $this->assertLessThanOrEqual(1, count($this->kept($invoice->id)),
            'one waiting upgrade on a part-paid invoice: a row a day in its activity (' . count($this->kept($invoice->id)) . ' rows in 4 days)');
    }

    /**
     * ADV-KL-2. Dedup is against the last «kept» row of the line only, not against what happened to the line since. The
     * line was kept (10 → 12), staff then set it to 12 and back to 10; the change tried again is kept again, but the
     * activity says nothing: the last kept row (older than both edits) has the same old/new.
     */
    public function test_adv_kl_2_a_line_kept_again_after_it_was_edited_in_between_is_told_again(): void
    {
        $line = $this->line();
        $line->invoice->transactions()->create(['amount' => 5, 'status' => InvoiceTransactionStatus::Succeeded]);

        ChangeOpenLine::handle($line, fn (InvoiceItem $locked) => $locked->price = 12);
        $this->assertCount(1, $this->kept($line->invoice_id));

        // Staff edit the line by hand: to 12, then back to 10 (each an audited «updated» of the item).
        Carbon::setTestNow('2026-10-13 10:00:00');
        $line->fresh()->forceFill(['price' => 12])->save();
        Carbon::setTestNow('2026-10-14 10:00:00');
        $line->fresh()->forceFill(['price' => 10])->save();

        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->assertFalse(ChangeOpenLine::handle($line->fresh(), fn (InvoiceItem $locked) => $locked->price = 12));

        $this->assertCount(2, $this->kept($line->invoice_id), 'kept again after the edits, but the activity only has the row from before them');
    }

    /**
     * ADV-KL-3. The audit is off in the console unless a command turns it on (config/audit.php 'console' => false;
     * AppServiceProvider: seeding and the importer write no audit rows). A custom event skips that check
     * (owen-it readyForAuditing returns true for isCustomEvent before isEventAuditable): a command that runs with the
     * audit off (tinker, a one-off fix-up, the importer, a seeder) still writes «line_kept_at_price» rows, while the
     * line's own «updated» audits are not written there.
     */
    public function test_adv_kl_3_no_kept_row_is_written_in_the_console_with_the_audit_off(): void
    {
        $line = $this->line();
        $line->invoice->transactions()->create(['amount' => 5, 'status' => InvoiceTransactionStatus::Succeeded]);
        Config::set('audit.console', false);

        ChangeOpenLine::handle($line, fn (InvoiceItem $locked) => $locked->price = 12);

        $this->assertSame([], $this->kept($line->invoice_id), 'audit off in the console, yet a kept row was written');
    }
}
