<?php

namespace App\Services\Import;

use App\Models\CannedReply;
use App\Models\CannedReplyCategory;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\Tickets\CannedReplyExpander;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use MyCS\TicketMail\Attachments;
use PDO;
use Throwable;

/**
 * WHMCS's tickets (docs/tickets.md §5α, §6ζ, PR Δ), after its accounts are imported (an account keeps its WHMCS client
 * id). Read-only on WHMCS. Re-runnable: a ticket is found by its `whmcs_id`, a message by its `whmcs_ref`; MyCS's work
 * is never undone:
 *  - a ticket's fields follow WHMCS only when WHMCS changed them since the last run (`whmcs_hash`), never its owner
 *    (staff may have linked a guest's ticket to an account); a message is brought once;
 *  - a ticket or message deleted in MyCS stays deleted (`ticket_whmcs_tombstones`).
 * Owners (§3 Δ): a client's ticket goes to the WHMCS client's own account, and only when its address matches one of that
 * account's (an id alone proves nothing: a MyCS signup may hold it); anything else is a guest's, with the client's or
 * ticket's name and address, never an account found by its email, never vouched for.
 * Messages in the order WHMCS wrote them: the opening, replies, staff's notes (internal), and those of the tickets WHMCS
 * merged into it (to the end of a chain), internal when another requester wrote them. Files from a copy of WHMCS's
 * attachments folder under §3 ΣΤ, every one counted. No event fires (no mail, no status moved, no notice). One ticket that
 * fails is counted and skipped; the others go on. --dry-run: the same numbers, nothing kept.
 * Predefined replies and their categories (§6ι): brought once and followed while not edited here; WHMCS's merge fields
 * ([NAME], …) as our tokens; one deleted here stays deleted.
 */
final class WhmcsTicketImport
{
    /** WHMCS's statuses; any other (a custom one) is open, and counted. */
    public const STATUSES = [
        'Open' => Ticket::STATUS_OPEN,
        'Answered' => Ticket::STATUS_ANSWERED,
        'Customer-Reply' => Ticket::STATUS_CUSTOMER_REPLY,
        'On Hold' => Ticket::STATUS_ON_HOLD,
        'In Progress' => Ticket::STATUS_ON_HOLD,
        'Closed' => Ticket::STATUS_CLOSED,
    ];

    private const CHUNK = 500;

    /** Files of one message at most (WHMCS has no limit; ours by mail is 5): the rest counted, never silently dropped. */
    private const MAX_FILES = 50;

    private const MAX_TEXT_BYTES = 60_000;

    /** @var array<string, mixed> */
    private array $counts = ['departments' => 0, 'tickets' => 0, 'unchanged' => 0, 'guests' => 0, 'no_account' => 0, 'messages' => 0, 'notes' => 0,
        'merged' => 0, 'canned_replies' => 0, 'files' => 0, 'files_missing' => 0, 'files_refused' => 0, 'files_removed' => 0, 'truncated' => 0, 'reference_taken' => 0,
        'deleted_here' => 0, 'failed' => 0, 'errors' => [], 'other_status' => []];

    /** @var array<int, int> WHMCS department id => ours */
    private array $departments = [];

    /** @var array<int, int> a merged ticket's WHMCS id => the ticket it ended in (its chain followed) */
    private array $roots = [];

    /** @var array<int, list<array<string, mixed>>> WHMCS ticket id => the rows of the tickets merged into it */
    private array $mergedInto = [];

    /** @var array<string, int|null> */
    private array $staff = [];

    /** @var list<string> files written in the ticket's open transaction (deleted if it rolls back) */
    private array $written = [];

    private int $order = 0;

    private ?bool $notes = null;

    private ?bool $clients = null;

    public function __construct(private readonly PDO $whmcs, private readonly ?string $attachments = null, private readonly bool $dryRun = false) {}

