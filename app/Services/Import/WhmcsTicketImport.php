<?php

namespace App\Services\Import;

use App\Models\Ticket;
use App\Models\TicketDepartment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use MyCS\TicketMail\Attachments;
use PDO;

/**
 * WHMCS's tickets (docs/tickets.md §5α, PR Δ), after its accounts are imported (WHMCS client = the MyCS login of the same
 * id, owner of its account). Read-only on WHMCS; re-runnable: a ticket is found by its `whmcs_id`, a message by its
 * `whmcs_ref`, and what MyCS made is never touched.
 *  - Departments with their address; never the mailbox's login (staff enter it at the cut-over).
 *  - A ticket of a client goes to that client's account; one with no account in WHMCS (`userid` 0) is a guest's, never
 *    the account of the same email (§3 Δ), and so is one whose client was not imported (counted).
 *  - Its opening, its replies (staff's by the admin's name), staff's notes as internal messages; a ticket WHMCS merged
 *    into another joins that one, with a note. Messages as text: WHMCS keeps them HTML-escaped, shown escaped again here.
 *  - Files copied from WHMCS's attachments folder through the same rules as by mail (§3 ΣΤ); what is missing or refused
 *    is counted, never stops the import.
 *  - No event fires (no mail, no status moved, no staff notice): the rows go in as WHMCS has them.
 */
final class WhmcsTicketImport
{
    /** WHMCS's statuses (Answered, Customer-Reply, …); any other (a custom one) is open, and counted. */
    public const STATUSES = [
        'Open' => Ticket::STATUS_OPEN,
        'Answered' => Ticket::STATUS_ANSWERED,
        'Customer-Reply' => Ticket::STATUS_CUSTOMER_REPLY,
        'On Hold' => Ticket::STATUS_ON_HOLD,
        'In Progress' => Ticket::STATUS_ON_HOLD,
        'Closed' => Ticket::STATUS_CLOSED,
    ];

    private const CHUNK = 500;

    /** @var array<string, mixed> */
    private array $counts = ['departments' => 0, 'tickets' => 0, 'guests' => 0, 'no_account' => 0, 'messages' => 0, 'notes' => 0, 'merged' => 0,
        'files' => 0, 'files_missing' => 0, 'files_refused' => 0, 'reference_taken' => 0, 'other_status' => []];

    /** @var array<int, int> WHMCS department id => ours */
    private array $departments = [];

    /** @var array<string, int|null> */
    private array $staff = [];

    public function __construct(private readonly PDO $whmcs, private readonly ?string $attachments = null) {}

    /** @return array<string, mixed> what was brought, and the parity (WHMCS and MyCS, per department and status) */
    public function run(): array
    {
        if (!$this->hasTable('tbltickets')) {
            return $this->counts;
        }
        $this->departments();
        $merged = [];
        $last = 0;
        do {
            $rows = $this->rows('SELECT * FROM tbltickets WHERE id > ? ORDER BY id LIMIT ' . self::CHUNK, [$last]);
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                if ((int) ($row['merged_ticket_id'] ?? 0) > 0 && (int) $row['merged_ticket_id'] !== (int) $row['id']) {
                    $merged[] = $row;

                    continue;
                }
                DB::transaction(fn () => $this->ticket($row));
            }
        } while (count($rows) === self::CHUNK);
        foreach ($merged as $row) {
            DB::transaction(fn () => $this->merged($row));
        }
        $this->counts['parity'] = $this->parity();

