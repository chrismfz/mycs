<?php

namespace Tests\Feature;

use App\Http\Middleware\McpAuth;
use App\Mcp\Tools\TicketNoteTool;
use App\Models\ApiKey;
use App\Models\McpCall;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * An internal note on a ticket, written at once through the MCP (owner 2026-11-14, docs/mcp.md): staff only, never
 * mailed, no status change; as the key's staff, marked as the MCP's, secrets masked, bounded.
 */
class McpTicketNoteTest extends TestCase
{
    use RefreshDatabase;

    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Role ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function key(Staff $staff): string
    {
        $plain = 'mcp-test-' . bin2hex(random_bytes(16));
        ApiKey::query()->create(['name' => 'MCP', 'token' => hash('sha256', $plain), 'staff_id' => $staff->id, 'type' => 'admin',
            'permissions' => [McpAuth::PERMISSION], 'enabled' => true]);

        return $plain;
    }

    /** @return array{0: bool, 1: string} whether it erred, and its text */
    private function mcp(string $key, string $tool, array $arguments = []): array
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]]);

        return [(bool) $response->json('result.isError') || $response->json('error') !== null,
            (string) ($response->json('result.content.0.text') ?? $response->json('error.message'))];
    }

    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::factory()->create(['client_id' => User::factory()->create()->client()->id, 'status' => Ticket::STATUS_OPEN] + $attributes);
    }

    public function test_offered_only_to_who_may_update_tickets(): void
    {
        $list = fn (Staff $staff) => collect($this->withHeaders(['Authorization' => 'Bearer ' . $this->key($staff), 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []])->json('result.tools'))->pluck('name');

        $this->assertNotContains('add_ticket_note', $list($this->staff([McpAuth::PERMISSION, 'admin.tickets.viewAny'])));
        $this->assertContains('add_ticket_note', $list($this->staff([McpAuth::PERMISSION, 'admin.tickets.viewAny', 'admin.tickets.update'])));
    }

    public function test_a_note_is_internal_marked_masked_and_moves_nothing(): void
    {
        config(['settings.mail_password' => 'MailPassw0rdXYZ123']);
        $ticket = $this->ticket();
        $staff = $this->staff([McpAuth::PERMISSION, 'admin.tickets.update']);
        $key = $this->key($staff);
        Mail::fake(); // after the ticket: opening one mails the customer
        Notification::fake();

        [$error, $text] = $this->mcp($key, 'add_ticket_note', ['ticket' => '#' . $ticket->id,
            'note' => "Account #12: the domain is in redemption.\u{202E} The mail password is MailPassw0rdXYZ123."]);

        $this->assertFalse($error, $text);
        $note = TicketMessage::query()->where('ticket_id', $ticket->id)->sole();
        $this->assertTrue($note->is_internal);
        $this->assertSame([$staff->id, TicketNoteTool::VIA], [$note->staff_id, $note->via]);
        $this->assertStringStartsWith("**MCP note**\n\n", $note->message);
        $this->assertStringContainsString('in redemption.', $note->message);
        $this->assertStringNotContainsString('MailPassw0rd', $note->message, 'secrets masked');
        $this->assertStringNotContainsString("\u{202E}", $note->message, 'no bidi override');
        $this->assertSame(Ticket::STATUS_OPEN, $ticket->fresh()->status, 'a note moves no status');
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        $this->assertSame('ok', McpCall::query()->where('tool', 'add_ticket_note')->value('outcome'));
    }

    public function test_bounded_and_never_on_a_merged_ticket(): void
    {
        $key = $this->key($this->staff([McpAuth::PERMISSION, 'admin.tickets.update']));
        $ticket = $this->ticket();

        $this->assertStringContainsString('empty', $this->mcp($key, 'add_ticket_note', ['ticket' => '#' . $ticket->id, 'note' => "  \u{200B} "])[1]);
        $this->assertStringContainsString('at most', $this->mcp($key, 'add_ticket_note', ['ticket' => '#' . $ticket->id, 'note' => str_repeat('x', TicketNoteTool::MAX + 1)])[1]);
        for ($i = 0; $i < TicketNoteTool::PER_DAY; $i++) {
            $this->assertFalse($this->mcp($key, 'add_ticket_note', ['ticket' => '#' . $ticket->id, 'note' => "Note {$i}"])[0]);
        }
        [$error, $text] = $this->mcp($key, 'add_ticket_note', ['ticket' => '#' . $ticket->id, 'note' => 'One more']);
        $this->assertTrue($error);
        $this->assertStringContainsString('24 hours', $text);
        // A note staff wrote themselves does not count.
        $ticket->messages()->create(['staff_id' => Staff::factory()->create()->id, 'message' => 'By hand', 'is_internal' => true]);
        $this->assertSame(TicketNoteTool::PER_DAY, TicketMessage::query()->where('ticket_id', $ticket->id)->where('via', TicketNoteTool::VIA)->count());

        $merged = $this->ticket(['merged_into_id' => $ticket->id]);
        $this->assertStringContainsString('merged', $this->mcp($key, 'add_ticket_note', ['ticket' => '#' . $merged->id, 'note' => 'Hello'])[1]);
        $this->assertSame(0, TicketMessage::query()->where('ticket_id', $merged->id)->count());
    }
}
