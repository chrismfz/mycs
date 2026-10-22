<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/** Adversarial review of #351 (app:staff:unlock). */
class Adv351UnlockTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false]);
        $this->staff = Staff::factory()->admin()->create(['email' => 'admin@example.test', 'password' => Hash::make('Old-Pass-123'), 'remember_token' => 'tok']);
        $this->staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->staff->saveAppAuthenticationRecoveryCodes(['a', 'b']);
        Audit::query()->delete();
    }

    /**
     * docs/install.md says the unlock «γράφεται στο audit», but every column it changes (2FA secret, recovery codes,
     * password, remember_token) is in Staff::$auditExclude and audit.empty_values is false: owen-it drops the audit,
     * so the member's audit history shows nothing of a break-glass 2FA reset and new password.
     */
    public function test_a_console_2fa_reset_and_new_password_leave_an_audit_row(): void
    {
        $this->artisan('app:staff:unlock', ['email' => 'admin@example.test', '--reset-2fa' => true, '--password' => true, '--force' => true])
            ->expectsQuestion('New password?', 'New-Pass-4567')
            ->expectsQuestion('Again?', 'New-Pass-4567')
            ->assertSuccessful();

        $this->assertFalse($this->staff->fresh()->hasTwoFactor());
        $this->assertGreaterThan(0, Audit::query()->where('auditable_type', $this->staff->getMorphClass())->where('auditable_id', $this->staff->id)->count(),
            'the break-glass left no audit row for the member of staff');
    }

    public function test_a_plain_unlock_leaves_an_audit_trace(): void
    {
        $this->artisan('app:staff:unlock', ['email' => 'admin@example.test', '--force' => true])->assertSuccessful();

        $this->assertGreaterThan(0, Audit::query()->count(), 'the login limit cleared from the console left no audit row');
    }
}
