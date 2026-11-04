<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Exceptions\DisplayException;
use App\Livewire\Auth\Login;
use App\Models\Client;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\UserSession;
use App\Services\Import\WhmcsParity;
use App\Services\Order\OrderLine;
use App\Services\Order\PlaceOrderService;
use App\Support\AuthCookies;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * WG-8: a WHMCS «Closed» account comes closed (its owner cannot sign in, it takes no orders), as WHMCS keeps it out;
 * WG-9: an account that said no to marketing mail (emailoptout, or WHMCS 8's marketing_emails_opt_in 0) has «news» off
 * for its owner, so the first news mail never reaches it. Fake data only.
 */
class ImportClientStatusConsentTest extends TestCase
{
    use RefreshDatabase;

    private PDO $whmcs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->whmcs = new PDO('sqlite::memory:');
        $this->whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->whmcs->exec('CREATE TABLE tblcurrencies (id INTEGER PRIMARY KEY, code TEXT)');
        $this->whmcs->exec("INSERT INTO tblcurrencies VALUES (1, 'EUR')");
        $this->whmcs->exec('CREATE TABLE tblusers (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, password TEXT,
            second_factor TEXT, second_factor_config TEXT, email_verified_at TEXT, created_at TEXT, updated_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblclients (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, companyname TEXT, email TEXT,
            country TEXT, credit REAL, currency INT, created_at TEXT, updated_at TEXT, datecreated TEXT, status TEXT, emailoptout INT,
            marketing_emails_opt_in INT)');
        $this->whmcs->exec('CREATE TABLE tblusers_clients (id INTEGER PRIMARY KEY, auth_user_id INT, client_id INT, owner INT, permissions TEXT,
            last_login TEXT, created_at TEXT, updated_at TEXT)');
        $this->whmcs->exec('CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INT, domainstatus TEXT)');
        if (!NotificationTemplate::query()->where('key', 'announcement_news')->exists()) {
            NotificationTemplate::query()->forceCreate(['key' => 'announcement_news', 'name' => 'News', 'subject' => 'News', 'body' => 'News',
                'enabled' => true, 'mail_enabled' => true, 'in_app_enabled' => false]);
        }
    }

    private function customer(int $id, string $status, int $emailOptOut = 0, int $marketing = 1, ?int $owner = null): void
    {
        $owner ??= $id;
        if ($owner === $id) {
            $this->whmcs->prepare('INSERT INTO tblusers VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$id, 'First', 'Last', "login{$id}@example.test", Hash::make('Whmcs-Pass-1'), '', null, '2024-01-01 00:00:00', '2024-01-01 00:00:00', '2025-01-01 00:00:00']);
        }
        $this->whmcs->prepare("INSERT INTO tblclients VALUES (?, 'First', 'Last', '', ?, 'GR', 0, 1, '2024-01-01 00:00:00', '2025-01-01 00:00:00',
            '2024-01-01', ?, ?, ?)")->execute([$id, "billing{$id}@example.test", $status, $emailOptOut, $marketing]);
        $this->whmcs->prepare('INSERT INTO tblusers_clients (auth_user_id, client_id, owner) VALUES (?, ?, 1)')->execute([$owner, $id]);
    }

    private function import(): BufferedOutput
    {
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $this->whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output = new BufferedOutput));
        (new ReflectionMethod($command, 'importUsers'))->invoke($command);
        (new ReflectionMethod($command, 'importMarketingConsent'))->invoke($command);

        return $output;
    }

    public function test_a_closed_account_cannot_sign_in_or_order(): void
    {
        Queue::fake();
        $this->customer(501, 'Closed');
        $this->customer(502, 'Inactive');
        $this->import();

        $this->assertNotNull(Client::query()->findOrFail(501)->closed_at);
        $this->assertNull(Client::query()->findOrFail(502)->closed_at, '«Inactive» is what its services say: open');

        Livewire::test(Login::class)->set('email', 'login501@example.test')->set('password', 'Whmcs-Pass-1')->call('submit')
            ->assertHasErrors(['email' => __('auth.closed')]);
        $this->assertGuest('web');
        Livewire::test(Login::class)->set('email', 'login502@example.test')->set('password', 'Whmcs-Pass-1')->call('submit')
            ->assertHasNoErrors();

        $product = $this->createProduct()->product;
        $this->expectException(DisplayException::class);
        (new PlaceOrderService)->place(Client::query()->findOrFail(501), 'EUR', [new OrderLine($product, $product->plans()->first())]);
    }

    public function test_a_login_with_another_account_open_still_signs_in_on_it(): void
    {
        $this->customer(601, 'Active');
        $this->customer(602, 'Closed', owner: 601);
        $this->import();

        $user = User::query()->findOrFail(601);
        $this->assertFalse($user->closedOut());
        $this->assertSame(601, $user->client()?->id, 'its open account first');

        Client::query()->findOrFail(601)->update(['closed_at' => now()]);
        $this->assertTrue($user->fresh()->closedOut(), 'staff close the other one too: out');
    }

    /** WG-8: back by «Remember me» to accounts all closed since: not signed in. */
    public function test_a_remembered_session_of_a_closed_account_signs_no_one_in(): void
    {
        $this->customer(801, 'Active');
        $this->import();
        $session = UserSession::create(['user_id' => 801, 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
            'last_activity' => now(), 'expires_at' => now()->addDays(30)]);
        // Past the model (its observer ends the sessions, review of #651): the cookie's own check, a second line.
        DB::table('clients')->where('id', 801)->update(['closed_at' => now()]);

        $this->withCookie(AuthCookies::remember(), $session->ulid)->get('/dashboard')->assertRedirect(url('/'));
        $this->assertGuest('web');
    }

    public function test_who_said_no_to_marketing_has_news_off(): void
    {
        $this->customer(701, 'Active', emailOptOut: 1);
        $this->customer(702, 'Active', marketing: 0);
        $this->customer(703, 'Active');

        $output = $this->import();

        $news = NotificationTemplate::query()->where('key', 'announcement_news')->sole();
        $off = fn (int $user) => DB::table('notification_preferences')->where('user_id', $user)->where('notification_template_id', $news->id)
            ->where('mail_enabled', false)->exists();
        $this->assertTrue($off(701), 'emailoptout');
        $this->assertTrue($off(702), 'WHMCS 8\'s marketing opt-in off');
        $this->assertFalse($off(703));
        $this->assertFalse($news->isEnabledForPreference(User::query()->findOrFail(701)->notificationsPreferences()->where('notification_template_id', $news->id)->first()));
        $this->assertStringContainsString('news off for 2 login(s) of 2 account(s)', $output->fetch());

        // Parity: the closed accounts and the owners without news, WHMCS's beside ours (the other rows' tables, empty).
        foreach (['CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT, subtotal REAL, tax REAL, tax2 REAL, credit REAL, total REAL)',
            'CREATE TABLE tblinvoiceitems (id INTEGER PRIMARY KEY, invoiceid INTEGER, type TEXT, relid INTEGER, amount REAL)',
            'CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY, invoiceid INTEGER, amountin REAL, amountout REAL, fees REAL)',
            'CREATE TABLE tbltickets (id INTEGER PRIMARY KEY)', 'CREATE TABLE tbldomains (id INTEGER PRIMARY KEY)'] as $sql) {
            $this->whmcs->exec($sql);
        }
        $rows = collect((new WhmcsParity($this->whmcs))->rows())->keyBy('metric');
        $this->assertTrue($rows['Owners without news (marketing)']['same']);
        $this->assertSame(2, $rows['Owners without news (marketing)']['mycs']);
        $this->assertTrue($rows['Customers closed']['same']);
    }
}
