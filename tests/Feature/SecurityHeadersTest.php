<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The app's own answers carry the browser's basic protections, whatever server is in front (security audit INJ-6,
 * SEC-4): never framed by another site, no sniffing, only the origin in a Referer, and cookies only over HTTPS.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_carries_the_protections(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $this->assertContains(SecurityHeaders::CSP, $response->headers->all('content-security-policy'));
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_machine_endpoints_and_errors_carry_them_too(): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/zlic.php')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_the_maintenance_page_carries_them_too(): void
    {
        $this->app->maintenanceMode()->activate([]);
        try {
            $response = $this->get('/login');
        } finally {
            $this->app->maintenanceMode()->deactivate();
        }

        $response->assertStatus(503)->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_routes_own_stricter_values_stay(): void
    {
        Route::get('/probe-strict', fn () => response('x')->withHeaders([
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer',
        ]));

        $response = $this->get('/probe-strict');

        // Both policies apply: the browser enforces each.
        $this->assertSame(["default-src 'none'; sandbox", SecurityHeaders::CSP], $response->headers->all('content-security-policy'));
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_a_private_links_referrer_policy_stays(): void
    {
        $this->get(route('password.reset', ['token' => 'x', 'email' => 'nobody@example.test']))
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_cookies_are_https_only_when_the_site_is(): void
    {
        $saved = [$_ENV, $_SERVER];
        try {
            foreach (['https://my.example.test' => true, 'http://localhost' => false] as $url => $secure) {
                $_ENV['APP_URL'] = $_SERVER['APP_URL'] = $url;
                unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);

                $this->assertSame($secure, (require base_path('config/session.php'))['secure'], $url);
            }
            // Set, it is what it says.
            $_ENV['SESSION_SECURE_COOKIE'] = $_SERVER['SESSION_SECURE_COOKIE'] = 'false';
            $this->assertFalse((require base_path('config/session.php'))['secure']);
        } finally {
            [$_ENV, $_SERVER] = $saved;
        }
    }
}
