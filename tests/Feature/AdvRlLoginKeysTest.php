<?php

namespace Tests\Feature;

use App\Admin\Auth\Login as StaffLogin;
use App\Livewire\Auth\Login;
use App\Models\Staff;
use App\Models\User;
use App\Support\CallerIp;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of #333 (throttles: real caller, IPv6 /64, per-login limits).
 *
 * ADV-RL-1: the per-login key is Str::lower(Str::ascii(email)), but the database finds the user by its collation
 * (utf8mb4_uca1400_ai_ci on MariaDB). About 2,200 characters compare equal to an ASCII letter there while Str::ascii
 * drops them (U+0209 «ȉ» = «i», U+01DF «ǟ» = «a», U+0367 combining «u», U+06F1 «۱» = «1», ...): «chrȉs@example.com»
 * logs in as chris@example.com under a fresh key, so every variant is 20 (customer) / 10 (staff) more tries, and on the
 * customer login 5 more per address too. Needs MariaDB/MySQL (SQLite compares bytes).
 *
 * ADV-RL-2: the staff per-login counter is hit before the password is checked and has no captcha: anyone who knows a
 * staff email locks that person out of the admin for 15 minutes with 10 wrong tries (two addresses, or one address and
 * two minutes), and can keep doing it. On main the staff limit was per address only.
 */
class AdvRlLoginKeysTest extends TestCase
{
    use RefreshDatabase;

    private string $remote = '127.0.0.1';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['settings.captcha' => 'disabled']);
        // Livewire::test() sends each call as a request of its own (127.0.0.1): give it the caller's address.
        $this->app->rebinding('request', fn ($app, $request) => $request->server->set('REMOTE_ADDR', $this->remote));
    }

    private function caller(string $remote): void
    {
        $this->remote = $remote;
        request()->server->set('REMOTE_ADDR', $remote);
        request()->headers->remove('X-Forwarded-For');
    }

    public function test_adv_rl_0_the_harness_really_changes_the_address(): void
    {
        $seen = [];
        Event::listen(Attempting::class, function () use (&$seen) {
            $seen[] = CallerIp::key();
        });
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach (['198.51.100.1', '198.51.100.2'] as $ip) {
            $this->caller($ip);
            Livewire::test(StaffLogin::class)->set('data.email', 'x@example.com')->set('data.password', 'x')->call('authenticate');
        }
        $this->assertSame(['198.51.100.1', '198.51.100.2'], $seen);
    }

    private function requireCollatingDatabase(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The database collation matters here: MariaDB/MySQL only.');
        }
    }

    public function test_adv_rl_1_a_collation_variant_of_the_customer_email_gets_no_fresh_count(): void
    {
        $this->requireCollatingDatabase();
        $user = User::factory()->create(['email' => 'chris@example.com', 'password' => Hash::make('Right-Pass-1')]);
        $this->assertSame($user->id, User::where('email', 'chrȉs@example.com')->value('id'), 'the database finds the variant');

        for ($i = 0; $i < 20; $i++) {
            $this->caller('203.0.113.' . ($i + 1));
            Livewire::test(Login::class)->set('email', 'chris@example.com')->set('password', 'guess-' . $i)->call('submit');
        }

        $this->caller('203.0.113.250');
        Livewire::test(Login::class)->set('email', 'chrȉs@example.com')->set('password', 'Right-Pass-1')->call('submit')
            ->assertHasErrors('email');
        $this->assertFalse(auth()->check(), 'the 21st try at the same account, spelled another way, is still limited');
    }

    public function test_adv_rl_1_a_collation_variant_of_the_staff_email_gets_no_fresh_count(): void
    {
        $this->requireCollatingDatabase();
        config(['settings.trusted_proxies' => []]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->admin()->create(['email' => 'chris@example.com', 'password' => Hash::make('Right-Pass-1')]);
        $this->assertSame($staff->id, Staff::where('email', 'chrȉs@example.com')->value('id'), 'the database finds the variant');

        // Up to the member's own limit (StaffLogin::ACCOUNT_FAILURES, raised from 10 so a stranger cannot lock the owner
        // out: ADV-RL-2), each from another address.
        for ($i = 0; $i < StaffLogin::ACCOUNT_FAILURES; $i++) {
            $this->caller('203.0.113.' . ($i + 1));
            Livewire::test(StaffLogin::class)->set('data.email', 'chris@example.com')->set('data.password', 'guess-' . $i)->call('authenticate');
            $this->travel(61)->seconds(); // past Filament's own 5-a-minute limit per address, well within the hour
        }

        $this->caller('203.0.113.250');
        Livewire::test(StaffLogin::class)->set('data.email', 'chrȉs@example.com')->set('data.password', 'Right-Pass-1')->call('authenticate');
        $this->assertFalse(auth('staff')->check(), 'the 11th try at the same staff account, spelled another way, is still limited');
    }

    public function test_adv_rl_2_a_stranger_cannot_lock_a_staff_member_out_of_the_admin(): void
    {
        config(['settings.trusted_proxies' => []]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        // Without 2FA: the password step alone logs in (with it, the code is asked next).
        $staff = Staff::factory()->admin()->withoutTwoFactor()->create(['email' => 'admin@example.com', 'password' => Hash::make('Right-Pass-1')]);

        // Ten wrong passwords from two addresses (Filament's own limit is 5 a minute per address).
        foreach (['198.51.100.1', '198.51.100.2'] as $attacker) {
            for ($i = 0; $i < 5; $i++) {
                $this->caller($attacker);
                Livewire::test(StaffLogin::class)->set('data.email', $staff->email)->set('data.password', 'nope-' . $i)->call('authenticate');
            }
        }
        $this->travel(61)->seconds(); // past Filament's own 5-a-minute limit per address, should the test request keep one address

        // The staff member, from the office, with the right password.
        $this->caller('203.0.113.10');
        Livewire::test(StaffLogin::class)->set('data.email', $staff->email)->set('data.password', 'Right-Pass-1')->call('authenticate');
        $this->assertTrue(auth('staff')->check(), 'ten wrong tries by someone else locked the owner out for 15 minutes');
    }

    /**
     * ADV-RL-3 (re-review of f09262de): `login-ip:<address>` (30 in 15 minutes, any email) counts successful logins too
     * and is never cleared, so an office or a mobile carrier's NAT where 30 customers signed in correctly locks the next
     * customer out with the right password. A per-address limit meant for guessing should count failures only.
     */
    public function test_adv_rl_3_customers_signing_in_correctly_behind_one_nat_do_not_lock_out_the_next_one(): void
    {
        $users = User::factory()->count(31)->create(['password' => Hash::make('Right-Pass-1')]);
        $this->caller('203.0.113.77');

        foreach ($users as $n => $user) {
            Livewire::test(Login::class)->set('email', $user->email)->set('password', 'Right-Pass-1')->call('submit')
                ->assertHasNoErrors();
            $this->assertTrue(auth('web')->check(), 'customer #' . ($n + 1) . ' signs in from the shared address');
            auth('web')->logout();
        }
    }
}
