<?php

namespace Tests\Feature;

use App\Admin\Widgets\BankTotals;
use App\Admin\Widgets\PaymentsTracking;
use App\Admin\Widgets\Renewals\DomainRenewals;
use App\Admin\Widgets\Renewals\ServiceRenewals;
use App\Models\BankMovement;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Services\BankAlerts\AlertMailbox;
use App\Services\BankAlerts\AlertReader;
use App\Services\Money\GatewayBalances;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MyCS\BankAlerts\Tests\Fixtures;
use MyCS\Extensions\Gateways\BankTransfer\BankTransfer;
use Tests\TestCase;

/** A mailbox in memory: folders of [uid => raw], their UIDVALIDITY; it records that nothing was ever changed in it. */
class FakeAlertMailbox implements AlertMailbox
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
 * Payments tracking (owner 2026-10-24): the banks' alert emails read without touching the mailbox, their credits for
 * staff, the balances for the admins (with Stripe and PayPal).
 */
class BankAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = ['folders' => ['INBOX', 'Checked'], 'authserv_id' => 'mx.example.test', 'arc_sealer' => 'example.test', 'ignore' => ['0000000000'],
        'accounts' => ['eurobank' => ['0000'], 'piraeus' => ['ΟΨΕΩΣ ΕΤΑΙΡΙΩΝ Κ EXAMPLE']]];

    private function reader(FakeAlertMailbox $mailbox): AlertReader
    {
        return new AlertReader($mailbox, self::SETTINGS);
    }

    private function credit(string $amount, string $reason, string $balance = '1.000,00'): string
    {
        return Fixtures::eurobank('πιστώθηκε', $amount, $reason, $balance);
    }

    public function test_what_the_mailbox_held_is_history_and_what_comes_after_is_for_staff(): void
    {
        $mailbox = new FakeAlertMailbox(['INBOX' => ['validity' => 7, 'messages' => [1 => $this->credit('10,00', 'OLD PAYMENT 31000', '900,00')]]]);
        $this->reader($mailbox)->run();
        $mailbox->add('INBOX', $this->credit('20,00', 'NEW PAYMENT 32000', '920,00'));
        $mailbox->add('INBOX', Fixtures::eurobank('χρεώθηκε', '5,00', 'FEE', '915,00'));
        $mailbox->add('INBOX', $this->credit('30,00', 'WLGR 0000000000 261022', '945,00'));

        $summary = $this->reader($mailbox)->run();

        $this->assertSame([3, 3], [$summary['read'], $summary['new']]);
        $this->assertSame(['31000' => 'history', '32000' => 'pending', 'FEE' => 'ignored', '261022' => 'ignored'],
            BankMovement::query()->orderBy('id')->get()->mapWithKeys(fn ($m) => [last(explode(' ', $m->reason)) => $m->status])->all());
        $this->assertSame(['eurobank' => '945.00'], array_map(fn ($b) => $b['balance'], BankMovement::balances()), 'the latest movement, a debit or a credit');
    }

    public function test_a_mail_moved_to_another_folder_is_read_once_and_a_folder_made_anew_reads_nothing_twice(): void
    {
        $mail = $this->credit('20,00', 'PAYMENT 32000');
        $mailbox = new FakeAlertMailbox(['INBOX' => ['validity' => 1, 'messages' => []], 'Checked' => ['validity' => 1, 'messages' => []]]);
        $this->reader($mailbox)->run();
        $mailbox->add('INBOX', $mail);
        $this->reader($mailbox)->run();
        $mailbox->add('Checked', $mail); // WHMCS's script moves it
        $mailbox->folders['INBOX'] = ['validity' => 2, 'messages' => [1 => $mail]];

        $this->reader($mailbox)->run();

        $this->assertSame(1, BankMovement::query()->count());
        $this->assertSame('pending', BankMovement::query()->value('status'));
    }

    public function test_the_reading_resumes_after_the_last_mail_read(): void
    {
        $mailbox = new FakeAlertMailbox(['INBOX' => ['validity' => 1, 'messages' => []]]);
        $this->reader($mailbox)->run();
        for ($i = 0; $i < AlertReader::BATCH + 5; $i++) {
            $mailbox->add('INBOX', $this->credit('1,00', "PAYMENT {$i}"));
        }

        $this->assertSame(AlertReader::BATCH, $this->reader($mailbox)->run()['read']);
        $this->assertSame(5, $this->reader($mailbox)->run()['read']);
        $this->assertSame(0, $this->reader($mailbox)->run()['read']);
        $this->assertSame(AlertReader::BATCH + 5, BankMovement::query()->count());
        $this->assertNull(config('settings.bank_alerts.cursor.INBOX'), 'the cursor is no global setting');
        $this->assertTrue(Setting::query()->where('key', 'bank_alerts.cursor.INBOX')->where('settingable_type', BankMovement::class)->exists());
    }

    public function test_an_alert_our_server_does_not_vouch_for_never_counts_for_the_balance(): void
    {
        $mailbox = new FakeAlertMailbox(['INBOX' => ['validity' => 1, 'messages' => []]]);
        $this->reader($mailbox)->run();
        $mailbox->add('INBOX', $this->credit('10,00', 'REAL', '500,00'));
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '9.999,00', 'FORGED', '99.999,00', "Authentication-Results: mx.example.test; dmarc=fail header.from=eurobank.gr\r\n"));

        $this->reader($mailbox)->run();

        $forged = BankMovement::query()->where('reason', 'FORGED')->sole();
        $this->assertFalse($forged->authentic);
        $this->assertSame('500.00', BankMovement::balances()['eurobank']['balance']);
    }

    public function test_an_unvouched_credit_or_one_about_another_account_is_unverified_and_the_banks_other_mails_are_passed(): void
    {
        $mailbox = new FakeAlertMailbox(['INBOX' => ['validity' => 1, 'messages' => []]]);
        $this->reader($mailbox)->run();
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '10,00', 'UNVOUCHED', '1,00', ''));
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '10,00', 'THEIRS', '1,00', account: '9999'));
        $mailbox->add('INBOX', Fixtures::forwardedByUs('eurobank.gr') . "From: eurobank@eurobank.gr\r\nSubject: News\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nΝέα προϊόντα για εσάς.\r\n");
        $mailbox->add('INBOX', $this->credit('20,00', 'OURS'));

        $summary = $this->reader($mailbox)->run();

        $this->assertSame([4, 3, []], [$summary['read'], $summary['new'], $summary['errors']], 'the newsletter read and passed, no error');
        $this->assertSame(['UNVOUCHED' => 'unverified', 'THEIRS' => 'unverified', 'OURS' => 'pending'],
            BankMovement::query()->orderBy('id')->pluck('status', 'reason')->all());
        $this->assertSame('not_our_account', BankMovement::query()->where('reason', 'THEIRS')->value('authenticity'));
    }

    /** Two accounts at one bank (Piraeus, owner 2026-10-28): its balance is both accounts', each by its latest alert. */
    public function test_a_banks_balance_is_the_sum_of_its_accounts(): void
    {
        $settings = self::SETTINGS;
        $settings['accounts']['eurobank'] = ['0000', '9999'];
        $mailbox = new FakeAlertMailbox(['INBOX' => ['validity' => 1, 'messages' => []]]);
        (new AlertReader($mailbox, $settings))->run();
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '10,00', 'A OLD', '100,00'));
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '10,00', 'B', '2.000,00', account: '9999'));
        $mailbox->add('INBOX', Fixtures::eurobank('χρεώθηκε', '5,00', 'A NEW', '95,00'));
        (new AlertReader($mailbox, $settings))->run();

        $balance = BankMovement::balances()['eurobank'];
        $this->assertSame('2095.00', $balance['balance']);
        $this->assertEqualsCanonicalizing(['0000' => '95.00', '9999' => '2000.00'], $balance['accounts']);
    }

    /**
     * An account set as ours after its alerts were read (the second Piraeus account, owner 2026-10-28): the next run counts
     * them, as if read now; what staff did stays; the same mail read again is no second movement.
     */
    public function test_an_account_set_as_ours_later_counts_its_movements_from_the_next_run(): void
    {
        $mailbox = new FakeAlertMailbox(['INBOX' => ['validity' => 1, 'messages' => [1 => Fixtures::eurobank('πιστώθηκε', '1,00', 'B HISTORY', '500,00', account: '9999')]]]);
        $this->reader($mailbox)->run();
        $credit = Fixtures::eurobank('πιστώθηκε', '20,00', 'B PAYMENT 32000', '520,00', account: '9999');
        $mailbox->add('INBOX', $credit);
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '30,00', 'B WLGR 0000000000', '550,00', account: '9999'));
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '40,00', 'B SEEN', '590,00', account: '9999'));
        $mailbox->add('INBOX', Fixtures::eurobank('πιστώθηκε', '50,00', 'B UNVOUCHED', '640,00', '', account: '9999'));
        $this->reader($mailbox)->run();
        BankMovement::query()->where('reason', 'B SEEN')->update(['status' => BankMovement::ACKNOWLEDGED]);
        $this->assertArrayNotHasKey('eurobank', BankMovement::balances());

        $settings = self::SETTINGS;
        $settings['accounts']['eurobank'] = ['0000', '9999'];
        (new AlertReader($mailbox, $settings))->run();

        $this->assertSame(['B HISTORY' => 'history', 'B PAYMENT 32000' => 'pending', 'B WLGR 0000000000' => 'ignored', 'B SEEN' => 'acknowledged',
            'B UNVOUCHED' => 'unverified'], BankMovement::query()->orderBy('id')->pluck('status', 'reason')->all());
        $this->assertFalse(BankMovement::query()->where('reason', 'B UNVOUCHED')->value('authentic'), 'not vouched for: still not');
        $this->assertSame('590.00', BankMovement::balances()['eurobank']['balance'], 'the latest vouched-for one');

        (new AlertReader($mailbox, $settings))->store($credit, 'Checked', false);
        $this->assertSame(1, BankMovement::query()->where('reason', 'B PAYMENT 32000')->count(), 'read again: the same movement');
    }

    public function test_a_mail_that_keeps_failing_for_another_cause_is_passed_after_its_attempts(): void
    {
        BankMovement::creating(function (BankMovement $movement) {
            if ($movement->reason === 'STUCK') {
                throw new \RuntimeException('SQLSTATE[HY000]: General error: something the mail cannot fix');
            }
        });
        $mailbox = new FakeAlertMailbox(['INBOX' => ['validity' => 1, 'messages' => []]]);
        $this->reader($mailbox)->run();
        $mailbox->add('INBOX', $this->credit('10,00', 'STUCK'));
        $mailbox->add('INBOX', $this->credit('11,00', 'AFTER'));

        foreach (range(1, AlertReader::ATTEMPTS - 1) as $run) {
            $this->reader($mailbox)->run();
            $this->assertFalse(BankMovement::query()->where('reason', 'AFTER')->exists(), "run {$run}: the folder waits on the mail, to try it again");
        }
        $summary = $this->reader($mailbox)->run();

        $this->assertTrue(BankMovement::query()->where('reason', 'AFTER')->exists(), 'passed after its attempts, never stuck for good');
        $this->assertNotEmpty($summary['errors']);
    }

    public function test_our_accounts_as_the_gateway_takes_them(): void
    {
        $this->assertSame(['eurobank' => ['2261'], 'piraeus' => ['ΟΨΕΩΣ ΕΤΑΙΡΙΩΝ Κ EXAMPLE', 'SECOND']],
            BankTransfer::accounts("Eurobank: 2261\r\npiraeus : ΟΨΕΩΣ ΕΤΑΙΡΙΩΝ Κ EXAMPLE\n\nnot a line\npiraeus: SECOND"));
    }

    public function test_the_command_does_nothing_until_it_is_set_up(): void
    {
        $this->artisan('bank:alerts')->expectsOutputToContain('off')->assertExitCode(0);
    }

    private function staff(array $permissions): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        $this->actingAs($staff, 'staff');

        return $staff;
    }

    private function movement(array $attributes = []): BankMovement
    {
        return BankMovement::query()->create(array_merge(['bank' => 'piraeus', 'direction' => 'credit', 'amount' => '150.00', 'balance' => '2903.01',
            'reason' => 'ΙΩΑΝΝΗΣ ΔΟΚΙΜΑΣΤΙΚΟΣ 32999', 'executed_at' => now(), 'message_key' => uniqid(), 'authentic' => true, 'status' => 'pending'], $attributes));
    }

    public function test_staff_see_the_credits_and_tick_one_seen_to(): void
    {
        $credit = $this->movement();
        $debit = $this->movement(['direction' => 'debit', 'reason' => 'FEE', 'status' => 'ignored']);
        $staff = $this->staff(['admin.widgets.payments_tracking', 'admin.bank_payments.update']);

        $this->livewireAs($staff)->test(PaymentsTracking::class)
            ->assertCanSeeTableRecords([$credit])->assertCanNotSeeTableRecords([$debit])
            ->callTableAction('acknowledge', $credit);

        $credit->refresh();
        $this->assertSame([BankMovement::ACKNOWLEDGED, $staff->id], [$credit->status, $credit->acknowledged_by]);

        $this->livewireAs($staff)->test(PaymentsTracking::class)->callTableAction('reopen', $credit);
        $this->assertSame(BankMovement::PENDING, $credit->fresh()->status);
    }

    public function test_staff_who_may_only_look_cannot_tick_and_the_balances_are_not_theirs(): void
    {
        $credit = $this->movement();
        $staff = $this->staff(['admin.widgets.payments_tracking']);

        $this->livewireAs($staff)->test(PaymentsTracking::class)->assertTableActionHidden('acknowledge', $credit);
        $this->assertFalse(BankTotals::canView());
        $this->staff(['admin.widgets.bank_totals']);
        $this->assertTrue(BankTotals::canView());
        $this->assertFalse(PaymentsTracking::canView());
    }

    public function test_the_invoices_that_match_a_credit(): void
    {
        $client = User::factory()->create()->client();
        $named = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'number' => '32999', 'currency_code' => 'EUR']);
        $named->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 150]);
        $sameAmount = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'number' => '32871', 'currency_code' => 'EUR']);
        $sameAmount->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 150]);
        $other = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'number' => '32870', 'currency_code' => 'EUR']);
        $other->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 99]);

        $found = PaymentsTracking::matches($this->movement());

        $this->assertSame([$named->id], $found['named']->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$named->id, $sameAmount->id], $found['amount']->pluck('id')->all());
    }

    public function test_bank_totals_add_the_banks_stripe_and_paypal_in_eur(): void
    {
        $this->movement(['bank' => 'piraeus', 'balance' => '2903.01']);
        $this->movement(['bank' => 'eurobank', 'balance' => '8151.51']);
        $this->movement(['bank' => 'eurobank', 'balance' => '99999.00', 'authentic' => false, 'executed_at' => now()->addMinute()]);
        Http::fake([
            'api.stripe.com/v1/balance' => Http::response(['available' => [['amount' => 32511, 'currency' => 'eur']], 'pending' => [['amount' => 0, 'currency' => 'eur']]]),
            '*/v1/oauth2/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/v1/reporting/balances*' => Http::response(['balances' => [['currency' => 'EUR', 'total_balance' => ['value' => '160.03'], 'available_balance' => ['value' => '148.03']]]]),
        ]);
        foreach (['Stripe' => ['stripe_secret_key' => 'sk_test'], 'PayPal' => ['client_id' => 'id', 'client_secret' => 'secret', 'test_mode' => true]] as $extension => $settings) {
            $gateway = Gateway::query()->create(['name' => $extension, 'extension' => $extension, 'type' => 'gateway', 'enabled' => true]);
            foreach ($settings as $key => $value) {
                $gateway->settings()->create(['key' => $key, 'value' => $value]);
            }
        }

        $this->artisan('gateways:balances')->assertExitCode(0);
        $staff = $this->staff(['admin.widgets.bank_totals']);
        $html = $this->livewireAs($staff)->test(BankTotals::class)->html();

        $this->assertSame([['currency' => 'EUR', 'available' => '148.03', 'pending' => '12.00']], GatewayBalances::stored()['PayPal']['rows']);
        $this->assertStringContainsString('11.527,66 €', $html, '8151.51 + 2903.01 + 325.11 + 148.03; never the unvouched 99999');
        $this->assertStringNotContainsString('99.999', $html);
    }

    public function test_a_gateway_that_cannot_be_read_keeps_what_was_read_with_the_error(): void
    {
        Cache::forever(GatewayBalances::KEY, ['Stripe' => ['label' => 'Stripe', 'rows' => [['currency' => 'EUR', 'available' => '10.00', 'pending' => '0.00']], 'read_at' => now()->subHour()->toIso8601String(), 'error' => null]]);
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'Invalid API Key provided']], 401)]);
        $gateway = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_live_wrong']);

        $this->artisan('gateways:balances')->assertExitCode(1);

        $stored = GatewayBalances::stored()['Stripe'];
        $this->assertSame('10.00', $stored['rows'][0]['available']);
        $this->assertNotNull($stored['error']);
    }

    public function test_the_renewals_go_in_line_below_income_and_payments_tracking(): void
    {
        $this->staff(['*']);
        $this->assertSame(21, ServiceRenewals::getSort(), 'no credits: domain renewals beside income, as before');
        $this->movement();
        $this->assertSame(19, ServiceRenewals::getSort());
        $this->assertLessThan(DomainRenewals::getSort(), ServiceRenewals::getSort());
    }
}
