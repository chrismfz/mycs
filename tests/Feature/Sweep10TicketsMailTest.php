<?php

namespace Tests\Feature;

use App\Jobs\SendChatNotification;
use App\Models\BankMovement;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\BankAlerts\AlertMailbox;
use App\Services\BankAlerts\AlertReader;
use App\Services\BankAlerts\PaymentMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use MyCS\BankAlerts\Tests\Fixtures;
use Tests\TestCase;

/**
 * Sweep 10, tickets-mail (adversarial verifier).
 *
 * S10-TICKETS-MAIL-1. A genuine bank alert is tied to us only by the account text in its body. Whom the bank wrote to
 * (To) is never read, so the bank's own mail to another of its customers, re-sent to our alerts address unchanged (its
 * DKIM still passes, our server vouches for it), counts as ours whenever its «Λογαριασμός» text equals ours, and the
 * matching pays the invoice it names.
 */
class Sweep10TicketsMailTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = ['folders' => ['INBOX'], 'authserv_id' => 'mx.example.test', 'arc_sealer' => 'example.test', 'ignore' => [],
        'accounts' => ['eurobank' => ['0000'], 'piraeus' => ['ΟΨΕΩΣ ΕΤΑΙΡΙΩΝ Κ EXAMPLE']]];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow('2026-10-23 12:00:00'); // the fixture's alert is of 2026-10-22: recent for the matching
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_bank_alert_addressed_to_someone_else_with_our_account_text_is_not_a_payment_of_ours(): void
    {
        Queue::fake([SendChatNotification::class]);
        Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        Setting::query()->create(['key' => PaymentMatcher::AFTER_KEY, 'settingable_type' => BankMovement::class, 'value' => '0', 'type' => 'string']);
        $invoice = Invoice::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => 'unpaid',
            'currency_code' => 'EUR', 'number' => '2026-0412', 'due_at' => now()->addDays(7)]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '120.00']);

        // The bank's own alert to another customer of it (a transfer between that customer's two accounts, the reason
        // naming our invoice), re-sent to our alerts address as it is: only its To differs from an alert of ours.
        $raw = Fixtures::piraeus('120.00 EUR (ΠΙ)', 'ΜΕΤΑΦΟΡΑ', '2026-0412', '', '120.00 EUR');
        $this->assertStringContainsString('To: <alerts@example.test>', $raw);
        $raw = str_replace('To: <alerts@example.test>', 'To: <someone-else@example.org>', $raw);

        $reader = new AlertReader($this->createStub(AlertMailbox::class), self::SETTINGS);
        $this->assertTrue($reader->store($raw, 'INBOX', false));
        PaymentMatcher::run();

        $movement = BankMovement::query()->sole();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status, 'an alert the bank sent to someone else paid our invoice');
        $this->assertFalse((bool) $movement->authentic, 'an alert the bank sent to someone else counts as ours');
    }
}
