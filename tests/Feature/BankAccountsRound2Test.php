<?php

namespace Tests\Feature;

use App\Models\BankMovement;
use App\Services\BankAlerts\AlertReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use MyCS\BankAlerts\Tests\Fixtures;
use Tests\TestCase;

/**
 * Adversarial review of #491, round 2 (the recheck of «Our accounts», both ways). Each test is a finding; it fails on the
 * PR as it stands (aa9093d4). The mailbox is BankAccountsAdversarialTest's AccountsAlertMailbox.
 */
class BankAccountsRound2Test extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = ['folders' => ['INBOX', 'Checked'], 'authserv_id' => 'mx.example.test', 'arc_sealer' => 'example.test', 'ignore' => ['0000000000'],
        'accounts' => ['eurobank' => ['0000'], 'piraeus' => ['ΟΨΕΩΣ ΕΤΑΙΡΙΩΝ Κ EXAMPLE']]];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-24 12:00:00');
        class_exists(BankAccountsAdversarialTest::class); // declares AccountsAlertMailbox
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settings(array $eurobank): array
    {
        $settings = self::SETTINGS;
        $settings['accounts']['eurobank'] = $eurobank;

        return $settings;
    }

    private function emptyMailbox(): AccountsAlertMailbox
    {
        $mailbox = new AccountsAlertMailbox(['INBOX' => ['validity' => 1, 'messages' => []], 'Checked' => ['validity' => 1, 'messages' => []]]);
        (new AlertReader($mailbox, self::SETTINGS))->run();

        return $mailbox;
    }

    /**
     * R2-491-1. The two directions are not symmetric for a movement with no Message-ID: taking its account out flips it
     * to «not one of our accounts» (its key, «authentic|raw:…», left as it was), but setting the account back skips it
     * (whereNotNull('message_id'), the round-1 fix of ADV-491-1). An account taken out by mistake and set back loses
     * those movements for ever: the balance stays wrong and nothing in the admin can bring them back. Its key was never
     * rewritten, so making it authentic again is safe (the same mail read again finds «authentic|raw:…»).
     */
    public function test_an_account_taken_out_and_set_back_counts_its_movements_without_message_id_again(): void
    {
        $mailbox = $this->emptyMailbox();
        $ours = $this->settings(['0000', '9999']);
        $mailbox->add('INBOX', (string) preg_replace('/^Message-ID: [^\r\n]*\r\n/mi', '',
            Fixtures::eurobank('πιστώθηκε', '20,00', 'B PAYMENT 32000', '520,00', account: '9999')));
        (new AlertReader($mailbox, $ours))->run();
        $this->assertTrue(BankMovement::query()->sole()->authentic);
        $this->assertSame('520.00', BankMovement::balances()['eurobank']['balance']);

        (new AlertReader($mailbox, self::SETTINGS))->run(); // taken out by mistake
        (new AlertReader($mailbox, $ours))->run();          // and set back

        $this->assertTrue(BankMovement::query()->sole()->authentic, 'its account is ours again');
        $this->assertSame('520.00', BankMovement::balances()['eurobank']['balance'] ?? null);
    }

    /**
     * R2-491-2. A copy of the bank's mail our server does not vouch for (a forged one, or a copy whose authentication
     * broke on the way), with the same Message-ID, is stored apart: «unverified|mid:X», as adversarial #450 wants. Take
     * the account out and recount() of the genuine movement finds its new key taken by that copy and returns before
     * anything: the movement stays authentic, its balance in the bank's total and its credit pending for the matching,
     * every run, though the account is no longer ours. On a collision taking the account out can still flip it
     * (keeping its key: the mail read again finds the copy's key and is no second movement).
     */
    public function test_an_account_taken_out_stops_counting_even_when_an_unvouched_copy_of_its_mail_exists(): void
    {
        $mailbox = $this->emptyMailbox();
        $ours = $this->settings(['0000', '9999']);
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '50.000,00', 'SOMEONE ELSE', '90.000,00', account: '9999'));
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '50.000,00', 'SOMEONE ELSE', '90.000,00',
            auth: Fixtures::forwardedByUs('eurobank.gr', 'fail'), account: '9999'));
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '10,00', 'A', '100,00'));
        (new AlertReader($mailbox, $ours))->run();
        $this->assertSame(1, BankMovement::query()->where('authentic', true)->where('account', '9999')->count());
        $this->assertSame(2, BankMovement::query()->where('account', '9999')->count(), 'the unvouched copy stored apart');

        (new AlertReader($mailbox, self::SETTINGS))->run(); // 9999 taken out

        $this->assertSame(0, BankMovement::query()->where('authentic', true)->where('account', '9999')->count(), 'not one of our accounts');
        $this->assertSame(0, BankMovement::query()->where('status', BankMovement::PENDING)->where('account', '9999')->count(), 'not for the matching');
        $this->assertSame('100.00', BankMovement::balances()['eurobank']['balance']);
    }
}
