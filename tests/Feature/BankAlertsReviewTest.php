<?php

namespace Tests\Feature;

use App\Admin\Widgets\PaymentsTracking;
use App\Models\BankMovement;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BankAlerts\AlertMailbox;
use App\Services\BankAlerts\AlertReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MyCS\BankAlerts\Tests\Fixtures;
use RuntimeException;
use Tests\TestCase;

/** An in-memory alerts mailbox for the review of #450 (its own class: BankAlertsTest's fake is not autoloadable). */
class ReviewAlertMailbox implements AlertMailbox
{
    /** @param  array<string, array{validity: int, messages: array<int, string>}>  $folders */
    public function __construct(public array $folders = []) {}

    public function status(string $folder): array
    {
        $messages = $this->folders[$folder]['messages'] ?? [];

        return ['validity' => $this->folders[$folder]['validity'] ?? 1, 'next' => ($messages === [] ? 0 : max(array_keys($messages))) + 1];
    }

    public function messages(string $folder, int $from, int $limit): iterable
    {
        $messages = $this->folders[$folder]['messages'] ?? [];
        ksort($messages);
        foreach (array_slice(array_filter($messages, fn ($uid) => $uid >= $from, ARRAY_FILTER_USE_KEY), 0, $limit, true) as $uid => $raw) {
            yield [$uid, $raw];
        }
    }

    public function add(string $folder, string $raw): void
    {
        $messages = $this->folders[$folder]['messages'] ?? [];
        $this->folders[$folder]['messages'][($messages === [] ? 0 : max(array_keys($messages))) + 1] = $raw;
    }
}