    /** @return array<string, mixed> what was brought, and the parity (WHMCS and MyCS, per department and status) */
    public function run(): array
    {
        if (!$this->hasTable('tbltickets')) {
            return $this->counts;
        }
        if ($this->dryRun) {
            DB::beginTransaction();
        }
        try {
            $this->departments();
            $this->cannedReplies();
            $this->merges();
            $last = 0;
            do {
                $rows = $this->rows('SELECT * FROM tbltickets WHERE id > ? ORDER BY id LIMIT ' . self::CHUNK, [$last]);
                foreach ($rows as $row) {
                    $last = (int) $row['id'];
                    if (!isset($this->roots[$last])) {
                        $this->safely($row); // a merged one joins the ticket its chain ends in
                    }
                }
            } while (count($rows) === self::CHUNK);
            $this->counts['parity'] = $this->parity();
        } finally {
            if ($this->dryRun) {
                DB::rollBack();
            }
        }

        return $this->counts;
    }

    /**
     * One ticket in its own transaction: a failure is counted (with its WHMCS id), its files deleted, the run goes on.
     *
     * @param  array<string, mixed>  $row
     */
    private function safely(array $row): void
    {
        $this->written = [];
        try {
            DB::transaction(fn () => $this->ticket($row));
        } catch (Throwable $e) {
            foreach ($this->written as $path) {
                @unlink(storage_path('app/' . $path));
            }
            $this->counts['failed']++;
            $this->counts['errors'][] = 'Ticket ' . $row['id'] . ': ' . mb_substr($e->getMessage(), 0, 300);
        }
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
                $department = TicketDepartment::query()->whereNull('whmcs_id')->whereRaw('LOWER(name) = ?', [mb_strtolower($this->text((string) $row['name']))])->first()
                    ?? $department;
            }
            $department->forceFill([
                'whmcs_id' => (int) $row['id'],
                'name' => mb_substr($this->text((string) $row['name']) ?: 'Department ' . $row['id'], 0, 255),
                'sort' => max(0, min(65535, (int) ($row['order'] ?? 0))),
                'hidden' => (bool) ($row['hidden'] ?? false),
                'clients_only' => (bool) ($row['clientsonly'] ?? false),
                'email' => $this->email((string) ($row['email'] ?? '')),
            ])->saveQuietly();
            $this->departments[(int) $row['id']] = $department->id;
            $this->counts['departments']++;
        }
    }

    /** WHMCS's predefined replies, by category (WHMCS's sub-categories as their own, flat). */
    private function cannedReplies(): void
    {
        if (!$this->hasTable('tblticketpredefinedreplies')) {
            return;
        }
        $categories = [];
        if ($this->hasTable('tblticketpredefinedcats')) {
            foreach ($this->rows('SELECT * FROM tblticketpredefinedcats ORDER BY id') as $row) {
                $category = $this->keep(CannedReplyCategory::class, 'canned_category:' . $row['id'], (int) $row['id'],
                    ['name' => mb_substr($this->text((string) $row['name']) ?: 'Category ' . $row['id'], 0, 255), 'sort' => min(65535, (int) $row['id'])]);
                $categories[(int) $row['id']] = $category?->id;
            }
        }
        foreach ($this->rows('SELECT * FROM tblticketpredefinedreplies ORDER BY id') as $row) {
            $body = strtr($this->text((string) $row['reply'], multiline: true), CannedReplyExpander::WHMCS);
            if (strlen($body) > self::MAX_TEXT_BYTES) {
                $body = mb_strcut($body, 0, self::MAX_TEXT_BYTES);
                $this->counts['truncated']++;
            }
            $reply = $this->keep(CannedReply::class, 'canned:' . $row['id'], (int) $row['id'], [
                'canned_reply_category_id' => $categories[(int) $row['catid']] ?? null,
                'title' => mb_substr($this->text((string) $row['name']) ?: 'Reply ' . $row['id'], 0, 255),
                'body' => $body,
                'sort' => min(65535, (int) $row['id']),
            ]);
            $this->counts['canned_replies'] += $reply !== null ? 1 : 0;
        }
    }

    /**
     * One row of WHMCS's kept here by its whmcs_id: made if missing, followed while it holds what the import last wrote
     * (`whmcs_hash`: any other value was edited here, whatever the second), never brought back once deleted here.
     *
     * @template T of CannedReply|CannedReplyCategory
     *
     * @param  class-string<T>  $model
     * @param  array<string, mixed>  $values
     * @return T|null
     */
    private function keep(string $model, string $tombstone, int $whmcsId, array $values): ?object
    {
        if ($this->tombstoned($tombstone)) {
            return null;
        }
        $record = $model::query()->firstOrNew(['whmcs_id' => $whmcsId]);
        $hash = fn (array $row) => hash('sha256', (string) json_encode(array_map(fn ($value) => $value === null ? null : (string) $value, $row)));
        if ($record->exists && $record->whmcs_hash !== $hash($record->only(array_keys($values)))) {
            return $record; // edited here: MyCS's wins
        }
        $record->forceFill(['whmcs_id' => $whmcsId, 'whmcs_hash' => $hash($values)] + $values)->saveQuietly();

        return $record;
    }

    /** WHMCS's merges, each to the end of its chain (B into A, A into C: B ends in C); one whose end is gone comes as itself. */
    private function merges(): void
    {
        if (!$this->hasColumn('tbltickets', 'merged_ticket_id')) {
            return;
        }
        $into = [];
        foreach ($this->rows('SELECT id, merged_ticket_id FROM tbltickets WHERE merged_ticket_id > 0 AND merged_ticket_id <> id') as $row) {
            $into[(int) $row['id']] = (int) $row['merged_ticket_id'];
        }
        foreach (array_keys($into) as $id) {
            $root = $id;
            $seen = [];
            while (isset($into[$root]) && !isset($seen[$root])) {
                $seen[$root] = true;
                $root = $into[$root];
            }
            if ($root !== $id && !isset($into[$root]) && $this->rows('SELECT id FROM tbltickets WHERE id = ?', [$root]) !== []) {
                $this->roots[$id] = $root;
            }
        }
        foreach (array_chunk(array_keys($this->roots), 500) as $ids) {
            foreach ($this->rows('SELECT * FROM tbltickets WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $row) {
                $this->mergedInto[$this->roots[(int) $row['id']]][] = $row;
            }
        }
    }

    /** @param  array<string, mixed>  $row */
    private function ticket(array $row): void
    {
        $whmcsId = (int) $row['id'];
        if ($this->tombstoned('ticket:' . $whmcsId)) {
            $this->counts['deleted_here']++;

            return;
        }
        $fields = [
            'subject' => mb_substr($this->text((string) $row['title']) ?: '(' . __('no subject') . ')', 0, 255),
            'status' => $this->status((string) $row['status']),
            'priority' => match ((string) $row['urgency']) {
                'Low' => 'low',
                'High' => 'high',
                default => 'medium',
            },
            'department_id' => $this->departments[(int) $row['did']] ?? null,
        ];
        $hash = sha1((string) json_encode($fields) . '|' . ($row['lastreply'] ?? ''));
        $existing = DB::table('tickets')->where('whmcs_id', $whmcsId)->first();
        if ($existing === null) {
            $owner = $this->owner($row);
            $attributes = $fields + $owner + [
                'whmcs_id' => $whmcsId,
                'whmcs_hash' => $hash,
                'reference' => $this->reference((string) ($row['tid'] ?? ''), $whmcsId),
                'opened_via' => 'whmcs',
                // WHMCS never checked who wrote to it (its form, its piping): a guest's is not vouched for (§3 Β).
                'sender_vouched' => $owner['client_id'] === null ? false : null,
                'created_at' => $this->date($row['date']) ?? now(),
                'updated_at' => $this->date($row['lastreply'] ?? null) ?? $this->date($row['date']) ?? now(),
            ];
            // The same id when it is free (the full import starts empty), so a number written somewhere still finds it.
            if (!DB::table('tickets')->where('id', $whmcsId)->exists()) {
                $attributes['id'] = $whmcsId;
            }
            $ticketId = (int) DB::table('tickets')->insertGetId($attributes);
            $clientId = $owner['client_id'];
            if ($clientId === null) {
                $this->counts[(int) $row['userid'] > 0 ? 'no_account' : 'guests']++;
            }
        } elseif ($existing->merged_into_id !== null) {
            // Merged here (§6κ): left closed; what WHMCS gets since goes to the ticket it was merged into.
            $survivor = Ticket::query()->findOrFail($existing->id)->survivor();
            $ticketId = $survivor->id;
            $clientId = $survivor->client_id;
            $this->counts['unchanged']++;
        } else {
            $ticketId = (int) $existing->id;
            $clientId = $existing->client_id !== null ? (int) $existing->client_id : null;
            // Only what WHMCS changed since the last run; never who owns it (staff may have linked it to an account).
            if ($existing->whmcs_hash !== $hash) {
                DB::table('tickets')->where('id', $ticketId)->update($fields + ['whmcs_hash' => $hash, 'updated_at' => $this->date($row['lastreply'] ?? null) ?? now()]);
            } else {
                $this->counts['unchanged']++;
            }
        }
        $this->counts['tickets']++;

        // Its messages and those of the tickets merged into it, in the order they were written.
        $items = $this->items($row, false);
        foreach ($this->mergedInto[$whmcsId] ?? [] as $merged) {
            // Another requester's words never become visible to this ticket's requester.
            $foreign = !$this->sameRequester($merged, $row);
            $items = array_merge($items, $this->items($merged, $foreign));
            $items[] = ['ref' => 'merged:' . $merged['id'], 'date' => $merged['date'], 'admin' => '', 'userid' => 0, 'internal' => true, 'files' => '',
                'removed' => false, 'order' => $this->order++, 'text' => 'Merged in WHMCS from ticket #' . $merged['tid'] . ' (' . $this->text((string) $merged['title']) . ')'
                    . ($foreign ? ', of another requester: its messages are internal.' : '.')];
            $this->counts['merged']++;
        }
        usort($items, fn (array $a, array $b) => [$this->date($a['date'])?->getTimestamp() ?? 0, $a['order']] <=> [$this->date($b['date'])?->getTimestamp() ?? 0, $b['order']]);
        foreach ($items as $item) {
            $this->message($ticketId, $clientId, $item);
        }
    }

    /**
     * A ticket's messages as items to insert: its opening, its replies, staff's notes.
     *
     * @param  array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function items(array $row, bool $internal): array
    {
        $id = (int) $row['id'];
        $items = [['ref' => 'ticket:' . $id, 'date' => $row['date'], 'admin' => (string) ($row['admin'] ?? ''), 'userid' => (int) $row['userid'],
            'internal' => $internal, 'text' => (string) ($row['message'] ?? ''), 'files' => (string) ($row['attachment'] ?? ''),
            'removed' => (bool) ($row['attachments_removed'] ?? false), 'order' => $this->order++]];
        foreach ($this->rows('SELECT * FROM tblticketreplies WHERE tid = ? ORDER BY id', [$id]) as $reply) {
            $items[] = ['ref' => 'reply:' . $reply['id'], 'date' => $reply['date'], 'admin' => (string) ($reply['admin'] ?? ''), 'userid' => (int) ($reply['userid'] ?? 0),
                'internal' => $internal, 'text' => (string) ($reply['message'] ?? ''), 'files' => (string) ($reply['attachment'] ?? ''),
                'removed' => (bool) ($reply['attachments_removed'] ?? false), 'order' => $this->order++];
        }
        if ($this->notes ??= $this->hasTable('tblticketnotes')) {
            foreach ($this->rows('SELECT * FROM tblticketnotes WHERE ticketid = ? ORDER BY id', [$id]) as $note) {
                $items[] = ['ref' => 'note:' . $note['id'], 'date' => $note['date'], 'admin' => (string) ($note['admin'] ?? ''), 'userid' => 0, 'internal' => true,
                    'text' => (string) ($note['message'] ?? ''), 'files' => (string) ($note['attachments'] ?? ''),
                    'removed' => (bool) ($note['attachments_removed'] ?? false), 'order' => $this->order++];
            }
        }

        return $items;
    }

    /** @param  array<string, mixed>  $item */
    private function message(int $ticketId, ?int $clientId, array $item): void
    {
        if ($this->tombstoned($item['ref'])) {
            $this->counts['deleted_here']++;

            return;
        }
        $existing = DB::table('ticket_messages')->where('whmcs_ref', $item['ref'])->value('id');
        if ($existing === null) {
            $staffId = $this->staffOf((string) $item['admin']);
            $text = $this->text((string) $item['text'], multiline: true);
            if (strlen($text) > self::MAX_TEXT_BYTES) {
                $this->counts['truncated']++;
            }
            $existing = DB::table('ticket_messages')->insertGetId([
                'ticket_id' => $ticketId,
                'staff_id' => $staffId,
                'user_id' => $staffId === null ? $this->userOf((int) $item['userid'], $clientId) : null,
                'message' => mb_strcut($text !== '' ? $text : '(' . __('no text') . ')', 0, self::MAX_TEXT_BYTES, 'UTF-8'),
                'via' => 'whmcs',
                'is_internal' => (bool) $item['internal'],
                'whmcs_ref' => $item['ref'],
                'created_at' => $this->date($item['date']) ?? now(),
                'updated_at' => $this->date($item['date']) ?? now(),
            ]);
        }
        $this->counts[$item['internal'] ? 'notes' : 'messages']++;
        $this->files((int) $existing, (string) $item['files'], (bool) $item['removed']);
    }

    /** WHMCS's files of a message («123456_name.pdf|…»), each once (by its name), under §3 ΣΤ; every one counted. */
    private function files(int $messageId, string $list, bool $removed): void
    {
        $names = array_values(array_filter(array_map('trim', explode('|', $list))));
        if ($names === []) {
            return;
        }
        if ($removed) {
            $this->counts['files_removed'] += count($names); // WHMCS removed them (staff, or its pruning): not «missing»

            return;
        }
        $have = DB::table('ticket_message_attachments')->where('ticket_message_id', $messageId)->pluck('filename')->all();
        foreach ($names as $index => $stored) {
            $name = Attachments::safeName(str_contains($stored, '_') ? substr($stored, strpos($stored, '_') + 1) : $stored);
            if (in_array($name, $have, true)) {
                continue; // brought on an earlier run
            }
            if ($index >= self::MAX_FILES) {
                $this->counts['files_refused']++;

                continue;
            }
            $path = $this->attachments !== null ? rtrim($this->attachments, '/') . '/' . basename($stored) : null;
            if ($path === null || (!is_file($path) && !is_link($path))) {
                $this->counts['files_missing']++;

                continue;
            }
            // Never a file outside the folder: no link is followed, no size over the limit is read.
            $root = realpath((string) $this->attachments);
            $real = realpath($path);
            if (is_link($path) || $real === false || $root === false || !str_starts_with($real, rtrim($root, '/') . '/') || filesize($real) > Attachments::MAX_BYTES) {
                $this->counts['files_refused']++;

                continue;
            }
            $content = (string) file_get_contents($real);
            if (Attachments::refusal($name, $content) !== null) {
                $this->counts['files_refused']++;

                continue;
            }
            $this->counts['files']++;
            $have[] = $name;
            if ($this->dryRun) {
                continue;
            }
            $relative = 'tickets/uploads/' . Str::ulid() . '.' . Attachments::extension($name);
            File::ensureDirectoryExists(storage_path('app/tickets/uploads'));
            file_put_contents(storage_path('app/' . $relative), $content);
            $this->written[] = $relative;
            DB::table('ticket_message_attachments')->insert(['uuid' => (string) Str::uuid(), 'ticket_message_id' => $messageId, 'path' => $relative,
                'filename' => $name, 'mime_type' => Attachments::sniff($content), 'filesize' => strlen($content), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Who owns a WHMCS ticket here: the WHMCS client's account (the same id) when its address is one of that account's;
     * anything else a guest's, by the ticket's (or the client's) name and address.
     *
     * @param  array<string, mixed>  $row
     * @return array{client_id: ?int, guest_name: ?string, guest_email: ?string}
     */
    private function owner(array $row): array
    {
        $userId = (int) $row['userid'];
        $client = $userId > 0 && ($this->clients ??= $this->hasTable('tblclients'))
            ? ($this->rows('SELECT * FROM tblclients WHERE id = ?', [$userId])[0] ?? null) : null;
        if ($userId > 0 && $this->isTheAccount($userId, $client)) {
            return ['client_id' => $userId, 'guest_name' => null, 'guest_email' => null];
        }
        $name = $this->text((string) $row['name']) ?: $this->text(trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? '')));
        $email = $this->email((string) $row['email']) ?? $this->email((string) ($client['email'] ?? ''));

        return ['client_id' => null, 'guest_name' => mb_substr($name !== '' ? $name : ($email ?? __('Guest')), 0, 255), 'guest_email' => $email];
    }

    /**
     * The account of a WHMCS client is the one of its id (accounts keep it), and it is that client's: WHMCS's address of
     * the client is one of the account's logins or contacts. Without WHMCS's client row the id is all there is.
     *
     * @param  array<string, mixed>|null  $client
     */
    private function isTheAccount(int $clientId, ?array $client): bool
    {
        if (!DB::table('clients')->where('id', $clientId)->exists()) {
            return false;
        }
        if ($client === null) {
            return true;
        }
        $email = $this->email((string) ($client['email'] ?? ''));
        if ($email === null) {
            return false;
        }
        $logins = DB::table('client_user')->join('users', 'users.id', '=', 'client_user.user_id')->where('client_user.client_id', $clientId)->pluck('users.email');
        $contacts = DB::table('contacts')->where('client_id', $clientId)->pluck('email');

        return $logins->merge($contacts)->map(fn ($address) => mb_strtolower((string) $address))->contains($email);
    }

    /**
     * The login a customer's message is of: on an account's ticket only, the WHMCS client's own login (the full import
     * gives it the client's id) when it is one of the account's, else the account's owner. Never another account's.
     */
    private function userOf(int $userId, ?int $clientId): ?int
    {
        if ($userId <= 0 || $clientId === null) {
            return null;
        }
        if (DB::table('client_user')->where('client_id', $clientId)->where('user_id', $userId)->whereNull('revoked_at')->exists()) {
            return $userId;
        }
        $owner = DB::table('client_user')->where('client_id', $clientId)->whereNotNull('owner_of_client_id')->value('user_id');

        return $owner !== null ? (int) $owner : null;
    }

    /**
     * Two WHMCS tickets of the same requester: the same client, or the same guest address.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function sameRequester(array $a, array $b): bool
    {
        if ((int) $a['userid'] > 0 || (int) $b['userid'] > 0) {
            return (int) $a['userid'] === (int) $b['userid'];
        }
        $email = $this->email((string) $a['email']);

        return $email !== null && $email === $this->email((string) $b['email']);
    }

    /** The staff member of a WHMCS admin's name: by full name, by first name only when one has it; one no longer here: a disabled member of that name. */
    private function staffOf(string $name): ?int
    {
        $name = $this->text($name);
        if ($name === '') {
            return null;
        }
        if (array_key_exists($name, $this->staff)) {
            return $this->staff[$name];
        }
        $id = DB::table('staff')->where(DB::raw("CONCAT(first_name, ' ', last_name)"), $name)->value('id');
        if ($id === null) {
            $byFirst = DB::table('staff')->where('first_name', $name)->limit(2)->pluck('id');
            $id = $byFirst->count() === 1 ? $byFirst->first() : null;
        }

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
        $tid = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $this->text($tid));
        $tid = mb_substr($tid !== '' ? $tid : (string) $whmcsId, 0, 50);
        if (!DB::table('tickets')->where('reference', $tid)->exists()) {
            return $tid;
        }
        $this->counts['reference_taken']++;

        return mb_substr($tid, 0, 40) . '-W' . $whmcsId;
    }

    private function tombstoned(string $ref): bool
    {
        return DB::table('ticket_whmcs_tombstones')->where('ref', $ref)->exists();
    }

    /** An address as stored: lower case, valid, at most 255; else none. */
    private function email(string $value): ?string
    {
        $value = mb_strtolower(trim(mb_scrub($value, 'UTF-8')));

        return $value !== '' && strlen($value) <= 255 && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    /** WHMCS's text (kept HTML-escaped) as text: decoded, valid UTF-8, without control characters; one line unless multiline. */
    private function text(string $value, bool $multiline = false): string
    {
        $value = html_entity_decode(mb_scrub($value, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F\p{Cf}]+/u', $multiline ? '' : ' ', $value);

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
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Tickets per department and status, in WHMCS (those merged into another not counted: they joined it; those deleted
     * here neither) and here (those it brought): they must agree.
     *
     * @return array{whmcs: array<string, int>, mycs: array<string, int>}
     */
    private function parity(): array
    {
        $whmcs = [];
        foreach ($this->rows('SELECT id, did, status FROM tbltickets') as $row) {
            if (isset($this->roots[(int) $row['id']]) || $this->tombstoned('ticket:' . $row['id'])) {
                continue;
            }
            $key = ($this->departments[(int) $row['did']] ?? 0) . '/' . (self::STATUSES[(string) $row['status']] ?? Ticket::STATUS_OPEN);
            $whmcs[$key] = ($whmcs[$key] ?? 0) + 1;
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
        } catch (Throwable) {
            return false;
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            $this->whmcs->query("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
