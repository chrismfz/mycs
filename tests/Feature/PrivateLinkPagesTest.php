<?php

namespace Tests\Feature;

use App\Models\ClientInvite;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A page reached by a secret in its URL (a password reset, an invitation, a guest's ticket) keeps it to itself (security
 * audit AUTHN-4): no staff script reads location.href there (head, header or footer), no Referer, no cache, and the
 * session never keeps the URL.
 */
class PrivateLinkPagesTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = '<script>analytics(location.href)</script>';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['theme_custom_head', 'theme_custom_header', 'theme_custom_footer'] as $key) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => self::SCRIPT, 'type' => 'string']);
        }
        SettingsProvider::flushCache();
        SettingsProvider::reload();
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertStringNotContainsString('analytics(location.href)', (string) $response->getContent());
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_password_reset_page_keeps_its_token(): void
    {
        $user = User::factory()->create(['email' => 'a@example.test']);
        $token = Password::createToken($user);

        $response = $this->get('/password/reset/' . $token . '?email=a%40example.test');
        $this->assertPrivate($response);
        $this->assertStringNotContainsString($token, (string) json_encode(session()->all()), 'the session never keeps the URL');
    }

    public function test_an_invitation_page_keeps_its_token(): void
    {
        $owner = User::factory()->create();
        [, $token] = ClientInvite::issue($owner->client(), 'new@example.test', null, $owner);

        $response = $this->get('/invite/' . $token);
        $response->assertOk();
        $this->assertStringNotContainsString('analytics(location.href)', (string) $response->getContent());
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    public function test_other_pages_keep_the_staff_scripts(): void
    {
        $this->assertStringContainsString('analytics(location.href)', (string) $this->get('/login')->getContent());
    }
}
