<?php

namespace App\Support;

use App\Models\Staff;
use App\Models\StaffMessage;
use App\Models\StaffThread;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The staff's own chat (docs/livechat.md §7γ): the shared room, and private threads of two. Who may read and write is
 * checked here, on every call, not only when the page opens (F7, F23). A private thread is read by its two members
 * only: membership decides, never a role, so not even an admin reads it from the admin.
 */
class StaffChat
{
    /** Messages a member may send a minute (§3 Ε). */
    public const PER_MINUTE = 20;

    /** The latest messages a poll reads: the whole window each time, so a message committed late is never skipped (F15). */
    public const WINDOW = 100;

    /** The badge counts up to this many; more show as «99+» (review of #373: a member who never reads the room). */
    public const UNREAD_CAP = 99;

    public const DIRECT = 'direct';

    public static function canView(?Staff $staff): bool
    {
        // Past the mandatory 2FA set-up too, as for «Staff online» (review of #373).
        return $staff !== null && StaffPresence::mayBeOnline($staff) && $staff->hasPermission('admin.staff_chat.view');
    }

    /**
     * Someone a member may write to: active, with a role and the chat. Not yet past the mandatory 2FA set-up is fine:
     * they read it once they are (owner 2026-10-23: new colleagues could not be messaged before their first login).
     */
    public static function reachable(Staff $peer): bool
    {
        return $peer->active && $peer->role_id !== null && $peer->hasPermission('admin.staff_chat.view');
    }

    public static function canPost(?Staff $staff): bool
    {
        return self::canView($staff) && $staff?->hasPermission('admin.staff_chat.post');
    }

    public static function room(): StaffThread
    {
        return self::thread(StaffThread::ROOM, StaffThread::ROOM);
    }

    /**
     * The one thread of two members, made the first time either opens it (one row per pair: `<low id>-<high id>`).
     * Null unless both may use the chat and they are two different people.
     */
    public static function direct(Staff $me, Staff $peer): ?StaffThread
    {
        if ($me->id === $peer->id || !self::canView($me) || !self::reachable($peer)) {
            return null;
        }
        $thread = self::thread(self::DIRECT, self::pairKey($me->id, $peer->id));
        // Its two members once, not on every poll (review of #377).
        if ($thread->wasRecentlyCreated || DB::table('staff_thread_members')->where('thread_id', $thread->id)->count() < 2) {
            foreach ([$me->id, $peer->id] as $id) {
                DB::table('staff_thread_members')->insertOrIgnore(['thread_id' => $thread->id, 'staff_id' => $id, 'last_read_message_id' => 0]);
            }
        }

        return $thread;
    }

    /**
     * The member's thread with someone who can no longer use the chat (left, or lost the permission): read-only, its
     * history stays (§7γ), and nothing is made. Null when the two never talked.
     */
    public static function formerDirect(Staff $me, int $peerId): ?StaffThread
    {
        if ($me->id === $peerId) {
            return null;
        }
        $thread = StaffThread::query()->where('direct_key', self::pairKey($me->id, $peerId))->first();

        return $thread !== null && self::isMember($me, $thread) ? $thread : null;
    }

    /**
     * Everyone the member has a private thread with, by id, whether or not they can still use the chat: so a member
     * without «Staff online», or one whose peer left, still finds the thread (review of #377).
     *
     * @return array<int, Staff>
     */
    public static function peers(Staff $staff): array
    {
        if (!self::canView($staff)) {
            return [];
        }
        $ids = DB::table('staff_thread_members as me')
            ->join('staff_threads as t', 't.id', '=', 'me.thread_id')
            ->join('staff_thread_members as peer', fn ($join) => $join->on('peer.thread_id', '=', 'me.thread_id')->on('peer.staff_id', '!=', 'me.staff_id'))
            ->where('me.staff_id', $staff->id)->where('t.kind', self::DIRECT)
            // Threads someone wrote in: merely opening one tells the other member nothing (re-review of #377).
            ->whereExists(fn ($query) => $query->from('staff_messages')->whereColumn('staff_messages.thread_id', 't.id'))
            ->pluck('peer.staff_id');

        return Staff::query()->whereKey($ids)->get()->keyBy('id')->all();
    }

    private static function pairKey(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }

    /** The room for everyone who may use the chat; a private thread for its two members only. */
    public static function isMember(Staff $staff, StaffThread $thread): bool
    {
        if (!self::canView($staff)) {
            return false;
        }

        return $thread->kind === StaffThread::ROOM
            || DB::table('staff_thread_members')->where(['thread_id' => $thread->id, 'staff_id' => $staff->id])->exists();
    }

