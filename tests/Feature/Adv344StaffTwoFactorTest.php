<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Adversarial review of #344 (2FA required in the admin): a member of staff who has not set up 2FA is logged in on
 * the staff guard after the password alone, and the panel's set-up redirect only guards the panel's pages. Routes
 * that read the same staff session must not act for them either.
 */
class Adv344StaffTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.example.test/api/mcp/auth_callback';

    private function staffSession(Staff $staff): array
    {
        Auth::forgetGuards();

        return [Auth::guard('staff')->getName() => $staff->id];
    }

    /** ADV344-1: the MCP OAuth consent issues a code (then a token) for a member who never set up 2FA. */
    public function test_adv344_1_mcp_consent_needs_2fa_set_up(): void
    {
        $staff = Staff::factory()->withoutTwoFactor()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        Setting::query()->updateOrCreate(['key' => 'mcp_oauth_redirect_hosts', 'settingable_type' => null, 'settingable_id' => null], ['value' => 'example.test', 'type' => 'string']);
        config(['settings.mcp_oauth_redirect_hosts' => 'example.test']);
        $clientId = $this->postJson('/mcp/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]])->assertCreated()->json('client_id');
        $verifier = str_repeat('v', 43);
        $query = ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'state' => 'st4te', 'resource' => url('/mcp')];

        $location = (string) $this->withSession($this->staffSession($staff))
            ->post('/mcp/oauth/authorize', $query + ['decision' => 'approve'])->headers->get('Location');

        $this->assertStringNotContainsString('code=', $location, 'A staff session without 2FA approved an MCP OAuth grant.');
    }

    /** ADV344-2: the admin's ticket-attachment download (an authenticated route of the panel) skips the 2FA set-up. */
    public function test_adv344_2_ticket_attachments_need_2fa_set_up(): void
    {
        $customer = User::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $customer->client()->id]);
        $message = TicketMessage::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $customer->id]);
        $path = 'tickets/uploads/adv344-' . uniqid() . '.txt';
        File::ensureDirectoryExists(storage_path('app/tickets/uploads'));
        File::put(storage_path('app/' . $path), 'secret');
        $this->beforeApplicationDestroyed(fn () => File::delete(storage_path('app/' . $path)));
        $attachment = $message->attachments()->create(['filename' => 'a.txt', 'path' => $path, 'filesize' => 6, 'mime_type' => 'text/plain']);

        $staff = Staff::factory()->admin()->withoutTwoFactor()->create();
        $this->withSession($this->staffSession($staff))->get(route('filament.admin.ticket-attachments.show', $attachment))
            ->assertRedirect('/sysadmin/multi-factor-authentication/set-up');
    }
}
