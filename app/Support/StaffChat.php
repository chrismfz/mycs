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
 * The staff's own chat (docs/livechat.md §7γ): the shared room for now. Who may read and write is checked here, on
 * every call, not only when the page opens (F7, F23).
 */
class StaffChat
{
    /** Messages a member may send a minute (§3 Ε). */
    public const PER_MINUTE = 20;

    /** The latest messages a poll reads: the whole window each time, so a message committed late is never skipped (F15). */
    public const WINDOW = 100;

    public static function canView(?Staff $staff): bool
    {
        // Past the mandatory 2FA set-up too, as for «Staff online» (review of #373).
        return $staff !== null && StaffPresence::mayBeOnline($staff) && $staff->hasPermission('admin.staff_chat.view');
    }

    public static function canPost(?Staff $staff): bool
    {
        return self::canView($staff) && $staff?->hasPermission('admin.staff_chat.post');
    }

    public static function room(): StaffThread
    {
        $room = StaffThread::query()->where('direct_key', StaffThread::ROOM)->first();
        if ($room !== null) {
            return $room;
        }
        try {
            return StaffThread::query()->create(['kind' => StaffThread::ROOM, 'direct_key' => StaffThread::ROOM]);
        } catch (UniqueConstraintViolationException) {
            // Two first visits at once: the other one made it.
            return StaffThread::query()->where('direct_key', StaffThread::ROOM)->firstOrFail();
        }
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
        // The room only, for now: a private thread (step 2b) checks its two members first (review of #373).
        if (!self::canPost($staff) || $thread->kind !== StaffThread::ROOM) {
            return __('You may not write here.');
        }
        $body = ChatText::clean($text);
        if ($body === null) {
            return __('The message is empty.');
        }
        // Counted first, then checked: many sends in one request stop at the limit (F13).
        $key = 'staff-chat:' . $staff->id;
        RateLimiter::hit($key, 60);
        if (RateLimiter::attempts($key) > self::PER_MINUTE) {
            return __('Too many messages, wait a moment.');
        }

        $message = $thread->messages()->create(['staff_id' => $staff->id, 'body' => $body]);
        self::markRead($staff, $thread, $message->id);

        return null;
    }

    public static function markRead(Staff $staff, StaffThread $thread, ?int $upTo = null): void
    {
        $upTo ??= (int) $thread->messages()->max('id');
        if ($upTo === 0) {
            return;
        }
        $row = ['thread_id' => $thread->id, 'staff_id' => $staff->id];
        DB::table('staff_thread_members')->insertOrIgnore($row + ['last_read_message_id' => 0]);
        // Only forward: an older tab never takes the mark back.
        DB::table('staff_thread_members')->where($row)->where('last_read_message_id', '<', $upTo)->update(['last_read_message_id' => $upTo]);
    }

    /** The badge counts up to this many; more show as «99+» (review of #373: a member who never reads the room). */
    public const UNREAD_CAP = 99;

    /** Messages of others in the room the member has not seen, up to UNREAD_CAP + 1. */
    public static function unread(Staff $staff): int
    {
        if (!self::canView($staff)) {
            return 0;
        }
        $room = StaffThread::query()->where('direct_key', StaffThread::ROOM)->first();
        if ($room === null) {
            return 0;
        }
        $read = (int) DB::table('staff_thread_members')->where(['thread_id' => $room->id, 'staff_id' => $staff->id])->value('last_read_message_id');

        return DB::query()->fromSub(
            $room->messages()->toBase()->select('id')->where('id', '>', $read)->where('staff_id', '!=', $staff->id)->limit(self::UNREAD_CAP + 1),
            'unread',
        )->count();
    }
}