        return $this->counts;
    }

    private function departments(): void
    {
        if (!$this->hasTable('tblticketdepartments')) {
            return;
        }
        foreach ($this->rows('SELECT * FROM tblticketdepartments ORDER BY id') as $row) {
            $department = TicketDepartment::query()->firstOrNew(['whmcs_id' => (int) $row['id']]);
            // A department named as one here (made by the A1 migration from the old setting) is taken over, not doubled.
            if (!$department->exists) {
                $department = TicketDepartment::query()->whereNull('whmcs_id')->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $row['name']))])->first()
                    ?? $department;
            }
            $department->forceFill([
                'whmcs_id' => (int) $row['id'],
                'name' => mb_substr(trim((string) $row['name']), 0, 255),
                'sort' => max(0, min(65535, (int) ($row['order'] ?? 0))),
                'hidden' => (bool) ($row['hidden'] ?? false),
                'clients_only' => (bool) ($row['clientsonly'] ?? false),
                'email' => filter_var(trim((string) ($row['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null,
            ])->saveQuietly();
            $this->departments[(int) $row['id']] = $department->id;
            $this->counts['departments']++;
        }
    }

    /** @param  array<string, mixed>  $row */
    private function ticket(array $row): void
    {
        $whmcsId = (int) $row['id'];
        $existing = DB::table('tickets')->where('whmcs_id', $whmcsId)->first();
        $clientId = $this->clientOf((int) $row['userid']);
        $attributes = [
            'subject' => mb_substr($this->text((string) $row['title']) ?: '(' . __('no subject') . ')', 0, 255),
            'status' => $this->status((string) $row['status']),
            'priority' => match ((string) $row['urgency']) {
                'Low' => 'low',
                'High' => 'high',
                default => 'medium',
            },
            'department_id' => $this->departments[(int) $row['did']] ?? null,
            'client_id' => $clientId,
            'guest_name' => $clientId === null ? mb_substr($this->text((string) $row['name']) ?: (string) $row['email'], 0, 255) : null,
            'guest_email' => $clientId === null ? (mb_strtolower(trim((string) $row['email'])) ?: null) : null,
            'opened_via' => 'whmcs',
            'created_at' => $this->date($row['date']) ?? now(),
            'updated_at' => $this->date($row['lastreply'] ?? null) ?? $this->date($row['date']) ?? now(),
        ];
        if ($clientId === null) {
            $this->counts[(int) $row['userid'] > 0 ? 'no_account' : 'guests']++;
        }
        if ($existing !== null) {
            DB::table('tickets')->where('id', $existing->id)->update($attributes);
            $ticketId = (int) $existing->id;
        } else {
            $attributes['whmcs_id'] = $whmcsId;
            $attributes['reference'] = $this->reference((string) ($row['tid'] ?? ''), $whmcsId);
            // The same id when it is free (the full import starts empty), so a number written somewhere still finds it.
            if (!DB::table('tickets')->where('id', $whmcsId)->exists()) {
                $attributes['id'] = $whmcsId;
            }
            $ticketId = (int) DB::table('tickets')->insertGetId($attributes);
        }
        $this->counts['tickets']++;

        $this->message($ticketId, 'ticket:' . $whmcsId, $row, (string) ($row['message'] ?? ''), false);
        foreach ($this->rows('SELECT * FROM tblticketreplies WHERE tid = ? ORDER BY id', [$whmcsId]) as $reply) {
            $this->message($ticketId, 'reply:' . $reply['id'], $reply, (string) $reply['message'], false);
        }
        if ($this->hasTable('tblticketnotes')) {
            foreach ($this->rows('SELECT * FROM tblticketnotes WHERE ticketid = ? ORDER BY id', [$whmcsId]) as $note) {
                $this->message($ticketId, 'note:' . $note['id'], $note + ['userid' => 0], (string) $note['message'], true, (string) ($note['attachments'] ?? ''));
            }
        }
    }

    /** A ticket WHMCS merged into another: its opening joins that ticket, with a note saying from where. */
    private function merged(array $row): void
    {
        $target = DB::table('tickets')->where('whmcs_id', (int) $row['merged_ticket_id'])->value('id');
        if ($target === null) {
            $this->ticket($row); // the ticket it went into was not brought: it comes as itself

            return;
        }
        $this->message((int) $target, 'ticket:' . $row['id'], $row, (string) $row['message'], false);
        $this->message((int) $target, 'merged:' . $row['id'], ['date' => $row['date'], 'admin' => '', 'userid' => 0],
            'Merged in WHMCS from ticket #' . $row['tid'] . ' (' . $this->text((string) $row['title']) . ').', true);
        $this->counts['merged']++;
    }

    /** @param  array<string, mixed>  $row */
    private function message(int $ticketId, string $ref, array $row, string $text, bool $internal, ?string $files = null): void
    {
        $staffId = $this->staffOf((string) ($row['admin'] ?? ''));
        $attributes = [
            'ticket_id' => $ticketId,
            'staff_id' => $staffId,
            'user_id' => $staffId === null ? $this->userOf((int) ($row['userid'] ?? 0)) : null,
            'message' => mb_strcut($this->text($text, multiline: true) ?: '(' . __('no text') . ')', 0, 60_000, 'UTF-8'),
            'via' => 'whmcs',
            'is_internal' => $internal,
            'created_at' => $this->date($row['date'] ?? null) ?? now(),
            'updated_at' => $this->date($row['date'] ?? null) ?? now(),
        ];
        $existing = DB::table('ticket_messages')->where('whmcs_ref', $ref)->value('id');
        if ($existing !== null) {
            DB::table('ticket_messages')->where('id', $existing)->update($attributes);
            $messageId = (int) $existing;
        } else {
            $messageId = (int) DB::table('ticket_messages')->insertGetId($attributes + ['whmcs_ref' => $ref]);
        }
        $this->counts[$internal ? 'notes' : 'messages']++;
        $this->files($messageId, (string) ($files ?? $row['attachment'] ?? ''));
    }

    /** WHMCS's files of a message («123456_name.pdf|…» in its attachments folder), once, under §3 ΣΤ. */
    private function files(int $messageId, string $list): void
    {
        $names = array_values(array_filter(array_map('trim', explode('|', $list))));
        if ($names === [] || DB::table('ticket_message_attachments')->where('ticket_message_id', $messageId)->exists()) {
            return;
        }
        foreach (array_slice($names, 0, Attachments::MAX_COUNT) as $stored) {
            $path = $this->attachments !== null ? rtrim($this->attachments, '/') . '/' . basename($stored) : null;
            if ($path === null || !is_file($path) || filesize($path) > Attachments::MAX_BYTES) {
                $this->counts[$path !== null && is_file($path) ? 'files_refused' : 'files_missing']++;

                continue;
            }
            $content = (string) file_get_contents($path);
            // WHMCS writes «random_original.ext»: the name the customer gave is after the first underscore.
            $name = Attachments::safeName(str_contains($stored, '_') ? substr($stored, strpos($stored, '_') + 1) : $stored);
            if (Attachments::refusal($name, $content) !== null) {
                $this->counts['files_refused']++;

                continue;
            }
            $relative = 'tickets/uploads/' . Str::ulid() . '.' . Attachments::extension($name);
            File::ensureDirectoryExists(storage_path('app/tickets/uploads'));
            file_put_contents(storage_path('app/' . $relative), $content);
            DB::table('ticket_message_attachments')->insert(['uuid' => (string) Str::uuid(), 'ticket_message_id' => $messageId, 'path' => $relative,
                'filename' => $name, 'mime_type' => Attachments::sniff($content), 'filesize' => strlen($content), 'created_at' => now(), 'updated_at' => now()]);
            $this->counts['files']++;
        }
    }

    /** The account of a WHMCS client: the one its login (the same id) owns; none, and it is a guest's. */
    private function clientOf(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }
        $client = DB::table('client_user')->where('user_id', $userId)->whereNotNull('owner_of_client_id')->value('client_id');

        return $client !== null ? (int) $client : null;
    }

    private function userOf(int $userId): ?int
    {
        return $userId > 0 && DB::table('users')->where('id', $userId)->exists() ? $userId : null;
    }

    /** The staff member of a WHMCS admin's name; one no longer here: a disabled member of that name (the reply stays theirs). */
    private function staffOf(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        if (array_key_exists($name, $this->staff)) {
            return $this->staff[$name];
        }
        $id = DB::table('staff')->where(DB::raw("CONCAT(first_name, ' ', last_name)"), $name)->orWhere('first_name', $name)->value('id');

        return $this->staff[$name] = $id !== null ? (int) $id : (int) DB::table('staff')->insertGetId(['first_name' => mb_substr($name, 0, 255), 'last_name' => '',
            'email' => 'whmcs-admin-' . Str::slug($name) . '-' . Str::random(6) . '@invalid.example', 'password' => bcrypt(Str::random(32)), 'active' => false,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function status(string $status): string
    {
        if (!isset(self::STATUSES[$status])) {
            $this->counts['other_status'][$status] = ($this->counts['other_status'][$status] ?? 0) + 1;
        }

        return self::STATUSES[$status] ?? Ticket::STATUS_OPEN;
    }

    /** WHMCS's tid as the number, unless a ticket made here has it: then the tid with -W and the id (counted). */
    private function reference(string $tid, int $whmcsId): string
    {
        $tid = trim($tid) !== '' ? mb_substr(trim($tid), 0, 50) : (string) $whmcsId;
        if (!DB::table('tickets')->where('reference', $tid)->exists()) {
            return $tid;
        }
        $this->counts['reference_taken']++;

        return mb_substr($tid, 0, 40) . '-W' . $whmcsId;
    }

    /** WHMCS's text (kept HTML-escaped) as text: decoded, valid UTF-8, without control characters; one line unless multiline. */
    private function text(string $value, bool $multiline = false): string
    {
        $value = html_entity_decode(mb_scrub($value, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]+/u', $multiline ? '' : ' ', $value);

        return trim($multiline ? $value : (string) preg_replace('/\s+/u', ' ', $value));
    }

    private function date(mixed $value): ?Carbon
    {
        $value = (string) $value;
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tickets per department and status, in WHMCS (merged ones not counted: they joined another) and here (those it
     * brought): they must agree.
     *
     * @return array{whmcs: array<string, int>, mycs: array<string, int>}
     */
    private function parity(): array
    {
        $whmcs = [];
        // merged_ticket_id since WHMCS 7: without it, nothing was merged.
        $merged = $this->hasColumn('tbltickets', 'merged_ticket_id') ? ' WHERE merged_ticket_id = 0 OR merged_ticket_id IS NULL OR merged_ticket_id = id' : '';
        foreach ($this->rows("SELECT did, status, COUNT(*) AS n FROM tbltickets{$merged} GROUP BY did, status") as $row) {
            $key = ($this->departments[(int) $row['did']] ?? 0) . '/' . (self::STATUSES[(string) $row['status']] ?? Ticket::STATUS_OPEN);
            $whmcs[$key] = ($whmcs[$key] ?? 0) + (int) $row['n'];
        }
        $mycs = DB::table('tickets')->whereNotNull('whmcs_id')->selectRaw('department_id, status, COUNT(*) AS n')->groupBy('department_id', 'status')->get()
            ->mapWithKeys(fn ($row) => [(int) $row->department_id . '/' . $row->status => (int) $row->n])->all();
        ksort($whmcs);
        ksort($mycs);

        return ['whmcs' => $whmcs, 'mycs' => $mycs];
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, array $bindings = []): array
    {
        $statement = $this->whmcs->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $this->whmcs->query("SELECT {$column} FROM {$table} LIMIT 1");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            $this->whmcs->query("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
