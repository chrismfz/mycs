<?php

namespace Tests\Feature;

use App\Http\Middleware\McpAuth;
use App\Mcp\Tools\SearchTool;
use App\Models\ApiKey;
use App\Models\DebugLog;
use App\Models\Domain;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Independent security review of #857/#858 (MCP reads): failing tests for what it found. */
class SecMcpSearchReviewTest extends TestCase
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

    /** @return array<string, mixed> */
    private function ask(string $key, string $tool, array $arguments = []): array
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$key}", 'Accept' => 'application/json, text/event-stream'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]])->assertOk();
        $this->assertNotTrue($response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return (array) json_decode((string) $response->json('result.content.0.text'), true);
    }

    /**
     * SEC-1. A role with admin.clients.viewAny but not admin.domains.viewAny gets `domains: null` from `client`, yet
     * `search` (in=clients) matches the registered domains' fqdn and answers which account holds a name: a substring
     * oracle for the domains its role may not see (the admin's client search looks at the contact only).
     */
    public function test_sec_1_search_clients_does_not_match_domains_for_a_role_without_domains(): void
    {
        $client = User::factory()->create()->client();
        Domain::factory()->create(['client_id' => $client->id, 'sld' => 'hiddenbrand', 'tld' => 'gr', 'fqdn' => 'hiddenbrand.gr']);
        $key = $this->key($this->staff([McpAuth::PERMISSION, 'admin.clients.viewAny']));

        $this->assertNull($this->ask($key, 'client', ['client' => (string) $client->id])['domains'], 'the client tool hides its domains');
        $this->assertSame([], array_column($this->ask($key, 'search', ['query' => 'hiddenbr', 'in' => 'clients'])['clients'], 'id'),
            'search answers which account holds a domain the role may not see');
    }

    /**
     * SEC-1b. The same role, without admin.services.viewAny: a service's username and IPs are not in `client`, yet
     * `search` matches them (the panel username is shown only to who may manage the service, review of #615).
     */
    public function test_sec_1b_search_clients_does_not_match_a_service_username_for_a_role_without_services(): void
    {
        $client = User::factory()->create()->client();
        Service::factory()->create(['client_id' => $client->id, 'product_id' => Product::factory()->create()->id,
            'status' => Service::STATUS_ACTIVE, 'domain' => null, 'username' => 'zx9panelusr']);
        $key = $this->key($this->staff([McpAuth::PERMISSION, 'admin.clients.viewAny']));

        $this->assertSame([], array_column($this->ask($key, 'search', ['query' => 'zx9pan', 'in' => 'clients'])['clients'], 'id'));
    }

    /**
     * SEC-2. A ticket's text is matched raw but shown scrubbed: a secret Scrub masks (here a URL's token) is rebuilt
     * by asking for its prefix a character at a time. Found means the next character is right.
     */
    public function test_sec_2_ticket_search_is_no_oracle_for_what_scrub_masks(): void
    {
        $ticket = Ticket::factory()->create(['client_id' => User::factory()->create()->client()->id, 'subject' => 'Reset link']);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'is_internal' => false,
            'message' => 'Use https://panel.example.test/reset?token=Qw7Rt5Yu3Io1 to get in']);
        $key = $this->key($this->staff([McpAuth::PERMISSION, 'admin.clients.viewAny', 'admin.tickets.viewAny']));

        $shown = $this->ask($key, 'ticket', ['ticket' => '#' . $ticket->id]);
        $this->assertStringNotContainsString('Qw7Rt5', json_encode($shown), 'the ticket tool masks the token');

        $hit = array_column($this->ask($key, 'search', ['query' => 'token=Qw7Rt5', 'in' => 'tickets'])['tickets'], 'id');
        $miss = array_column($this->ask($key, 'search', ['query' => 'token=Qw7Rt6', 'in' => 'tickets'])['tickets'], 'id');
        $this->assertSame($miss, $hit, 'a masked token is told apart one character at a time');
    }

    /**
     * SEC-3 (pre-existing, TicketTool, not in #857/#858). A message is cut to 10,000 characters BEFORE Scrub: a known
     * secret across the cut is no longer whole, so its head goes out (the fix #857 made for `matched_text`, not here).
     */
    public function test_sec_3_a_known_secret_across_the_ticket_tools_cut_is_not_shown_in_part(): void
    {
        config(['settings.mail_password' => 'MailPassw0rdXYZ123']);
        $ticket = Ticket::factory()->create(['client_id' => User::factory()->create()->client()->id]);
        TicketMessage::query()->create(['ticket_id' => $ticket->id, 'is_internal' => true,
            'message' => str_repeat('a', 9990) . 'MailPassw0rdXYZ123 tail']);
        $key = $this->key($this->staff([McpAuth::PERMISSION, 'admin.tickets.viewAny']));

        $text = (string) json_encode($this->ask($key, 'ticket', ['ticket' => '#' . $ticket->id]));
        $this->assertFalse(str_contains($text, 'MailPassw0'), 'the head of the mail password goes out: …' . mb_substr($text, (int) mb_strpos($text, 'aaaa') + 9980, 40));
    }

    /**
     * Re-review of the SEC-2 fix: the filter must fold accents and case as MariaDB's collation does, or a Greek search
     * without tonos (found by the database) is dropped; text with nothing masked keeps the database's match as is.
     */
    public function test_sec_2_fix_keeps_greek_searches_without_tonos(): void
    {
        $still = new \ReflectionMethod(SearchTool::class, 'stillShows');

        $this->assertTrue($still->invoke(null, 'Ο λογαριασμός μου', 'Ο λογαριασμός μου', 'xyz'), 'nothing masked: the database decided');
        $this->assertTrue($still->invoke(null, 'Λογαριασμός token=Qw7', 'Λογαριασμός token=********', 'λογαριασμοσ'), 'accents, case and final sigma aside');
        $this->assertFalse($still->invoke(null, 'Λογαριασμός token=Qw7', 'Λογαριασμός token=********', 'token=Qw7'), 'the masked part');
    }

    /** Re-review: the `http_error_logs` tool's search was the same oracle on a URL's masked query value, with % and _ as wildcards. */
    public function test_logs_search_is_no_oracle_for_a_masked_url_value(): void
    {
        DebugLog::query()->create(['type' => 'http', 'context' => ['method' => 'GET', 'url' => 'https://api.example.test/v1?api_key=Zx81Kq55Lm&x=1']]);
        $key = $this->key($this->staff([McpAuth::PERMISSION, 'admin.debug_logs.view']));
        $ask = fn (string $search) => count($this->ask($key, 'http_error_logs', ['type' => 'http', 'search' => $search])['entries']);

        $this->assertSame(1, $ask('api.example.test'), 'a visible part is found');
        $this->assertSame($ask('api_key=Zx82'), $ask('api_key=Zx81'), 'the masked value is not told apart');
        $this->assertSame(0, $ask('api%example'), '% literally');
    }

    /** Re-review: `http_error_logs` and `failed_jobs` cut to 1,500 characters before Scrub (the SEC-3 pattern). */
    public function test_logs_and_failed_jobs_scrub_before_the_cut(): void
    {
        config(['settings.mail_password' => 'MailPassw0rdXYZ123']);
        $text = str_repeat('a', 1490) . 'MailPassw0rdXYZ123 tail';
        DebugLog::query()->create(['type' => 'exception', 'context' => ['message' => $text, 'trace' => $text]]);
        DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'Job']), 'exception' => $text, 'failed_at' => now()]);
        $key = $this->key($this->staff(['*']));

        $this->assertStringNotContainsString('MailPassw0', (string) json_encode($this->ask($key, 'http_error_logs', ['type' => 'error'])));
        $this->assertStringNotContainsString('MailPassw0', (string) json_encode($this->ask($key, 'failed_jobs')));
    }
}
