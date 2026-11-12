<?php

namespace Tests\Feature;

use App\Admin\Auth\Login as StaffLogin;
use App\Admin\Resources\StaffResource\Pages\EditStaff;
use App\Console\Commands\ImportFromWhmcs;
use App\Models\LoginAttempt;
use App\Models\Staff;
use App\Services\Whmcs\AdminImport;
use Filament\Facades\Filament;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PDO;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Owner 2026-11-12: a member of staff signs in with the email or the username, as the WHMCS admins' saved logins have
 * it; both importers bring WHMCS's usernames.
 */
class StaffUsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @return list<string> */
    private function results(): array
    {
        return LoginAttempt::query()->where('area', LoginAttempt::STAFF)->orderBy('id')->pluck('result')->all();
    }

    public function test_a_member_signs_in_with_the_username_or_the_email(): void
    {
        $staff = Staff::factory()->admin()->withoutTwoFactor()->create(['email' => 'chris@myip.test', 'username' => 'chris', 'password' => 'Staff-Pass-1']);

        Livewire::test(StaffLogin::class)->set('data.email', 'chris')->set('data.password', 'Staff-Pass-1')->call('authenticate')->assertHasNoErrors();
        $this->assertSame($staff->id, auth('staff')->id());
        auth('staff')->logout();
        Livewire::test(StaffLogin::class)->set('data.email', 'chris@myip.test')->set('data.password', 'Staff-Pass-1')->call('authenticate')->assertHasNoErrors();
        $this->assertSame($staff->id, auth('staff')->id());

        $this->assertSame([LoginAttempt::SUCCESS, LoginAttempt::SUCCESS], $this->results());
        $this->assertSame([$staff->id, $staff->id], LoginAttempt::query()->orderBy('id')->pluck('staff_id')->all());
    }

    public function test_a_wrong_password_or_an_unknown_username_is_refused_and_kept(): void
    {
        $staff = Staff::factory()->admin()->withoutTwoFactor()->create(['email' => 'chris@myip.test', 'username' => 'chris', 'password' => 'Staff-Pass-1']);

        Livewire::test(StaffLogin::class)->set('data.email', 'chris')->set('data.password', 'Wrong-Pass-1')->call('authenticate')->assertHasErrors('data.email');
        Livewire::test(StaffLogin::class)->set('data.email', 'nobody')->set('data.password', 'Staff-Pass-1')->call('authenticate')->assertHasErrors('data.email');

        $this->assertFalse(auth('staff')->check());
        $this->assertSame([LoginAttempt::FAILED, LoginAttempt::UNKNOWN], $this->results());
        $this->assertSame([$staff->id, null], LoginAttempt::query()->orderBy('id')->pluck('staff_id')->all());
    }

    public function test_an_email_wins_over_another_members_username_of_the_same_spelling(): void
    {
        // A username never has an @ (the form refuses it), so this is only a guard: the email's own member signs in.
        $owner = Staff::factory()->admin()->withoutTwoFactor()->create(['email' => 'a@myip.test', 'password' => 'Owner-Pass-1']);
        Staff::factory()->admin()->withoutTwoFactor()->create(['email' => 'b@myip.test', 'username' => 'x', 'password' => 'Other-Pass-1']);
        Staff::query()->where('email', 'b@myip.test')->update(['username' => 'a@myip.test']);

        Livewire::test(StaffLogin::class)->set('data.email', 'a@myip.test')->set('data.password', 'Owner-Pass-1')->call('authenticate')->assertHasNoErrors();
        $this->assertSame($owner->id, auth('staff')->id());
    }

    public function test_the_staff_form_keeps_a_username_without_an_at_sign_and_unique(): void
    {
        $admin = Staff::factory()->admin()->withoutTwoFactor()->create(['email' => 'chris@myip.test']);
        $other = Staff::factory()->admin()->withoutTwoFactor()->create(['email' => 'ilias@myip.test', 'username' => 'ilias']);
        $this->actingAs($admin, 'staff');

        Livewire::test(EditStaff::class, ['record' => $admin->getRouteKey()])->set('data.username', 'chris@x')->call('save')->assertHasErrors('data.username');
        Livewire::test(EditStaff::class, ['record' => $admin->getRouteKey()])->set('data.username', 'ilias')->call('save')->assertHasErrors('data.username');
        Livewire::test(EditStaff::class, ['record' => $admin->getRouteKey()])->set('data.username', ' chris ')->call('save')->assertHasNoErrors();
        $this->assertSame('chris', $admin->fresh()->username);
        Livewire::test(EditStaff::class, ['record' => $admin->getRouteKey()])->set('data.username', '')->call('save')->assertHasNoErrors();
        $this->assertNull($admin->fresh()->username);
        $this->assertSame('ilias', $other->fresh()->username);
    }

    public function test_both_importers_bring_the_whmcs_username_unless_it_has_an_at_sign_or_is_taken(): void
    {
        $whmcs = new PDO('sqlite::memory:');
        $whmcs->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $whmcs->exec('CREATE TABLE tbladmins (id INTEGER PRIMARY KEY, username TEXT, firstname TEXT, lastname TEXT, email TEXT, password TEXT, passwordhash TEXT, roleid INTEGER, disabled INTEGER, created_at TEXT)');
        $hash = password_hash('whmcs-pass', PASSWORD_BCRYPT, ['cost' => 10]);
        $insert = $whmcs->prepare("INSERT INTO tbladmins VALUES (?, ?, 'A', 'B', ?, '', ?, 1, 0, NULL)");
        $insert->execute([1, ' chris ', 'chris@myip.test', $hash]);
        $insert->execute([2, 'ilias@nixpal', 'ilias@myip.test', $hash]);
        $insert->execute([3, '', 'haris@myip.test', $hash]);
        $command = app(ImportFromWhmcs::class);
        (new ReflectionProperty($command, 'pdo'))->setValue($command, $whmcs);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        (new ReflectionMethod($command, 'importAdmins'))->invoke($command);

        $this->assertSame([1 => 'chris', 2 => null, 3 => null], Staff::query()->orderBy('id')->pluck('username', 'id')->all());
        Livewire::test(StaffLogin::class)->set('data.email', 'chris')->set('data.password', 'whmcs-pass')->call('authenticate');
        $this->assertSame(1, auth('staff')->id(), 'signs in with the WHMCS username and password');

        // Into a live MyCS: a username already a member's here stays with that member.
        (new AdminImport)->run([
            ['id' => 7, 'username' => 'philip', 'firstname' => 'P', 'lastname' => 'H', 'email' => 'philip@myip.test', 'passwordhash' => $hash, 'roleid' => 1, 'rolename' => 'Full Administrator', 'disabled' => 0],
            ['id' => 8, 'username' => 'chris', 'firstname' => 'C', 'lastname' => 'Two', 'email' => 'chris2@myip.test', 'passwordhash' => $hash, 'roleid' => 1, 'rolename' => 'Full Administrator', 'disabled' => 0],
        ]);
        $this->assertSame('philip', Staff::query()->where('email', 'philip@myip.test')->value('username'));
        $this->assertNull(Staff::query()->where('email', 'chris2@myip.test')->value('username'));
        $this->assertSame('chris', Staff::query()->find(1)->username);
    }

    /** Review of #826, REV-826-3: a no-break space around a WHMCS username is trimmed, one inside refuses it. */
    public function test_a_username_never_keeps_a_space_nobody_could_type(): void
    {
        $this->assertSame('chris', AdminImport::username(['username' => "\u{00A0}chris\u{00A0}"]));
        $this->assertNull(AdminImport::username(['username' => "ch\u{00A0}ris"]));
    }
}
