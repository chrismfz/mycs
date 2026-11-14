<?php

namespace Tests\Feature;

use App\Http\Middleware\McpAuth;
use App\Models\ApiKey;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use MyCS\Extensions\Others\DiscordNotifications\DiscordNotifications;
use Tests\TestCase;

/**
 * Security review of #860 (add_ticket_note).
 */
class SecMcpTicketNoteTest extends TestCase
{
    use RefreshDatabase;

    private function writer(): string
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(),
            'permissions' => [McpAuth::PERMISSION, 'admin.tickets.viewAny', 'admin.tickets.update']])->id]);
        $plain = 'mcp-sec-' . bin2hex(random_bytes(16));
        ApiKey::query()->create(['name' => 'MCP', 'token' => hash('sha256', $plain), 'staff_id' => $staff->id, 'type' => 'admin',
            'permissions' => [McpAuth::PERMISSION], 'enabled' => true]);

        return $plain;
    }

    /** @return array{0: bool, 1: string} */
    private function note(string $key, Ticket $ticket, string $note): array
    {
        $this->app['auth']->forgetGuards();
        $response = $this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'add_ticket_note', 'arguments' => ['ticket' => '#' . $ticket->id, 'note' => $note]]]);

        return [(bool) $response->json('result.isError') || $response->json('error') !== null,
            (string) ($response->json('result.content.0.text') ?? $response->json('error.message'))];
    }

    private function ticket(): Ticket
    {
        Mail::fake();

        return Ticket::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Ticket::STATUS_OPEN]);
    }

    /**
     * SEC-860-1. The Discord Notifications extension's «Ticket Replied» posts every TicketMessage's text, internal or not
     * (ChatNotifications and TicketMailer skip notes; this one does not). An agent's note, written without staff, goes to
     * a third party's servers (Discord), with whatever the agent put in it.
     */
    public function test_an_mcp_note_is_not_posted_to_discord(): void
    {
        Http::fake(['https://discord.com/*' => Http::response([])]);
        (new DiscordNotifications(['webhook_url' => 'https://discord.com/api/webhooks/1/test', 'events' => ['Ticket Replied'], 'ping_type' => 'none']))->boot();
        $ticket = $this->ticket();

        [$error, $text] = $this->note($this->writer(), $ticket, 'Internal finding: the customer\'s cPanel user is acme42, home /home/acme42.');
        $this->assertFalse($error, $text);

        $posted = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'discord.com/api/webhooks'))
            ->map(fn ($pair) => json_encode($pair[0]->data()))->implode(' | ');
        $this->assertStringNotContainsString('acme42', $posted, 'an internal note left MyCS for Discord: ' . $posted);
        $this->assertStringContainsString('Internal note', $posted, 'still announced, without its text');
    }

    /**
     * SEC-860-2. `headers: false` drops the «Basic …» rule entirely, so a real HTTP Basic credential an agent pastes
     * (from ssh, a server's config, a log it read elsewhere) is stored in the ticket in clear, base64 of user:password.
     * Bearer is still masked; «Authorization: Basic <b64>» is a header, not English.
     */
    public function test_an_authorization_basic_header_in_a_note_is_masked(): void
    {
        $ticket = $this->ticket();
        $b64 = base64_encode('whmuser:S3cretPassw0rd!');

        [$error, $text] = $this->note($this->writer(), $ticket, "The panel answers 401 to:\nAuthorization: Basic {$b64}\nBasic troubleshooting done.");
        $this->assertFalse($error, $text);

        $stored = (string) TicketMessage::query()->where('ticket_id', $ticket->id)->value('message');
        $this->assertStringContainsString('Basic troubleshooting', $stored, 'the ADV-860-2 fix still holds');
        $this->assertStringNotContainsString($b64, $stored, 'a Basic credential stored in clear: ' . $stored);
    }
}
