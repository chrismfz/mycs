<?php

namespace Tests\Feature;

use App\Livewire\Client\Account;
use App\Livewire\Client\Security;
use App\Models\EmailLog;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserSession;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use RobThree\Auth\Providers\Qr\EndroidQrCodeProvider;
use RobThree\Auth\TwoFactorAuth;
use Tests\TestCase;

/**
 * A stolen session must not become the account for good (security audit AUTHN-1): a new login email (a reset goes
 * there) needs the password, the old address is told, and two-factor authentication goes off only with the password and
 * a code from the app. Staff in «Login as client» do neither (AUTHN-6).
 */
class ReauthSensitiveTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null, 'settingable_id' => null], ['value' => '0', 'type' => 'boolean']);
        config(['settings.mail_disable' => false]);
        $this->user = User::factory()->create(['email' => 'old@example.test', 'password' => Hash::make('Right-Pass-1')]);
    }

    private function as(array $session = []): void
    {
        $data = $this->loginUser($this->user, [CurrentClient::SESSION_KEY => $this->user->client()->id] + $session);
        $this->actingAs($this->user)->withSession($data);
        // The page lists the sessions and marks this one (ResolveUserSession sets it on a real request).
        request()->attributes->set('user_session', UserSession::query()->where('ulid', $data['user_session'])->sole());
    }

    private function code(string $secret): string
    {
        return (new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name')))->getCode($secret);
    }

    public function test_a_new_email_needs_the_password_and_the_old_address_is_told(): void
    {
        $this->as();

        Livewire::test(Account::class)->set('contact.country', 'GR')->set('email', 'new@example.test')->set('email_password', 'wrong')->call('submit')->assertHasErrors(['email_password']);
        $this->assertSame('old@example.test', $this->user->fresh()->email);

        Livewire::test(Account::class)->set('contact.country', 'GR')->set('email', 'new@example.test')->set('email_password', 'Right-Pass-1')->call('submit')->assertHasNoErrors();
        $this->assertSame('new@example.test', $this->user->fresh()->email);
        $this->assertSame(1, EmailLog::query()->where('to', 'old@example.test')->where('subject', __('account.notifications.email_changed_subject'))->count());
    }

    public function test_the_name_alone_needs_no_password(): void
    {
        $this->as();

        Livewire::test(Account::class)->set('contact.country', 'GR')->set('first_name', 'Maria')->call('submit')->assertHasNoErrors();

        $this->assertSame('Maria', $this->user->fresh()->first_name);
    }

    public function test_two_factor_goes_off_only_with_the_password_and_a_code(): void
    {
        $secret = (new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name')))->createSecret();
        $this->user->update(['tfa_secret' => $secret]);
        $this->as();

        Livewire::test(Security::class)->call('disableTwoFactor');
        Livewire::test(Security::class)->set('disable_password', 'Right-Pass-1')->set('disable_code', '000000')->call('disableTwoFactor');
        Livewire::test(Security::class)->set('disable_password', 'wrong')->set('disable_code', $this->code($secret))->call('disableTwoFactor');
        $this->assertSame($secret, $this->user->fresh()->tfa_secret);

        Livewire::test(Security::class)->set('disable_password', 'Right-Pass-1')->set('disable_code', $this->code($secret))->call('disableTwoFactor');
        $this->assertNull($this->user->fresh()->tfa_secret);
    }

    public function test_staff_signed_in_as_the_customer_change_neither(): void
    {
        $secret = (new TwoFactorAuth(new EndroidQrCodeProvider, config('app.name')))->createSecret();
        $this->user->update(['tfa_secret' => $secret]);
        $this->as(['impersonating' => $this->user->id]);
        session(['impersonating' => $this->user->id]);

        Livewire::test(Account::class)->set('contact.country', 'GR')->set('email', 'staff@example.test')->set('email_password', 'Right-Pass-1')->call('submit')->assertHasErrors(['email']);
        Livewire::test(Security::class)->set('disable_password', 'Right-Pass-1')->set('disable_code', $this->code($secret))->call('disableTwoFactor');

        $this->assertSame(['old@example.test', $secret], [$this->user->fresh()->email, $this->user->fresh()->tfa_secret]);
    }

    public function test_two_factor_goes_on_with_the_password_and_the_first_code(): void
    {
        $this->as();
        $page = Livewire::test(Security::class)->call('enableTwoFactor');
        $secret = $page->get('twoFactorData')['secret'];

        $page->set('enable_password', 'Right-Pass-1')->set('twoFactorCode', $this->code($secret))->call('enableTwoFactor');

        $this->assertSame($secret, $this->user->fresh()->tfa_secret);
    }
}
