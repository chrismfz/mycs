<?php

namespace Tests\Feature;

use App\Models\BankMovement;
use App\Services\BankAlerts\AlertMailbox;
use App\Services\BankAlerts\AlertReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MyCS\BankAlerts\Tests\Fixtures;
use RuntimeException;
use Tests\TestCase;

/** An in-memory mailbox for the adversarial tests (its own, so the file never depends on another test file loading). */
class AdversarialAlertMailbox implements AlertMailbox
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

/**
 * Adversarial review of #450 (bank alerts): state an attacker can change from outside, by sending mail. All data
 * synthetic: «our» account ends in 0000, the attacker's own in 9999.
 */
class BankAlertsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    /** «accounts»: our accounts' last digits per bank, a setting the fix needs (unknown to the reader today). */
    private const SETTINGS = ['folders' => ['INBOX'], 'authserv_id' => 'mx.example.test', 'arc_sealer' => 'example.test', 'ignore' => [],
        'accounts' => ['eurobank' => ['0000']]];

    private function reader(AdversarialAlertMailbox $mailbox): AlertReader
    {
        return new AlertReader($mailbox, self::SETTINGS);
    }

    /** An empty INBOX read once: from now on, what arrives is new (not history). */
    private function started(): AdversarialAlertMailbox
    {
        $mailbox = new AdversarialAlertMailbox(['INBOX' => ['validity' => 1, 'messages' => []]]);
        $this->reader($mailbox)->run();

        return $mailbox;
    }

    /** A Eurobank alert as the fixture builds it, for an account with the given last digits. */
    private static function eurobankFor(string $digits, string $amount, string $reason, string $balance, ?string $auth = null): string
    {
        $text = "Αγαπητέ πελάτη,\n\n    Σας ενημερώνουμε ότι πιστώθηκε με {$amount} EUR ο λογαριασμός σας με τελευταία ψηφία *{$digits}.\n"
            . "    Αιτιολογία: {$reason}.\n    Νέο υπόλοιπο: {$balance} EUR.\n\n    Σημείωση:\n\n    Μην απαντάτε σε αυτό το μήνυμα.\n";
        $body = chunk_split(base64_encode((string) iconv('UTF-8', 'Windows-1253', $text)), 76, "\r\n");

        return ($auth ?? Fixtures::forwardedByUs('eurobank.gr'))
            . "Date: Fri, 23 Oct 2026 11:00:00 +0300\r\nFrom: \"eurobank@eurobank.gr\" <eurobank@eurobank.gr>\r\nTo: <someone@attacker.example>\r\n"
            . "Subject: Alert\r\nMIME-Version: 1.0\r\nMessage-ID: <" . md5($text) . "@mailrelay.eurobank.gr>\r\n"
            . "Content-Type: text/plain; charset=\"Cp1253\"\r\nContent-Transfer-Encoding: base64\r\n\r\n{$body}\r\n";
    }

    /**
     * The attacker banks with Eurobank too. They move 5.000 € between their own accounts with the reference of an
     * invoice (theirs, or anyone's), and send the genuine alert they got, unchanged, to the address our alerts arrive
     * at (or to the mailbox the sieve redirects from). It is the bank's mail, DKIM intact: our server says DMARC pass,
     * so it is authentic and pending — the credit the next PR marks the invoice paid with, and its balance becomes
     * «ours» in Bank totals. Nothing checks that the alert is about OUR account.
     */
    public function test_a_genuine_alert_about_someone_elses_account_is_no_payment_of_ours(): void
    {
        $mailbox = $this->started();
        $mailbox->add('INBOX', self::eurobankFor('0000', '20,00', 'ΠΛΗΡΩΜΗ 32000', '1.000,00'));
        $this->reader($mailbox)->run();

        $direct = "Authentication-Results: mx.example.test;\r\n\tdkim=pass header.d=eurobank.gr header.s=s1;\r\n"
            . "\tspf=fail smtp.mailfrom=someone@attacker.example;\r\n\tdmarc=pass (policy=reject) header.from=eurobank.gr\r\n";
        $mailbox->add('INBOX', self::eurobankFor('9999', '5.000,00', 'ΤΙΜΟΛΟΓΙΟ 32999', '250.000,00', $direct));
        $this->reader($mailbox)->run();

        $theirs = BankMovement::query()->where('reason', 'ΤΙΜΟΛΟΓΙΟ 32999')->first();
        $this->assertNotNull($theirs, 'stored, so staff can see it');
        $this->assertFalse($theirs->authentic && $theirs->status === BankMovement::PENDING,
            "another account's credit is an authentic pending payment of ours ({$theirs->authenticity})");
        $this->assertSame('1000.00', BankMovement::balances()['eurobank']['balance'] ?? null, "Bank totals show the attacker's balance as ours");
    }

    /**
     * One mail is one movement by its Message-ID, whoever sent it: the first row wins. An attacker who knows (or
     * guesses: a relay's counter, a timestamp) the Message-ID of an alert to come sends a forged one with it first.
     * It is stored unauthentic; the bank's own alert is then «already read» and dropped: the payment never reaches
     * staff or the matching as authentic.
     */
    public function test_a_forged_mail_with_the_message_id_of_a_real_alert_does_not_suppress_it(): void
    {
        $mailbox = $this->started();
        $real = Fixtures::eurobank('πιστώθηκε', '120,00', 'ΤΙΜΟΛΟΓΙΟ 32000', '1.120,00');
        $squat = Fixtures::eurobank('πιστώθηκε', '120,00', 'ΤΙΜΟΛΟΓΙΟ 32000', '1.120,00', ''); // same Message-ID, no vouching
        $mailbox->add('INBOX', $squat);
        $this->reader($mailbox)->run();
        $mailbox->add('INBOX', $real);
        $this->reader($mailbox)->run();

        $this->assertTrue(BankMovement::query()->where('authentic', true)->exists(),
            "the bank's real alert was dropped as a duplicate of the attacker's unauthentic one");
    }

    /**
     * Anyone can send a mail From eurobank.gr; it is stored (unauthentic). One whose row cannot be written (on
     * MariaDB: a 16-digit amount in decimal(17,2), «Out of range») throws out of the folder's loop before its cursor
     * is saved: every run reads the same batch again and stops at the same mail, so no alert after it is ever read.
     * The insert error is simulated here (SQLite does not check a decimal's precision).
     */
    public function test_one_mail_that_cannot_be_stored_does_not_stop_the_reading_for_good(): void
    {
        BankMovement::creating(function (BankMovement $movement) {
            if (strlen(explode('.', (string) $movement->getAttributes()['amount'])[0]) > 15) {
                throw new RuntimeException("SQLSTATE[22003]: Numeric value out of range: 1264 Out of range value for column 'amount'");
            }
        });
        $mailbox = $this->started();
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '1.234.567.890.123.456,00', 'POISON', '1,00', ''));
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '120,00', 'ΤΙΜΟΛΟΓΙΟ 32000', '1.120,00'));

        $this->reader($mailbox)->run();
        $this->reader($mailbox)->run();
        $this->reader($mailbox)->run();

        $this->assertTrue(BankMovement::query()->where('reason', 'ΤΙΜΟΛΟΓΙΟ 32000')->exists(), 'the real alert behind the poisoned mail is never read');
    }
}
