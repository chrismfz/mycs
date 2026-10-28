<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
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
use Illuminate\Support\Facades\Queue;
use MyCS\BankAlerts\Tests\Fixtures;
use Tests\TestCase;

/** A mailbox in memory, as BankAlertsTest's (its class is not autoloaded from there). */
class AccountsAlertMailbox implements AlertMailbox
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
 * Adversarial review of #491 (a bank's balance over its accounts; an account set as ours later: its movements recheck).
 * Each test is a finding; it fails on the PR as it stands.
 */
class BankAccountsAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = ['folders' => ['INBOX', 'Checked'], 'authserv_id' => 'mx.example.test', 'arc_sealer' => 'example.test', 'ignore' => ['0000000000'],
        'accounts' => ['eurobank' => ['0000'], 'piraeus' => ['ΟΨΕΩΣ ΕΤΑΙΡΙΩΝ Κ EXAMPLE']]];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-24 12:00:00'); // the fixtures' alerts are of 2026-10-23: recent for the matching
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
        (new AlertReader($mailbox, self::SETTINGS))->run(); // first sight: nothing is history from now on

        return $mailbox;
    }

    /**
     * ADV-491-1. A genuine alert with no usable Message-ID (none, or one MessageId::normalise() drops: whitespace, over 250)
     * is keyed by its raw text. The recheck cannot rebuild the authentic key from the raw (it keeps «unverified|raw:…»)
     * but still makes the row authentic and pending; the same mail read again (moved to another folder, a folder made anew)
     * is then keyed «authentic|raw:…» and becomes a second authentic, pending credit of the same payment.
     */
    public function test_a_rechecked_alert_without_message_id_read_again_is_not_a_second_payment(): void
    {
        $mailbox = $this->emptyMailbox();
        $mail = (string) preg_replace('/^Message-ID: [^\r\n]*\r\n/mi', '',
            Fixtures::eurobank('πιστώθηκε', '20,00', 'B PAYMENT 32000', '520,00', account: '9999'));
        $mailbox->add('INBOX', $mail);
        (new AlertReader($mailbox, self::SETTINGS))->run();
        $this->assertSame('not_our_account', BankMovement::query()->sole()->authenticity);

        $settings = $this->settings(['0000', '9999']);
        (new AlertReader($mailbox, $settings))->run();
        $mailbox->add('Checked', $mail); // staff's mail client moves it
        (new AlertReader($mailbox, $settings))->run();

        $this->assertSame(1, BankMovement::query()->where('authentic', true)->where('reason', 'B PAYMENT 32000')->count(),
            'one transfer, one authentic credit');
    }

    /**
     * ADV-491-2. A credit stored as unverified (its account not set as ours yet) was shown to staff, who are told to «check
     * it at the bank»: they may have recorded it by hand, on the invoice it was really for. Once the account is set, the
     * recheck makes it pending and recent, and the matching pays the invoice its reference names with it: the same money
     * recorded twice. PaymentMatcher's own rule (adversarial of #458): a credit left for staff is theirs, never matched later.
     */
    public function test_an_unverified_credit_staff_may_have_recorded_by_hand_is_not_matched_once_its_account_is_set(): void
    {
        Queue::fake([SendChatNotification::class]);
        Gateway::query()->create(['name' => 'Bank transfer', 'extension' => 'BankTransfer', 'type' => 'gateway', 'enabled' => true]);
        Setting::query()->create(['key' => PaymentMatcher::AFTER_KEY, 'settingable_type' => BankMovement::class, 'value' => '0', 'type' => 'string']);
        $client = User::factory()->create()->client();
        $invoice = function (string $number) use ($client): Invoice {
            $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'EUR', 'number' => $number, 'due_at' => now()->addDays(7)]);
            $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => '20.00']);

            return $invoice->fresh();
        };
        $named = $invoice('32000');   // what the customer wrote
        $paidFor = $invoice('32001'); // what the transfer was for: same monthly amount

        $mailbox = $this->emptyMailbox();
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '20,00', 'B PAYMENT 32000', '520,00', account: '9999'));
        (new AlertReader($mailbox, self::SETTINGS))->run();
        $this->assertSame(BankMovement::UNVERIFIED, BankMovement::query()->sole()->status);
        ExtensionHelper::addPayment($paidFor, 'BankTransfer', '20.00'); // staff, after checking at the bank

        (new AlertReader($mailbox, $this->settings(['0000', '9999'])))->run();
        PaymentMatcher::run();

        $this->assertSame(Invoice::STATUS_UNPAID, $named->fresh()->status, 'the 20.00 staff recorded on 32001 is not recorded again on 32000');
        $this->assertNotSame(BankMovement::MATCHED, BankMovement::query()->sole()->status);
    }

    /**
     * ADV-491-3. An account set as ours by mistake (a typo, a customer's account) and then taken out of «Our accounts»: its
     * movements, made authentic by the recheck, stay authentic for ever. Its balance stays in the bank's total and its
     * credits stay pending for the matching; the configuration no longer says it is ours and nothing can undo it.
     */
    public function test_an_account_taken_out_of_our_accounts_no_longer_counts_for_the_balance(): void
    {
        $mailbox = $this->emptyMailbox();
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '10,00', 'A', '100,00'));
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '50.000,00', 'SOMEONE ELSE', '90.000,00', account: '9999'));
        (new AlertReader($mailbox, self::SETTINGS))->run();

        (new AlertReader($mailbox, $this->settings(['0000', '9999'])))->run(); // set by mistake
        (new AlertReader($mailbox, self::SETTINGS))->run();                     // and corrected

        $this->assertSame('100.00', BankMovement::balances()['eurobank']['balance'], 'only the account still set as ours');
    }
}
