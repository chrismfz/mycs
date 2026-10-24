<?php

namespace Tests\Feature;

use App\Admin\Widgets\PaymentsTracking;
use App\Models\BankMovement;
use App\Models\Role;
use App\Models\Staff;
use App\Services\BankAlerts\AlertMailbox;
use App\Services\BankAlerts\AlertReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\BankAlerts\Tests\Fixtures;
use RuntimeException;
use Tests\TestCase;

/** An in-memory mailbox (its own, so this file never depends on another test file loading first). */
class Round2AlertMailbox implements AlertMailbox
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

/** Adversarial review of #450, round 2: the fixes' own edges. Synthetic data; our Eurobank account ends in 0000. */
class BankAlertsAdversarialRound2Test extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = ['folders' => ['INBOX'], 'authserv_id' => 'mx.example.test', 'arc_sealer' => 'example.test', 'ignore' => [],
        'accounts' => ['eurobank' => ['0000'], 'piraeus' => ['ΟΨΕΩΣ ΕΤΑΙΡΙΩΝ Κ EXAMPLE']]];

    /**
     * The per-mail catch passes ANY error and moves the cursor past the mail. A transient one (a deadlock, «MySQL server
     * has gone away», a lock wait timeout) on the bank's own alert loses the payment for good: the mail stays in the
     * mailbox, but it is never read again and never reaches staff or the matching. Only an error that will recur (the
     * mail itself) should be passed; a transient one retried.
     */
    public function test_a_transient_error_on_a_real_alert_does_not_lose_it_for_good(): void
    {
        $mailbox = new Round2AlertMailbox(['INBOX' => ['validity' => 1, 'messages' => []]]);
        (new AlertReader($mailbox, self::SETTINGS))->run();
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '120,00', 'ΤΙΜΟΛΟΓΙΟ 32000', '1.120,00'));

        $failures = 1;
        BankMovement::creating(function () use (&$failures) {
            if ($failures-- > 0) {
                throw new RuntimeException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
            }
        });
        (new AlertReader($mailbox, self::SETTINGS))->run(); // the deadlock: said and passed
        (new AlertReader($mailbox, self::SETTINGS))->run(); // the database is fine again

        $this->assertTrue(BankMovement::query()->where('reason', 'ΤΙΜΟΛΟΓΙΟ 32000')->exists(),
            'the bank\'s alert was skipped on a transient error and never read again');
    }

    /**
     * «pending» is to mean authentic and ours (the next PR matches on it). An unverified credit (a forged mail, or a
     * genuine one about another account) can be ticked «seen to»; «Back to pending» then makes it pending, still
     * unauthentic. Reopening should give back what it was (unverified), never pending.
     */
    public function test_reopening_an_unverified_credit_never_makes_it_pending(): void
    {
        $forged = BankMovement::query()->create(['bank' => 'eurobank', 'direction' => 'credit', 'amount' => '5000.00', 'balance' => null,
            'reason' => 'ΤΙΜΟΛΟΓΙΟ 32999', 'executed_at' => now(), 'message_key' => uniqid(), 'authentic' => false,
            'authenticity' => 'no_bank_dkim_and_dmarc', 'status' => BankMovement::UNVERIFIED]);
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(),
            'permissions' => ['admin.widgets.payments_tracking', 'admin.bank_payments.update']])->id]);
        $this->actingAs($staff, 'staff');

        $this->livewireAs($staff)->test(PaymentsTracking::class)->callTableAction('acknowledge', $forged);
        $this->livewireAs($staff)->test(PaymentsTracking::class)->callTableAction('reopen', $forged->fresh());

        $forged->refresh();
        $this->assertFalse($forged->status === BankMovement::PENDING && !$forged->authentic,
            'an unauthentic credit laundered into «pending» through Seen to → Back to pending');
    }
}
