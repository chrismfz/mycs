<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The customers' OAuth server (Passport) is gone (security audit AUTHN-10, SA-9): no consent page to crash, no
 * cross-site logout through `prompt=login`, no token endpoint; the MCP's own OAuth is apart and stays.
 */
class PassportRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_its_endpoints_are_gone(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/oauth/oauth/authorize?client_id=1&response_type=code&prompt=login')->assertNotFound();
        $this->assertAuthenticatedAs($user, 'web');
        $this->post('/api/oauth/token', ['grant_type' => 'client_credentials'])->assertNotFound();
        $this->getJson('/api/me')->assertNotFound();
        $this->assertFalse(Route::has('filament.admin.resources.oauth-clients.index'));
    }

    public function test_its_tables_are_dropped_and_the_mcps_stay(): void
    {
        foreach (['oauth_clients', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_auth_codes', 'oauth_personal_access_clients'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasTable('mcp_oauth_clients'));
        $this->assertNull(config('auth.guards.api'));
    }
}
