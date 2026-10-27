<?php

namespace App\Support;

use App\Enums\StaffPresenceStatus;
use App\Models\LiveChat;
use App\Models\LiveChatMessage;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Live chat with customers (docs/livechat.md §3, §6, step 3α). Every state change and every right to read or write is
 * decided here, on every call (F7): a customer only through the account's permission `chat` and never as staff in
 * «Login as client» (F9); an operator only in the departments that list them (F3).
 */
class LiveChats
{
    /** Chats a login may start in 10 minutes, and messages it may send a minute (§3 Ε). */
    public const STARTS_PER_10_MINUTES = 3;

    public const PER_MINUTE = 20;

    /** The latest messages a poll reads: the whole window each time, so a message committed late is never skipped (F15). */
    public const WINDOW = 100;

    public static function enabled(): bool
    {
        return (bool) config('settings.livechat_enabled', false);
    }

    // ---- the customer's side

    /** The current login may chat for the current account: the setting, the account's `chat`, and not staff in disguise. */
    public static function customerMay(?User $user): bool
    {
        return self::enabled() && $user !== null && !Actor::impersonating() && CurrentClient::id() > 0 && CurrentClient::allows('chat');
    }

    /**
     * Departments a customer may chat with: offered to customers (not hidden) and with someone listed to answer.
     *
     * @return Collection<int, TicketDepartment>
     */
    public static function departments(): Collection
    {
        return TicketDepartment::query()->offered()->whereHas('chatStaff')->get(['id', 'name']);
    }

    /** Seconds the client area trusts «someone is available»: the bubble asks on every page, the answer is shared. */
    public const AVAILABILITY_TTL = 30;

    /**
     * Departments a customer may chat with right now: those of departments() with someone available (owner 2026-10-27:
     * when we are offline, the bubble hides). Kept for AVAILABILITY_TTL seconds for every customer alike.
     *
     * @return list<int>
     */
    public static function availableDepartmentIds(): array
    {
        return Cache::remember('live-chat-available', self::AVAILABILITY_TTL, fn () => self::departments()
            ->filter(fn (TicketDepartment $department) => self::availableIn($department->id))->pluck('id')->map(fn ($id) => (int) $id)->values()->all());
    }

    /** The account's chat still open, if any: one at a time per account (F10). */
    public static function openChatOf(int $clientId): ?LiveChat
    {
        return LiveChat::query()->where('client_id', $clientId)->where('status', '!=', LiveChat::CLOSED)->latest('id')->first();
    }

    /** Null when started; else what to tell the customer. */
    public static function start(User $user, int $departmentId, string $text): ?string
    {
        if (!self::customerMay($user)) {
            return __('Live chat is not available.');
        }
        $department = self::departments()->firstWhere('id', $departmentId);
        if ($department === null) {
            return __('Choose a department.');
        }
        if (!self::availableIn($department->id)) {
            return __('No one is available in this department right now.');
        }
        $body = ChatText::clean($text);
        if ($body === null) {
            return __('The message is empty.');
        }
        $clientId = CurrentClient::id();
        // Said before counting, so a second tab does not use up the account's starts; checked again under the lock below.
        if (self::openChatOf($clientId) !== null) {
            return __('A chat of this account is already open.');
        }
        // Counted first, then checked (F13), per account: every login of it shares the limit (§3 Ε, review of #475).
        if (self::over('live-chat-start:' . $clientId, 600, self::STARTS_PER_10_MINUTES)) {
            return __('Too many chats started, wait a few minutes.');
        }

        // One open chat per account, even when two tabs start at once (F13).
        return Cache::lock('live-chat-open:' . $clientId, 10)->block(5, function () use ($user, $clientId, $department, $body) {
            if (self::openChatOf($clientId) !== null) {
                return __('A chat of this account is already open.');
            }
            DB::transaction(function () use ($user, $clientId, $department, $body) {
                $chat = LiveChat::query()->create([
                    'client_id' => $clientId,
                    'user_id' => $user->id,
                    'department_id' => $department->id,
                    'status' => LiveChat::WAITING,
                    'last_participant_message_at' => now(),
                ]);
                $chat->messages()->create(['author_type' => LiveChatMessage::CLIENT, 'user_id' => $user->id, 'body' => $body]);
            });

            return null;
        });
    }

