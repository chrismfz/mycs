<?php

namespace Tests\Feature;

use App\Admin\Auth\Login as StaffLogin;
use App\Admin\Logs;
use App\Admin\Resources\LoginAttemptResource;
use App\Admin\Resources\LoginAttemptResource\Pages\ListLoginAttempts;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Tfa;
use App\Models\LoginAttempt;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Login History (owner 2026-10-23): every sign-in to the client area and the admin, good or not, never a password,
 * kept 90 days; among the logs to staff who see the Activity Log.
 */
class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.captcha' => 'disabled']);
    }

    /** @return list<string> */
    private function results(string $area): array
    {
        return LoginAttempt::query()->where('area', $area)->orderBy('id')->pluck('result')->all();
    }

    public function test_a_customers_sign_ins_good_and_bad_are_kept_never_a_password(): void
    {
        $user = User::factory()->create(['email' => 'maria@example.com', 'password' => 'Right-Pass-1']);

        Livewire::test(Login::class)->set('email', 'maria@example.com')->set('password', 'Wrong-Pass-1')->call('submit');
        Livewire::test(Login::class)->set('email', 'nobody@example.com')->set('password', 'Wrong-Pass-1')->call('submit');
        // A password typed into the address: never kept.
        Livewire::test(Login::class)->set('email', 'Secret-Pass-9')->set('password', 'Wrong-Pass-1')->call('submit');
        Livewire::test(Login::class)->set('email', 'maria@example.com')->set('password', 'Right-Pass-1')->call('submit');

        $this->assertSame([LoginAttempt::FAILED, LoginAttempt::UNKNOWN, LoginAttempt::SUCCESS], $this->results(LoginAttempt::CLIENT));
        $rows = LoginAttempt::query()->orderBy('id')->get();
        $this->assertSame([$user->id, null, $user->id], $rows->pluck('user_id')->all());
        $this->assertSame(['maria@example.com', 'nobody@example.com', 'maria@example.com'], $rows->pluck('email')->all());
        $this->assertSame(0, LoginAttempt::query()->where('email', 'like', '%Pass%')->count());
    }

    public function test_a_wrong_2fa_code_and_the_limit_are_kept(): void
    {
        $user = User::factory()->create(['tfa_secret' => 'JBSWY3DPEHPK3PXP']);
        Session::put('2fa', ['user_id' => $user->id, 'remember' => false, 'expires' => now()->addMinutes(10)]);
        for ($i = 0; $i < 6; $i++) {
            Livewire::test(Tfa::class)->set('code', '000000')->call('verify');
        }

        $results = $this->results(LoginAttempt::CLIENT);
        $this->assertSame(array_fill(0, 5, LoginAttempt::FAILED_2FA), array_slice($results, 0, 5));
        $this->assertSame(LoginAttempt::THROTTLED, end($results));
    }

    public function test_a_staff_members_sign_ins_are_kept(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->withoutTwoFactor()->create(['email' => 'chris@myip.test', 'password' => 'Staff-Pass-1']);

        Livewire::test(StaffLogin::class)->set('data.email', 'chris@myip.test')->set('data.password', 'Wrong-Pass-1')->call('authenticate');
        Livewire::test(StaffLogin::class)->set('data.email', 'ghost@myip.test')->set('data.password', 'Wrong-Pass-1')->call('authenticate');
        Livewire::test(StaffLogin::class)->set('data.email', 'chris@myip.test')->set('data.password', 'Staff-Pass-1')->call('authenticate');

        $this->assertSame([LoginAttempt::FAILED, LoginAttempt::UNKNOWN, LoginAttempt::SUCCESS], $this->results(LoginAttempt::STAFF));
        $this->assertSame([$staff->id, null, $staff->id], LoginAttempt::query()->orderBy('id')->pluck('staff_id')->all());
    }

    public function test_a_staff_members_wrong_2fa_code_is_kept(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Staff::factory()->admin()->create(['email' => 'chris@myip.test', 'password' => 'Staff-Pass-1']); // with 2FA

        $page = Livewire::test(StaffLogin::class)->set('data.email', 'chris@myip.test')->set('data.password', 'Staff-Pass-1')->call('authenticate');
        $page->set('data.multiFactor.app.code', '000000')->call('authenticate');

        $this->assertSame([LoginAttempt::FAILED_2FA], $this->results(LoginAttempt::STAFF));
    }

    public function test_filaments_own_limit_is_kept_too(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        for ($i = 0; $i < 6; $i++) {
            Livewire::test(StaffLogin::class)->set('data.email', 'ghost@myip.test')->set('data.password', 'Wrong-Pass-1')->call('authenticate');
        }

        $this->assertContains(LoginAttempt::THROTTLED, $this->results(LoginAttempt::STAFF));
    }

    public function test_the_history_page_lists_them_to_who_may_see_the_activity_log(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $user = User::factory()->create(['email' => 'maria@example.com']);
        $good = LoginAttempt::query()->create(['area' => LoginAttempt::CLIENT, 'user_id' => $user->id, 'email' => 'maria@example.com',
            'ip_address' => '203.0.113.7', 'result' => LoginAttempt::SUCCESS]);
        $bad = LoginAttempt::query()->create(['area' => LoginAttempt::STAFF, 'email' => 'ghost@myip.test',
            'ip_address' => '198.51.100.9', 'result' => LoginAttempt::UNKNOWN]);
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Audit', 'permissions' => ['admin.audit.viewAny']])->id]), 'staff');

        $this->get(LoginAttemptResource::getUrl('index'))->assertOk()->assertSee('203.0.113.7');
        Livewire::test(ListLoginAttempts::class)
            ->assertTableColumnFormattedStateSet('result', 'Unknown address', $bad)
            ->filterTable('failed')->assertCanSeeTableRecords([$bad])->assertCanNotSeeTableRecords([$good]);
        $this->assertContains(__('Login History'), array_map(fn ($item) => $item->getLabel(), Logs::navigation()));
    }

    public function test_others_do_not_see_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Tickets', 'permissions' => ['admin.tickets.viewAny']])->id]), 'staff');

        $this->get(LoginAttemptResource::getUrl('index'))->assertForbidden();
    }

    public function test_it_is_kept_ninety_days(): void
    {
        $old = LoginAttempt::query()->create(['area' => LoginAttempt::CLIENT, 'result' => LoginAttempt::FAILED]);
        $old->forceFill(['created_at' => now()->subDays(LoginAttempt::DAYS + 1)])->save();
        $new = LoginAttempt::query()->create(['area' => LoginAttempt::CLIENT, 'result' => LoginAttempt::FAILED]);

        $this->artisan('model:prune', ['--model' => [LoginAttempt::class]])->assertSuccessful();

        $this->assertSame([$new->id], LoginAttempt::query()->pluck('id')->all());
    }
}
