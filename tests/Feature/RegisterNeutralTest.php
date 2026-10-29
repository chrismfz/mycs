<?php

namespace Tests\Feature;

use App\Livewire\Auth\Register;
use App\Models\EmailLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An address's account is never told by registration (owner's decision 2026-10-23): the page answers «we sent you an
 * email», and the account's owner is told how to log in or reset, once an hour at most.
 */
class RegisterNeutralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Setting::query()->updateOrCreate(['key' => 'mail_disable', 'settingable_type' => null, 'settingable_id' => null], ['value' => '0', 'type' => 'boolean']);
        config(['settings.mail_disable' => false, 'settings.tos' => false]);
    }

    private function register(string $email)
    {
        return Livewire::test(Register::class)->set('contact.country', 'GR')->set('first_name', 'Eve')->set('last_name', 'Probe')->set('email', $email)
            ->set('password', 'Probe-Pass-1')->set('password_confirmation', 'Probe-Pass-1')->call('submit');
    }

    public function test_an_address_with_an_account_is_never_said_to_be_taken(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test', 'password' => Hash::make('Owner-Pass-1')]);

        $this->register('owner@example.test')->assertHasNoErrors()->assertRedirect(route('login'));

        $this->assertGuest('web');
        $this->assertSame(1, User::query()->where('email', 'owner@example.test')->count(), 'no second account');
        $this->assertTrue(Hash::check('Owner-Pass-1', $owner->fresh()->password), 'the owner\'s password is untouched');
        $this->assertSame(1, EmailLog::query()->where('to', 'owner@example.test')->where('subject', __('auth.register_taken.subject'))->count());
    }

    public function test_the_owner_is_told_once_an_hour_at_most(): void
    {
        User::factory()->create(['email' => 'owner@example.test']);

        $this->register('owner@example.test');
        $this->register('owner@example.test');

        $this->assertSame(1, EmailLog::query()->where('to', 'owner@example.test')->where('subject', __('auth.register_taken.subject'))->count());
    }

    public function test_a_new_address_registers_and_logs_in_as_before(): void
    {
        $this->register('new@example.test')->assertHasNoErrors();

        $this->assertAuthenticated('web');
        $this->assertSame(1, User::query()->where('email', 'new@example.test')->count());
    }
}