    /** The customer's message in their account's open chat. Null when sent; else what to tell them. */
    public static function postAsCustomer(User $user, LiveChat $chat, string $text): ?string
    {
        if (!self::customerMay($user) || $chat->client_id !== CurrentClient::id()) {
            return __('You may not write here.');
        }
        $body = ChatText::clean($text);
        if ($body === null) {
            return __('The message is empty.');
        }
        if (self::over('live-chat:' . $chat->client_id, 60, self::PER_MINUTE)) {
            return __('Too many messages, wait a moment.');
        }

        return self::write($chat, ['author_type' => LiveChatMessage::CLIENT, 'user_id' => $user->id, 'body' => $body], participant: true);
    }

    /** The customer ends their chat. */
    public static function endAsCustomer(User $user, LiveChat $chat): void
    {
        if (self::customerMay($user) && $chat->client_id === CurrentClient::id()) {
            self::close($chat);
        }
    }

    // ---- the operator's side

    /** A member who answers chats at all: active, past the 2FA set-up when required, with the permission. */
    public static function isOperator(?Staff $staff): bool
    {
        return $staff !== null && StaffPresence::mayBeOnline($staff) && $staff->hasPermission('admin.chats.view');
    }

    /** @return list<int> the departments a member answers */
    public static function departmentsOf(Staff $staff): array
    {
        return DB::table('live_chat_department_staff')->where('staff_id', $staff->id)->pluck('ticket_department_id')->map(fn ($id) => (int) $id)->all();
    }

    /** A member may see a chat: an operator listed in its department (F3). */
    public static function canSee(?Staff $staff, LiveChat $chat): bool
    {
        return self::isOperator($staff) && $chat->department_id !== null && in_array($chat->department_id, self::departmentsOf($staff), true);
    }

    /** A member may take, write in and close a chat they see. */
    public static function canReply(?Staff $staff, LiveChat $chat): bool
    {
        return self::canSee($staff, $chat) && (bool) $staff?->hasPermission('admin.chats.reply');
    }

    /** Someone answers in the department now: listed there, an operator, connected and «Available» (§5α). */
    public static function availableIn(int $departmentId): bool
    {
        $listed = DB::table('live_chat_department_staff')->where('ticket_department_id', $departmentId)->pluck('staff_id')->map(fn ($id) => (int) $id)->all();
        foreach (StaffPresence::online() as $member) {
            if (in_array($member['staff']->id, $listed, true) && $member['status'] === StaffPresenceStatus::Available
                && self::isOperator($member['staff']) && $member['staff']->hasPermission('admin.chats.reply')) {
                return true;
            }
        }

        return false;
    }

    /**
     * An active chat whose operator can no longer answer it (left, unlisted, lost the permission, deleted): a colleague of
     * the department takes it over or closes it, so the customer is never left writing to no one (review of #475).
     */
    public static function orphaned(LiveChat $chat): bool
    {
        if ($chat->status !== LiveChat::ACTIVE) {
            return false;
        }
        $operator = $chat->assigned_to === null ? null : Staff::query()->find($chat->assigned_to);

        return !self::canReply($operator, $chat);
    }

    /** @return Collection<int, LiveChat> chats waiting in the member's departments, and those whose operator left, oldest first */
    public static function queue(Staff $staff): Collection
    {
        if (!self::isOperator($staff)) {
            return new Collection;
        }
        $chats = LiveChat::query()->with(['client', 'department:id,name'])->whereIn('status', [LiveChat::WAITING, LiveChat::ACTIVE])
            ->whereIn('department_id', self::departmentsOf($staff))->oldest('id')->get();

        return $chats->filter(fn (LiveChat $chat) => $chat->status === LiveChat::WAITING || ($chat->assigned_to !== $staff->id && self::orphaned($chat)))->values();
    }

    /** How many wait in the member's departments: the menu's badge, on every admin page, as one count. */
    public static function waiting(Staff $staff): int
    {
        return self::isOperator($staff)
            ? LiveChat::query()->where('status', LiveChat::WAITING)->whereIn('department_id', self::departmentsOf($staff))->count()
            : 0;
    }

    /** @return Collection<int, LiveChat> the member's own active chats */
    public static function mine(Staff $staff): Collection
    {
        if (!self::isOperator($staff)) {
            return new Collection;
        }

        return LiveChat::query()->with(['client', 'department:id,name'])->where('status', LiveChat::ACTIVE)
            ->where('assigned_to', $staff->id)->whereIn('department_id', self::departmentsOf($staff))->oldest('id')->get();
    }

