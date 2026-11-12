<?php

namespace Tests\Feature;

use App\Admin\Auth\Login as StaffLogin;
use App\Models\LoginAttempt;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class Rev826Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_failures_by_username_count_on_the_member_and_hold_the_email_back_too(): void
    {
        $staff = Staff::factory()->admin()->withoutTwoFactor()->create(['email' => 'chris@myip.test', 'username' => 'chris', 'password' => 'Staff-Pass-1']);
        for ($i = 0; $i < StaffLogin::ACCOUNT_FAILURES; $i++) {
            RateLimiter::hit('staff-login-account:' . $staff->id, 3600);
        }
        Livewire::test(StaffLogin::class)->set('data.email', ' chris ')->set('data.password', 'Staff-Pass-1')->call('authenticate');
        $this->assertFalse(auth('staff')->check());
        $this->assertSame([$staff->id], LoginAttempt::query()->pluck('staff_id')->all());
        $this->assertSame([LoginAttempt::THROTTLED], LoginAttempt::query()->pluck('result')->all());
    }

    public function test_a_staff_email_without_an_at_sign_does_not_shadow_another_members_username(): void
    {
        // app:staff:create and the full importer take any email; the login looks the email up first.
        Staff::factory()->admin()->withoutTwoFactor()->create(['email' => 'chris', 'password' => 'Other-Pass-1']);
        $member = Staff::factory()->admin()->withoutTwoFactor()->create(['email' => 'c@myip.test', 'username' => 'chris', 'password' => 'Staff-Pass-1']);
        Livewire::test(StaffLogin::class)->set('data.email', 'chris')->set('data.password', 'Staff-Pass-1')->call('authenticate');
        $this->assertSame($member->id, auth('staff')->id());
    }
}
