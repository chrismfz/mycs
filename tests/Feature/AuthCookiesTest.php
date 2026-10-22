<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Models\User;
use App\Models\UserSession;
use App\Support\AuthCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The login cookies can't be set by a sibling or parent domain (live chat review G4), and a login attempt takes as long
 * for an unknown email as for a known one (G9).
 */
class AuthCookiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_prefix_is_used_only_where_the_browser_takes_it(): void
    {
        $this->assertTrue(AuthCookies::prefixed(true, null));
        $this->assertTrue(AuthCookies::prefixed(true, ''));
        $this->assertFalse(AuthCookies::prefixed(true, '.myip.gr'), 'a Domain makes the browser refuse a __Host- cookie');
        $this->assertFalse(AuthCookies::prefixed(false, null), 'without Secure as well');

        config(['session.secure' => true, 'session.domain' => null]);
        $this->assertSame('__Host-mycs_remember', AuthCookies::remember());
        config(['session.secure' => false]);
        $this->assertSame('mycs_remember', AuthCookies::remember());
    }

    public function test_the_session_cookie_is_named_from_the_same_rule(): void
    {
        $saved = [$_ENV, $_SERVER];
        try {
            foreach ([['https://my.example.test', null, '__Host-mycs_session'], ['https://my.example.test', '.example.test', 'mycs_session'],
                ['http://localhost', null, 'mycs_session']] as [$url, $domain, $name]) {
                $_ENV['APP_URL'] = $_SERVER['APP_URL'] = $url;
                unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
                if ($domain === null) {
                    unset($_ENV['SESSION_DOMAIN'], $_SERVER['SESSION_DOMAIN']);
                } else {
                    $_ENV['SESSION_DOMAIN'] = $_SERVER['SESSION_DOMAIN'] = $domain;
                }

                $this->assertSame($name, (require base_path('config/session.php'))['cookie'], $url . ' ' . $domain);
            }
        } finally {
            [$_ENV, $_SERVER] = $saved;
        }
    }

    private function remembered(): UserSession
    {
        config(['session.secure' => true, 'session.domain' => null]);
        $user = User::factory()->create();

        return UserSession::create(['user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
            'last_activity' => now(), 'expires_at' => now()->addDays(30)]);
    }

    public function test_a_remember_cookie_of_the_old_name_signs_no_one_in(): void
    {
        // One a sibling domain could set.
        $this->withCookie('mycs_remember', $this->remembered()->ulid)->get('/dashboard')->assertRedirect(route('login'));

        $this->assertGuest('web');
    }

    public function test_the_prefixed_remember_cookie_signs_its_login_in(): void
    {
        $remembered = $this->remembered();

        $this->withCookie('__Host-mycs_remember', $remembered->ulid)->get('/dashboard');

        $this->assertAuthenticatedAs($remembered->user, 'web');
    }

    public function test_a_login_with_remember_me_sets_the_prefixed_cookie(): void
    {
        config(['session.secure' => true, 'session.domain' => null]);
        User::factory()->create(['email' => 'owner@example.test', 'password' => Hash::make('Right-Pass-1')]);

        $response = Livewire::test(Login::class)->set('email', 'owner@example.test')->set('password', 'Right-Pass-1')
            ->set('remember', true)->call('submit');

        $cookie = collect(Cookie::getQueuedCookies())->firstWhere(fn ($c) => $c->getName() === '__Host-mycs_remember');
        $this->assertNotNull($cookie, 'the remember cookie carries the prefix');
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertAuthenticated('web');
    }

    public function test_an_unknown_email_costs_a_hash_check_too(): void
    {
        $checks = 0;
        Hash::swap(new class(app('hash'), $checks) extends HashManager
        {
            public function __construct(private HashManager $real, private int &$checks)
            {
                parent::__construct(app());
            }

            public function check($value, $hashedValue, array $options = []): bool
            {
                $this->checks++;

                return $this->real->check($value, $hashedValue, $options);
            }

            public function make($value, array $options = []): string
            {
                return $this->real->make($value, $options);
            }
        });

        Livewire::test(Login::class)->set('email', 'nobody@example.test')->set('password', 'whatever-1')->call('submit')
            ->assertHasErrors('email');

        $this->assertSame(1, $checks);
        $this->assertGuest('web');
    }
}