    /**
     * The member takes a waiting chat, or takes over one whose operator left. True when it is theirs; two at once, one
     * wins (F16): the update holds only while the chat is still as it was read.
     */
    public static function claim(Staff $staff, LiveChat $chat): bool
    {
        $chat->refresh();
        if (!self::canReply($staff, $chat)) {
            return false;
        }
        if ($chat->status === LiveChat::WAITING) {
            $taken = LiveChat::query()->whereKey($chat->id)->where('status', LiveChat::WAITING)->whereNull('assigned_to')
                ->update(['status' => LiveChat::ACTIVE, 'assigned_to' => $staff->id, 'updated_at' => now()]);

            return $taken === 1;
        }
        if (!self::orphaned($chat)) {
            return false;
        }
        $query = LiveChat::query()->whereKey($chat->id)->where('status', LiveChat::ACTIVE);
        $chat->assigned_to === null ? $query->whereNull('assigned_to') : $query->where('assigned_to', $chat->assigned_to);

        return $query->update(['assigned_to' => $staff->id, 'updated_at' => now()]) === 1;
    }

    /** The operator's message, in a chat they took. Null when sent; else what to tell them. */
    public static function postAsStaff(Staff $staff, LiveChat $chat, string $text): ?string
    {
        $chat->refresh();
        if (!self::canReply($staff, $chat) || $chat->assigned_to !== $staff->id || $chat->status !== LiveChat::ACTIVE) {
            return __('You may not write here.');
        }
        $body = ChatText::clean($text);
        if ($body === null) {
            return __('The message is empty.');
        }
        $key = 'live-chat-staff:' . $staff->id;
        RateLimiter::hit($key, 60);
        if (RateLimiter::attempts($key) > self::PER_MINUTE * 2) {
            return __('Too many messages, wait a moment.');
        }

        return self::write($chat, ['author_type' => LiveChatMessage::STAFF, 'staff_id' => $staff->id, 'body' => $body], participant: false);
    }

    /** The operator closes the chat they took, or one of their department whose operator left. */
    public static function closeAsStaff(Staff $staff, LiveChat $chat): bool
    {
        $chat->refresh();
        if (!self::canReply($staff, $chat) || ($chat->assigned_to !== $staff->id && !self::orphaned($chat))) {
            return false;
        }

        return self::close($chat);
    }

    // ---- shared

    /** @return \Illuminate\Support\Collection<int, LiveChatMessage> the latest messages, oldest first */
    public static function messages(LiveChat $chat): \Illuminate\Support\Collection
    {
        return $chat->messages()->with(['staff:id,first_name,last_name,email', 'user:id,first_name,last_name,email'])->orderByDesc('id')->limit(self::WINDOW)->get()->reverse()->values();
    }

    /** The account's other login, as the customer sees it: the first name only. */
    public static function loginName(?User $user): string
    {
        return $user === null ? __('Another login') : (trim((string) $user->first_name) ?: __('Another login'));
    }

    /** What the customer sees of an operator: the first name only. */
    public static function operatorName(?Staff $staff): string
    {
        return $staff === null ? __('Our team') : (trim((string) $staff->first_name) ?: __('Our team'));
    }

    /**
     * A message goes in only while the chat is open, checked and written under the chat's lock, so a close and a message
     * at once never leave a message after the close (F16).
     *
     * @param  array<string, mixed>  $message
     */
    private static function write(LiveChat $chat, array $message, bool $participant): ?string
    {
        return DB::transaction(function () use ($chat, $message, $participant) {
            $locked = LiveChat::query()->whereKey($chat->id)->lockForUpdate()->first();
            if ($locked === null || !$locked->isOpen()) {
                return __('This chat is closed.');
            }
            $locked->messages()->create($message);
            if ($participant) {
                $locked->forceFill(['last_participant_message_at' => now()])->save();
            }

            return null;
        });
    }

    /** Counted first, then checked (F13). */
    private static function over(string $key, int $seconds, int $limit): bool
    {
        RateLimiter::hit($key, $seconds);

        return RateLimiter::attempts($key) > $limit;
    }

    private static function close(LiveChat $chat): bool
    {
        return LiveChat::query()->whereKey($chat->id)->where('status', '!=', LiveChat::CLOSED)
            ->update(['status' => LiveChat::CLOSED, 'closed_at' => now(), 'updated_at' => now()]) === 1;
    }
}
