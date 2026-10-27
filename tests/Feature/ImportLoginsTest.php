<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\User;
use App\Services\Import\WhmcsSecondFactor;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * WHMCS logins come over as themselves (docs/accounts.md PR 4, security audit AUTHN-11): their own ids, emails and
 * passwords, the logins of no account too, each account's owner and other users with their permissions, and their
 * authenticator secret, so their app keeps working.
 */
class ImportLoginsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DP';

    private PDO $whmcs;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $this->whmcs->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT,
            second_factor TEXT, second_factor_config TEXT, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT,
            tax_id TEXT, phonenumber TEXT, address1 TEXT, address2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT,
            taxexempt INT, separateinvoices INT, disableautocc INT, credit REAL, currency INT, created_at TEXT, updated_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT,
            last_login TEXT, created_at TEXT, updated_at TEXT)');

        $hash = Hash::make('Whmcs-Pass-1');
        $user = $this->whmcs->prepare('INSERT INTO tblusers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $user->execute([7, 'Ada', 'Owner', 'ada@login.test', $hash, 'totp', 'ENC-7', '2025-01-01 00:00:00', '2024-01-01 00:00:00', '2025-01-01 00:00:00']);
        $user->execute([8, 'Bob', 'Helper', 'bob@login.test', $hash, '', null, null, '2024-02-01 00:00:00', '2025-01-01 00:00:00']);
        $user->execute([9, 'Cy', 'Nobody', 'cy@login.test', $hash, 'totp', 'ENC-BROKEN', null, '2024-03-01 00:00:00', '2025-01-01 00:00:00']);
        $this->whmcs->exec("INSERT INTO tblclients VALUES (300, 'Ada', 'Owner', 'Ada Ltd', 'billing@company.test', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GR',
            0, 0, 0, 0, 1, '2024-01-01 00:00:00', '2025-01-01 00:00:00')");
        $this->whmcs->exec("INSERT INTO tblusers_clients VALUES (1, 7, 300, 1, NULL, '2026-10-01 10:00:00', '2024-01-01 00:00:00', NULL),
            (2, 8, 300, 0, 'invoices,tickets,unknownthing', NULL, '2024-05-01 00:00:00', NULL)");
    }

    private function import(?WhmcsSecondFactor $secondFactor): void
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->secondFactor = $secondFactor;
        $this->output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $this->output));
        (new ReflectionMethod($command, 'importUsers'))->invoke($command);
    }

    private function decrypting(): WhmcsSecondFactor
    {
        return new WhmcsSecondFactor(fn (string $encrypted) => $encrypted === 'ENC-7' ? json_encode(['secret' => self::SECRET]) : null);
    }

    public function test_logins_come_over_as_themselves(): void
    {
        $this->import($this->decrypting());

        $ada = User::query()->findOrFail(7);
        $this->assertSame('ada@login.test', $ada->email, 'the login\'s own address, not the account\'s billing one');
        $this->assertTrue(Hash::check('Whmcs-Pass-1', $ada->password));
        $this->assertSame(['Bob', 'Cy'], User::query()->whereIn('id', [8, 9])->orderBy('id')->pluck('first_name')->all(), 'a login of no account too');
    }

    public function test_the_owner_and_the_others_have_their_grants(): void
    {
        $this->import($this->decrypting());

        $owner = ClientUser::query()->where('client_id', 300)->where('user_id', 7)->sole();
        $this->assertTrue($owner->owner);
        $this->assertSame(300, (int) $owner->owner_of_client_id);
        $helper = ClientUser::query()->where('client_id', 300)->where('user_id', 8)->sole();
        $this->assertFalse($helper->owner);
        // `chat` with `tickets`: WHMCS has no live chat, and a login that opens tickets may chat (review of #475).
        $this->assertSame(['invoices', 'tickets', 'chat'], $helper->permissions, 'WHMCS\'s names, an unknown one left out');
        $this->assertTrue($helper->allows('invoices'));
        $this->assertFalse($helper->allows('products'));
    }

    public function test_2fa_comes_over_when_whmcs_can_read_it(): void
    {
        $this->import($this->decrypting());

        $this->assertSame(self::SECRET, User::query()->findOrFail(7)->tfa_secret, 'the same app keeps working');
        $this->assertNull(User::query()->findOrFail(9)->tfa_secret);
        $this->assertStringContainsString('user ids 9', $this->output->fetch(), 'the one not carried is listed');
    }

    public function test_without_whmcss_key_no_login_gets_a_wrong_2fa_and_all_are_listed(): void
    {
        $this->import(new WhmcsSecondFactor(null));

        $this->assertNull(User::query()->findOrFail(7)->tfa_secret);
        $output = $this->output->fetch();
        $this->assertStringContainsString('user ids 7, 9', $output);
        $this->assertStringContainsString('WHMCS_CC_ENCRYPTION_HASH', $output);
    }

    public function test_a_secret_in_other_shapes_is_read_or_refused(): void
    {
        $read = fn (string $plain) => (new WhmcsSecondFactor(fn () => $plain))->secret('totp', 'x');

        $this->assertSame(self::SECRET, $read(json_encode(['secret' => strtolower(self::SECRET) . '=='])));
        $this->assertNull($read(json_encode(['secret' => 'not base32!'])));
        $this->assertNull($read(serialize(['secret' => self::SECRET])), 'WHMCS 8 keeps JSON: nothing else is read');
        $this->assertNull((new WhmcsSecondFactor(fn () => json_encode(['secret' => self::SECRET])))->secret('duosecurity', 'x'), 'another kind of 2FA');
    }

    public function test_a_zero_date_and_an_account_whose_owner_is_gone(): void
    {
        $this->whmcs->exec("UPDATE tblusers SET email_verified_at = '0000-00-00 00:00:00' WHERE id = 8");
        $this->whmcs->exec("INSERT INTO tblclients VALUES (301, 'Gone', 'Owner', '', 'gone@company.test', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'GR',
            0, 0, 0, 0, 1, '2024-01-01 00:00:00', '2025-01-01 00:00:00')");
        $this->whmcs->exec("INSERT INTO tblusers_clients VALUES (3, 999, 301, 1, NULL, NULL, '2024-01-01 00:00:00', NULL)");

        $this->import($this->decrypting());

        $this->assertNull(User::query()->findOrFail(8)->email_verified_at);
        $this->assertNull(Client::query()->find(301), 'an account with no owner to log in is not made');
    }

    /** Their language (docs/accounts.md): WHMCS's names as ours, the account's and the login's; empty is the instance's. */
    public function test_languages_come_over(): void
    {
        $this->whmcs->exec('ALTER TABLE tblclients ADD COLUMN language TEXT');
        $this->whmcs->exec('ALTER TABLE tblusers ADD COLUMN language TEXT');
        $this->whmcs->exec("UPDATE tblclients SET language = 'english' WHERE id = 300");
        $this->whmcs->exec("UPDATE tblusers SET language = 'English' WHERE id = 7");
        $this->whmcs->exec("UPDATE tblusers SET language = '' WHERE id = 8");

        $this->import($this->decrypting());

        $this->assertSame('en', Client::query()->findOrFail(300)->language);
        $this->assertSame(['en', null], [User::query()->findOrFail(7)->language, User::query()->findOrFail(8)->language]);
    }
}
