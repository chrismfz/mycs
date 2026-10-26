<?php

namespace Tests\Feature;

use App\Console\Commands\ReadBankAlerts;
use App\Jobs\SendChatNotification;
use App\Models\BankMovement;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Models\User;
use App\Services\BankAlerts\PaymentMatcher;
use App\Services\Invoice\RecordPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial review of #468 (PaymentMatcher::since(), strictly after): ways the matcher still records a credit read
 * while it was not watching, or never matches one read after it was turned on.
 */
class BankPaymentMatchingSinceAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendChatNotification::class]);
        $this->gateway = Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
    }

    private function invoice(string $number, string $price, ?int $clientId = null): Invoice
    {
        $invoice = Invoice::factory()->create(['client_id' => $clientId ?? User::factory()->create()->client()->id,
            'status' => 'unpaid', 'currency_code' => 'EUR', 'number' => $number, 'due_at' => now()->addDays(7)]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);
        DB::table('invoices')->where('id', $invoice->id)->update(['number' => $number, 'status' => 'unpaid']);

        return $invoice->fresh();
    }

    /** A credit as AlertReader::store() makes it: created_at = now() (second precision in the column). */
    private function credit(string $reason, string $amount, ?\DateTimeInterface $executedAt = null): BankMovement
    {
        return BankMovement::query()->create(['bank' => 'eurobank', 'direction' => 'credit', 'amount' => $amount, 'balance' => '1000.00',
            'reason' => $reason, 'executed_at' => $executedAt ?? now()->subHour(), 'message_key' => uniqid('', true), 'authentic' => true, 'status' => 'pending']);
    }

    private function received(int $invoiceId): string
    {
        return number_format((float) InvoiceTransaction::query()->where('invoice_id', $invoiceId)->sum('amount'), 2, '.', '');
    }

    /** @param array<string, mixed> $settings */
    private function alerts(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value, 'encrypted' => $key === 'alerts_password']);
        }
    }

    /**
     * S-1 (medium). Reading the alerts turned off (Bank transfer → alerts_enabled off, or host/username cleared) with the
     * knob left on: bank:alerts returns before ReadBankAlerts::match(), so PaymentMatcher::stop() is never called and
     * match_since keeps its old value. While nothing reads the mailbox staff record the transfers by hand (the only way
     * they get recorded). Reading turned on again, the backlog of mails that came meanwhile is read as new pending credits,
     * all created after the old since: the first run matches them, and records again what staff recorded by hand (here on
     * the customer's other invoice, as R2-2 of #458). Turning the knob off and on resets since; turning reading off and on
     * does not, though for the matcher it is the same gap.
     */
    public function test_reading_turned_off_and_on_again_does_not_match_the_backlog_staff_recorded_by_hand(): void
    {
        $this->freezeTime();
        $this->alerts(['alerts_enabled' => true, 'alerts_host' => 'imap.example.test', 'alerts_username' => 'alerts', 'alerts_password' => 'x',
            'alerts_auto_match' => true]);
        $this->assertSame(['matched' => 0, 'left' => 0], ReadBankAlerts::match(['auto_match' => true, 'match_days' => 7]), 'the knob on: since set');

        // Reading off for three days, the knob left on.
        $this->alerts(['alerts_enabled' => false]);
        $this->travel(1)->minutes();
        $this->artisan('bank:alerts')->expectsOutputToContain('off')->assertExitCode(0);
        $this->travel(1)->days();

        // Meanwhile a transfer comes; staff see it in e-banking and record it by hand where it belongs.
        $clientId = User::factory()->create()->client()->id;
        $named = $this->invoice('32999', '100.00', $clientId);
        $meant = $this->invoice('33000', '100.00', $clientId);
        (new RecordPaymentService)->handle($meant->id, $this->gateway, '100.00', enteredByStaff: true, paidAt: now());
        $this->travel(2)->days();

        // Reading on again: the backlog is read now (created_at = now), then matched in the same run.
        $this->alerts(['alerts_enabled' => true]);
        $credit = $this->credit('ΠΛΗΡΩΜΗ 32999', '100.00', now()->subDays(2));
        ReadBankAlerts::match(['auto_match' => true, 'match_days' => 7]);

        $this->assertSame('0.00', $this->received($named->id), 'a credit read after reading was off for days, recorded by hand meanwhile, is recorded again');
        $this->assertNotSame(BankMovement::MATCHED, $credit->fresh()->status);
    }

    /**
     * S-2 (low). The other side of «strictly after»: since is stored at second precision, and a credit read in the same
     * second after it (a bank:alerts run from the CLI next to the scheduler's, which withoutOverlapping does not stop)
     * has created_at == since. It is never matched, on any later run, and gets no note saying why. An id watermark
     * (the last bank_movements.id when the knob was turned on) has neither this gap nor the one #468 closes.
     */
    public function test_a_credit_read_after_since_in_the_same_second_is_matched_by_a_later_run(): void
    {
        PaymentMatcher::stop();
        $this->travelTo(now()->startOfSecond()->addMicroseconds(200000));
        $this->assertSame(['matched' => 0, 'left' => 0], PaymentMatcher::run(), 'the knob\'s first run, nothing read');

        $this->travelTo(now()->startOfSecond()->addMicroseconds(700000)); // the same second, after since
        $invoice = $this->invoice('33001', '42.00');
        $credit = $this->credit('ΠΛΗΡΩΜΗ 33001', '42.00');
        $this->assertSame(PaymentMatcher::since()->toDateTimeString(), $credit->fresh()->created_at->toDateTimeString(), 'read in since\'s second, after it');

        $this->travel(3)->minutes();
        $this->assertSame(['matched' => 1, 'left' => 0], PaymentMatcher::run(), 'a credit read after the knob was turned on is never matched');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }
}
