<?php

namespace Tests\Feature;

use App\Http\Controllers\McpOauthController;
use App\Http\Middleware\Api\AdminApi;
use App\Livewire\Auth\Register;
use App\Models\ApiKey;
use App\Models\McpOauthGrant;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Support\TicketText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Security audit API-4..9: MCP registration limits by network and for everyone, the redirect hosts staff allow, grants
 * revoked when a login's 2FA changes or it is disabled, ticket images as links, the admin API's 401 before any record
 * lookup, and registration limits.
 */
class McpApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.example.test/api/mcp/auth_callback';

    private string $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hosts('example.test');
        $this->verifier = str_repeat('v', 20) . bin2hex(random_bytes(16));
    }

    /** Stored as staff save it: the settings are read again from the database on later requests. */
    private function hosts(string $hosts): void
    {
        Setting::query()->updateOrCreate(['key' => 'mcp_oauth_redirect_hosts', 'settingable_type' => null, 'settingable_id' => null], ['value' => $hosts, 'type' => 'string']);
        config(['settings.mcp_oauth_redirect_hosts' => $hosts]);
    }

    private function register(string $redirect = self::REDIRECT, string $ip = '203.0.113.7')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/mcp/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$redirect]]);
    }

    private function authorizeQuery(string $clientId, string $redirect = self::REDIRECT): array
    {
        return ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => $redirect, 'state' => 'st4te', 'resource' => url('/mcp'),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];
    }

    private function grantFor(Staff $staff): McpOauthGrant
    {
        $clientId = $this->register()->assertCreated()->json('client_id');
        $location = $this->actingAs($staff, 'staff')->post('/mcp/oauth/authorize', $this->authorizeQuery($clientId) + ['decision' => 'approve'])
            ->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        $this->app['auth']->forgetGuards();
        $this->postJson('/mcp/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $query['code'],
            'redirect_uri' => self::REDIRECT, 'code_verifier' => $this->verifier])->assertOk();

        return McpOauthGrant::query()->where('staff_id', $staff->id)->sole();
    }

    public function test_one_ipv6_network_shares_one_registration_limit(): void
    {
        for ($i = 1; $i <= McpOauthController::REGISTER_PER_HOUR; $i++) {
            $r = $this->register(ip: "2001:db8:1:2::{$i}");
            $this->assertSame(201, $r->status(), (string) $r->getContent());
        }

        $this->register(ip: '2001:db8:1:2::ffff')->assertStatus(429);
        $this->register(ip: '2001:db8:9:9::1')->assertCreated(); // another /64
    }

    public function test_registrations_from_everyone_have_a_ceiling(): void
    {
        for ($i = 0; $i < McpOauthController::REGISTER_PER_HOUR_ALL; $i++) {
            RateLimiter::hit('mcp-register:all', 3600);
        }

        $this->register(ip: '198.51.100.1')->assertStatus(429);
    }

    public function test_a_host_staff_did_not_allow_is_refused_at_registration(): void
    {
        $this->register('https://phisher.invalid/callback')->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        // A suffix that is not a subdomain is not the host either.
        $this->register('https://evilexample.test/callback')->assertStatus(400);
        $this->register('https://deep.sub.example.test/callback')->assertCreated();
    }

    public function test_a_client_registered_before_the_host_was_removed_is_refused_at_authorize_without_a_redirect(): void
    {
        $clientId = $this->register()->assertCreated()->json('client_id');
        $this->hosts('claude.ai');

        $response = $this->actingAs(Staff::factory()->admin()->create(), 'staff')->get('/mcp/oauth/authorize?' . http_build_query($this->authorizeQuery($clientId)));

        $this->assertFalse($response->isRedirect(), 'never sent to a host no longer allowed');
        $this->assertStringNotContainsString('code=', (string) $response->headers->get('Location'));
    }

    public function test_the_default_list_allows_claude_and_the_local_machine(): void
    {
        $this->hosts('');

        $this->register('https://claude.ai/api/mcp/auth_callback')->assertCreated();
        $this->register('http://localhost:33418/callback')->assertCreated();
        $this->register('https://claude.example.test/cb')->assertStatus(400);
    }

    public function test_a_changed_2fa_revokes_what_the_login_approved(): void
    {
        $staff = Staff::factory()->admin()->create();
        $grant = $this->grantFor($staff);

        $staff->fresh()->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');

        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertSame('2FA changed', $grant->fresh()->revoked_reason);
    }

    public function test_a_disabled_member_loses_their_grants(): void
    {
        $staff = Staff::factory()->admin()->create();
        $grant = $this->grantFor($staff);

        $staff->fresh()->update(['active' => false]);

        $this->assertSame('Staff member disabled', $grant->fresh()->revoked_reason);
    }

    public function test_another_change_keeps_the_grants(): void
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $grant = $this->grantFor($staff);

        $staff->fresh()->update(['first_name' => 'Renamed']);

        $this->assertNull($grant->fresh()->revoked_at);
    }

    public function test_a_ticket_image_is_a_link_never_loaded(): void
    {
        $html = TicketText::html("Look ![shot](https://tracker.invalid/p.png?u=1) and ![](javascript:alert(1))\n\n<img src=x onerror=alert(1)>");

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('<a href="https://tracker.invalid/p.png?u=1" rel="nofollow noopener noreferrer" target="_blank">🖼 shot</a>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('&lt;img', $html, 'raw HTML stays escaped');
    }

    public function test_ticket_markdown_still_renders(): void
    {
        $html = TicketText::html("**bold** [site](https://example.test)\nline");

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<a href="https://example.test">site</a>', $html);
        $this->assertStringContainsString('<br>', $html);
    }

    public function test_the_admin_api_answers_401_without_a_key_whether_or_not_the_record_exists(): void
    {
        $user = User::factory()->create();

        $this->getJson(route('api.v1.admin.users.show', $user))->assertStatus(401);
        $this->getJson(route('api.v1.admin.users.show', 999999))->assertStatus(401);
    }

    public function test_registration_is_limited_per_network(): void
    {
        Mail::fake();
        config(['settings.tos' => false]);
        $submit = fn (string $email) => Livewire::withHeaders([])->test(Register::class)->set('first_name', 'A')->set('last_name', 'B')->set('email', $email)
            ->set('password', 'Probe-Pass-1')->set('password_confirmation', 'Probe-Pass-1')->call('submit');

        // A typo costs no try.
        for ($i = 0; $i < Register::PER_NETWORK_HOUR + 2; $i++) {
            $submit('not-an-email')->assertHasErrors('email');
        }
        for ($i = 0; $i < Register::PER_NETWORK_HOUR; $i++) {
            $this->app['auth']->forgetGuards();
            $submit("new{$i}@example.test")->assertHasNoErrors();
        }
        $this->app['auth']->forgetGuards();
        $submit('one-more@example.test')->assertHasErrors('email');

        $this->assertSame(0, User::query()->where('email', 'one-more@example.test')->count());
    }

    public function test_a_valid_admin_api_key_is_not_held_back_by_bad_tries_on_its_network(): void
    {
        for ($i = 0; $i < AdminApi::BAD_PER_MINUTE; $i++) {
            $this->withToken('junk' . $i)->getJson(route('api.v1.admin.users.index'))->assertStatus(401);
        }
        $this->withToken('junk-more')->getJson(route('api.v1.admin.users.index'))->assertStatus(429);

        $plain = 'PAYM' . bin2hex(random_bytes(8));
        $staff = Staff::factory()->admin()->create();
        ApiKey::create(['staff_id' => $staff->id, 'name' => 't', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $plain), 'permissions' => ['admin.users.viewAny']]);
        $status = $this->withToken($plain)->getJson(route('api.v1.admin.users.index'))->status();
        $this->assertNotContains($status, [401, 429], 'the key is let through to its own checks');
    }

    public function test_hosts_may_be_listed_on_new_lines(): void
    {
        $this->hosts("claude.ai\nexample.test");

        $this->register('https://claude.ai/api/mcp/auth_callback')->assertCreated();
        $this->register()->assertCreated();
    }
}