    /** @return Collection<int, StaffMessage> the latest messages, oldest first */
    public static function messages(StaffThread $thread): Collection
    {
        return $thread->messages()->with('staff:id,first_name,last_name,email')
            ->orderByDesc('id')->limit(self::WINDOW)->get()->reverse()->values();
    }

    /** Null when sent; else what to tell the member. */
    public static function post(Staff $staff, StaffThread $thread, string $text): ?string
    {
        if (!self::canPost($staff) || !self::isMember($staff, $thread)) {
            return __('You may not write here.');
        }
        // A private thread whose other member left (or lost the chat) takes no more messages; its history stays.
        if ($thread->kind === self::DIRECT && self::peerOf($staff, $thread)?->id === null) {
            return __('This member can no longer receive messages.');
        }
        $body = ChatText::clean($text);
        if ($body === null) {
            return __('The message is empty.');
        }
        // Counted first, then checked: many sends in one request stop at the limit (F13). One limit for all threads.
        $key = 'staff-chat:' . $staff->id;
        RateLimiter::hit($key, 60);
        if (RateLimiter::attempts($key) > self::PER_MINUTE) {
            return __('Too many messages, wait a moment.');
        }

        $message = $thread->messages()->create(['staff_id' => $staff->id, 'body' => $body]);
        self::markRead($staff, $thread, $message->id);

        return null;
    }

    /** The other member of a private thread, while they may still use the chat. */
    public static function peerOf(Staff $staff, StaffThread $thread): ?Staff
    {
        $peerId = DB::table('staff_thread_members')->where('thread_id', $thread->id)->where('staff_id', '!=', $staff->id)->value('staff_id');
        $peer = $peerId === null ? null : Staff::query()->find($peerId);

        return $peer !== null && self::reachable($peer) ? $peer : null;
    }

    public static function markRead(Staff $staff, StaffThread $thread, ?int $upTo = null): void
    {
        $upTo ??= (int) $thread->messages()->max('id');
        if ($upTo <= 0 || !self::isMember($staff, $thread)) {
            return;
        }
        $row = ['thread_id' => $thread->id, 'staff_id' => $staff->id];
        DB::table('staff_thread_members')->insertOrIgnore($row + ['last_read_message_id' => 0]);
        // Only forward: an older tab never takes the mark back.
        DB::table('staff_thread_members')->where($row)->where('last_read_message_id', '<', $upTo)->update(['last_read_message_id' => $upTo]);
    }

    /** Messages of others in every thread of the member, not yet seen, up to UNREAD_CAP + 1. */
    public static function unread(Staff $staff): int
    {
        return min(self::UNREAD_CAP + 1, array_sum(self::unreadByThread($staff)));
    }

    /**
     * Unseen messages of others per thread the member is in: the room under `room`, a private thread under its other
     * member's id. Each count stops at UNREAD_CAP + 1.
     *
     * @return array<int|string, int>
     */
    public static function unreadByThread(Staff $staff): array
    {
        if (!self::canView($staff)) {
            return [];
        }
        $counts = [];
        $room = StaffThread::query()->where('direct_key', StaffThread::ROOM)->first();
        if ($room !== null) {
            $counts['room'] = self::countUnread($staff, $room->id);
        }
        $threads = DB::table('staff_thread_members as me')
            ->join('staff_threads as t', 't.id', '=', 'me.thread_id')
            ->join('staff_thread_members as peer', fn ($join) => $join->on('peer.thread_id', '=', 'me.thread_id')->on('peer.staff_id', '!=', 'me.staff_id'))
            ->where('me.staff_id', $staff->id)->where('t.kind', self::DIRECT)
            ->pluck('peer.staff_id', 't.id');
        foreach ($threads as $threadId => $peerId) {
            $counts[(int) $peerId] = self::countUnread($staff, (int) $threadId);
        }

        return array_filter($counts);
    }

    private static function countUnread(Staff $staff, int $threadId): int
    {
        $read = (int) DB::table('staff_thread_members')->where(['thread_id' => $threadId, 'staff_id' => $staff->id])->value('last_read_message_id');

        return DB::query()->fromSub(
            StaffMessage::query()->toBase()->select('id')->where('thread_id', $threadId)->where('id', '>', $read)
                ->where('staff_id', '!=', $staff->id)->limit(self::UNREAD_CAP + 1),
            'unread',
        )->count();
    }

    private static function thread(string $kind, string $key): StaffThread
    {
        $thread = StaffThread::query()->where('direct_key', $key)->first();
        if ($thread !== null) {
            return $thread;
        }
        try {
            return StaffThread::query()->create(['kind' => $kind, 'direct_key' => $key]);
        } catch (UniqueConstraintViolationException) {
            // Two first visits at once: the other one made it.
            return StaffThread::query()->where('direct_key', $key)->firstOrFail();
        }
    }
}
