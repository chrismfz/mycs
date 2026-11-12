<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

/**
 * Independent review of #824. The full importer writes AdminImport::whmcsHash() into staff.password as it is, without
 * AdminImport's bcrypt check or $2a$/$2b$ → $2y$ normalisation. Laravel's bcrypt hasher (verify on) throws on a hash
 * that is not $2y$ bcrypt, so the login of such an admin is a 500, not a refusal or a login. Fixtures only.
 */
class Rev824Test extends TestCase
{
    use RefreshDatabase;

    /** @param  list<array{0: int, 1: string, 2: string, 3: string}>  $rows  id, email, password, passwordhash */
    private function fullImport(array $rows): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tbladmins (id INTEGER PRIMARY KEY, firstname TEXT, lastname TEXT, email TEXT, password TEXT, passwordhash TEXT, roleid INTEGER, disabled INTEGER, created_at TEXT)');
        $insert = $whmcs->prepare("INSERT INTO tbladmins VALUES (?, 'A', 'B', ?, ?, ?, 1, 0, NULL)");
        foreach ($rows as $row) {
            $insert->execute($row);
        }
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'importAdmins'))->invoke($command);
    }

    private function attempt(string $email, string $password): bool|string
    {
        try {
            return Auth::guard('staff')->attempt(['email' => $email, 'password' => $password]);
        } catch (Throwable $e) {
            return $e::class . ': ' . $e->getMessage();
        }
    }

    /** REV-824-1: a $2b$ passwordhash logs in through app:import-whmcs-admins (normalised) but not through the full importer. */
    public function test_the_full_import_logs_in_a_2b_passwordhash_like_the_admin_import_does(): void
    {
        $hash = '$2b$' . substr(password_hash('whmcs-pass', PASSWORD_BCRYPT, ['cost' => 10]), 4);
        $this->fullImport([[1, 'b@example.test', password_hash(md5('whmcs-pass'), PASSWORD_BCRYPT, ['cost' => 10]), $hash]]);

        $this->assertTrue($this->attempt('b@example.test', 'whmcs-pass'));
    }

    /** REV-824-2: an older admin with no passwordhash and an md5 password: a refused login («Forgot password»), not an exception. */
    public function test_the_full_import_of_an_md5_admin_refuses_the_login_without_throwing(): void
    {
        $this->fullImport([[1, 'old@example.test', md5('old-pass'), '']]);

        $this->assertFalse($this->attempt('old@example.test', 'old-pass'));
    }
}
