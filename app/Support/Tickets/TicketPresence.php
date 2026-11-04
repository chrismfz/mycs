<?php

namespace App\Support\Tickets;

use App\Models\Staff;
use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Who of the staff views a ticket and who writes a reply in it (docs/tickets.md §6ν). Unlike WHMCS's «X is replying» it
 * never sticks: each open page of a ticket beats, and what it says lasts only while it beats. A row per page (its
 * Livewire id), so two tabs of one member do not overwrite each other. Staff's only: nothing of it reaches the customer.
 */
class TicketPresence
{
    /** A page beats this often while in front, and every BACKGROUND_BEAT seconds behind another tab. */
    public const BEAT = 10;

    public const BACKGROUND_BEAT = 30;

    /** Seconds a page counts without a beat: a missed background beat and then some. */
    public const ALIVE = 45;

    /**
     * A page holding a reply counts longer without a beat: behind another tab for over five minutes, browsers fire its
     * timer about once a minute (review). Viewing needs a tab in front, so it keeps ALIVE.
     */
    public const DRAFT_ALIVE = 150;

    /** «Writes a reply»: text in the reply box and typing in the last two minutes; older text is an unsent reply. */
    public const WRITING = 120;

    /** Rows of pages gone without a word (a crash, a lost beacon) go at the next beat after an hour. */
    public const PRUNE_AFTER = 3600;

    public const WRITING_STATE = 'writing';

    public const DRAFT_STATE = 'draft';

    public const VIEWING_STATE = 'viewing';

    /**
     * A beat of one page. $viewing: the tab is in front and someone moved in it lately; $typed: the reply box changed
     * since the last beat; $hasDraft: the reply box holds text or a file (read by the server from the form).
     */
    public static function beat(Ticket $ticket, Staff $staff, string $page, bool $viewing, bool $typed, bool $hasDraft): void
    {
        $now = now();
        $row = DB::table('ticket_presences')->where('page', $page)->first(['ticket_id', 'staff_id', 'typed_at', 'has_draft']);
        // A page is one member's on one ticket: a beat for another is not this page's.
        if ($row !== null && ((int) $row->ticket_id !== $ticket->id || (int) $row->staff_id !== $staff->id)) {
            return;
        }
        // Text that just appeared (typed, pasted, a predefined reply, an MCP draft) counts as typed now.
        $typedAt = match (true) {
            !$hasDraft => null,
            $typed || $row === null || !$row->has_draft || $row->typed_at === null => $now,
            default => Carbon::parse($row->typed_at),
        };

        DB::table('ticket_presences')->upsert([[
            'page' => $page,
            'ticket_id' => $ticket->id,
            'staff_id' => $staff->id,
            'alive_at' => $now,
            'viewing_at' => $viewing ? $now : null,
            'typed_at' => $typedAt,
            'has_draft' => $hasDraft,
        ]], ['page'], ['alive_at', 'viewing_at', 'typed_at', 'has_draft']);

        // At most once every ten minutes, not a range delete on every beat of every tab (review).
        if (Cache::add('ticket-presences:pruned', true, 600)) {
            DB::table('ticket_presences')->where('alive_at', '<', $now->copy()->subSeconds(self::PRUNE_AFTER))->delete();
        }
    }

    /** The page is gone (closed, left, or its reply sent and the box empty): its row goes now, not when it expires. */
    public static function leave(Staff $staff, string $page): void
    {
        DB::table('ticket_presences')->where('page', $page)->where('staff_id', $staff->id)->delete();
    }

    /**
     * Who else is on these tickets now, per ticket and per member, the strongest state of their pages: writing, then an
     * unsent reply, then viewing. A page with nothing to say (behind another tab, idle, no text) says nothing.
     *
     * @param  list<int>  $ticketIds  null: every ticket
     * @return array<int, list<array{staff_id: int, name: string, state: string, typed_at: ?Carbon}>>
     */
    public static function on(?array $ticketIds, ?int $exceptStaffId = null): array
    {
        $now = now();
        $rows = DB::table('ticket_presences')
            ->join('staff', 'staff.id', '=', 'ticket_presences.staff_id')
            ->where('ticket_presences.alive_at', '>=', $now->copy()->subSeconds(self::DRAFT_ALIVE))
            ->when($ticketIds !== null, fn ($query) => $query->whereIn('ticket_presences.ticket_id', $ticketIds))
            ->when($exceptStaffId !== null, fn ($query) => $query->where('ticket_presences.staff_id', '!=', $exceptStaffId))
            ->get(['ticket_presences.*', 'staff.first_name', 'staff.last_name', 'staff.email']);

        $rank = [self::WRITING_STATE => 3, self::DRAFT_STATE => 2, self::VIEWING_STATE => 1];
        $on = [];
        foreach ($rows as $row) {
            $typedAt = $row->typed_at !== null ? Carbon::parse($row->typed_at) : null;
            if (!$row->has_draft && Carbon::parse($row->alive_at)->lt($now->copy()->subSeconds(self::ALIVE))) {
                continue;
            }
            $state = match (true) {
                (bool) $row->has_draft && $typedAt !== null && $typedAt->gte($now->copy()->subSeconds(self::WRITING)) => self::WRITING_STATE,
                (bool) $row->has_draft => self::DRAFT_STATE,
                $row->viewing_at !== null && Carbon::parse($row->viewing_at)->gte($now->copy()->subSeconds(self::ALIVE)) => self::VIEWING_STATE,
                default => null,
            };
            if ($state === null) {
                continue;
            }
            $current = $on[(int) $row->ticket_id][(int) $row->staff_id] ?? null;
            if ($current === null || $rank[$state] > $rank[$current['state']]
                || ($rank[$state] === $rank[$current['state']] && $typedAt?->gt($current['typed_at'] ?? Carbon::createFromTimestamp(0)))) {
                $on[(int) $row->ticket_id][(int) $row->staff_id] = [
                    'staff_id' => (int) $row->staff_id,
                    'name' => trim($row->first_name . ' ' . $row->last_name) ?: (string) $row->email,
                    'state' => $state,
                    'typed_at' => $typedAt,
                ];
            }
        }

        return array_map(function (array $members) use ($rank) {
            $members = array_values($members);
            usort($members, fn (array $a, array $b) => [$rank[$b['state']], $a['name']] <=> [$rank[$a['state']], $b['name']]);

            return $members;
        }, $on);
    }

    /** @return list<array{staff_id: int, name: string, state: string, typed_at: ?Carbon}> */
    public static function onTicket(Ticket $ticket, ?int $exceptStaffId = null): array
    {
        return self::on([$ticket->id], $exceptStaffId)[$ticket->id] ?? [];
    }

    /**
     * Messages of others since $seenId: the customer's, a CC's, another member's, notes of colleagues too. Never the
     * member's own (from another tab, say).
     *
     * @return Collection<int, TicketMessage>
     */
    public static function newerThan(Ticket $ticket, int $seenId, int $staffId): Collection
    {
        return $ticket->messages()->with(['user', 'staff'])->where('id', '>', $seenId)
            ->where(fn ($query) => $query->whereNull('staff_id')->orWhere('staff_id', '!=', $staffId))
            ->orderBy('id')->get();
    }

    public static function lastMessageId(Ticket $ticket): int
    {
        return (int) $ticket->messages()->max('id');
    }
}