/** Independent review of #450 (bank alerts, Payments tracking): failing tests for the defects found. */
class BankAlertsReviewTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = ['folders' => ['INBOX'], 'authserv_id' => 'mx.example.test', 'arc_sealer' => 'example.test', 'ignore' => [],
        'accounts' => ['eurobank' => ['0000']]]; // «Our accounts», required since the adversarial review of #450

    private function credit(string $amount, string $reason): string
    {
        return Fixtures::eurobank('πιστώθηκε', $amount, $reason, '1.000,00');
    }

    private function run_(ReviewAlertMailbox $mailbox): array
    {
        return (new AlertReader($mailbox, self::SETTINGS))->run();
    }

    /**
     * A folder that was already being read and gets a new UIDVALIDITY (rebuilt index, restored mailbox, server
     * migration) is not a first sight: a credit that arrived since the last run and was never read must reach staff
     * as pending, not be buried as history. Message-ID already keeps the read ones from being stored twice.
     */
    public function test_a_new_uidvalidity_does_not_turn_unread_credits_into_history(): void
    {
        $old = $this->credit('10,00', 'OLD 31000');
        $mailbox = new ReviewAlertMailbox(['INBOX' => ['validity' => 1, 'messages' => []]]);
        $this->run_($mailbox);
        $mailbox->add('INBOX', $old);
        $this->run_($mailbox);
        $this->assertSame('pending', BankMovement::query()->where('reason', 'OLD 31000')->value('status'));

        // A payment arrives, and before the next run the server renumbers the folder.
        $new = $this->credit('20,00', 'NEW 32000');
        $mailbox->folders['INBOX'] = ['validity' => 2, 'messages' => [1 => $old, 2 => $new]];
        $this->run_($mailbox);

        $this->assertSame(2, BankMovement::query()->count());
        $this->assertSame('pending', BankMovement::query()->where('reason', 'NEW 32000')->value('status'),
            'a credit never read before is no history just because the folder got a new UIDVALIDITY');
    }

    /**
     * One mail the database refuses (on MariaDB: a forged alert's 16-digit amount overflows DECIMAL(17,2), or its date
     * falls outside TIMESTAMP's 1970-2038; anyone can send a mail From eurobank.gr) must not stop the folder for good:
     * the cursor is saved only after the whole batch, so every run re-reads up to the same mail and fails there again.
     */
    public function test_one_mail_the_database_refuses_does_not_stop_the_folder_for_good(): void
    {
        // The database's refusal, simulated so the test also fails on SQLite (which stores anything).
        BankMovement::creating(function (BankMovement $movement) {
            if ($movement->reason === 'POISON') {
                throw new RuntimeException('SQLSTATE[22003]: Numeric value out of range');
            }
        });
        $mailbox = new ReviewAlertMailbox(['INBOX' => ['validity' => 1, 'messages' => []]]);
        $this->run_($mailbox);
        $mailbox->add('INBOX', $this->credit('10,00', 'BEFORE 1'));
        $mailbox->add('INBOX', $this->credit('11,00', 'POISON'));
        $mailbox->add('INBOX', $this->credit('12,00', 'AFTER 3'));

        foreach (range(1, 3) as $ignored) {
            $this->run_($mailbox);
        }

        $this->assertTrue(BankMovement::query()->where('reason', 'AFTER 3')->exists(),
            'the credit after the refused mail is never read: the folder is stuck on it, run after run');
    }

    /**
     * executed_at is the instant the alert names. Eurobank's comes from the Date header, kept in the header's offset;
     * Eloquent writes the wall-clock time of that offset and reads it back in the app's timezone, so the time moves by
     * the difference (here three hours).
     */
    public function test_a_eurobank_alert_is_dated_at_the_instant_its_date_header_says(): void
    {
        $mailbox = new ReviewAlertMailbox(['INBOX' => ['validity' => 1, 'messages' => []]]);
        $this->run_($mailbox);
        $mailbox->add('INBOX', $this->credit('10,00', 'PAYMENT 1')); // Date: Fri, 23 Oct 2026 10:27:47 +0300

        $this->run_($mailbox);

        $executed = BankMovement::query()->sole()->executed_at;
        $this->assertTrue($executed->equalTo(Carbon::parse('2026-10-23T10:27:47+03:00')),
            "stored as {$executed->toIso8601String()} (app timezone " . config('app.timezone') . ')');
    }

    private mixed $client = null;

    private function unpaid(string $currency, string $price): Invoice
    {
        $client = $this->client ??= User::factory()->create()->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => $currency]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => $price]);

        return $invoice;
    }

    /**
     * «Find matching invoices» loads up to 1000 unpaid invoices with items and transactions, but Invoice::total also
     * reads adjustmentNotes, which are not eager loaded: one query per unpaid invoice (up to ~1000) each time the
     * modal opens.
     */
    public function test_finding_the_matching_invoices_does_not_query_once_per_unpaid_invoice(): void
    {
        foreach (range(1, 30) as $i) {
            $this->unpaid('EUR', '99');
        }
        $movement = BankMovement::query()->create(['bank' => 'piraeus', 'direction' => 'credit', 'amount' => '150.00',
            'reason' => 'NO NUMBER HERE', 'executed_at' => now(), 'message_key' => 'k1', 'authentic' => true, 'status' => 'pending']);

        DB::enableQueryLog();
        PaymentsTracking::matches($movement);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(15, $queries, "{$queries} queries for 30 unpaid invoices");
    }

    /** The credits are in EUR: an unpaid invoice in another currency with the same number left is no match. */
    public function test_an_invoice_in_another_currency_is_no_match_for_a_eur_credit(): void
    {
        $usd = $this->unpaid('USD', '150');
        $eur = $this->unpaid('EUR', '150');
        $movement = BankMovement::query()->create(['bank' => 'eurobank', 'direction' => 'credit', 'amount' => '150.00',
            'reason' => 'PAYMENT', 'executed_at' => now(), 'message_key' => 'k2', 'authentic' => true, 'status' => 'pending']);

        $this->assertSame([$eur->id], PaymentsTracking::matches($movement)['amount']->pluck('id')->all(),
            "the USD invoice #{$usd->id} is offered for a EUR bank credit");
    }
}
