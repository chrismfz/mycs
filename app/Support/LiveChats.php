<?php

namespace App\Support;

use App\Enums\StaffPresenceStatus;
use App\Models\LiveChat;
use App\Models\LiveChatMessage;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The current login may chat for the current account: the setting, the account's `chat`, not staff in disguise, and
     * its address proven where the client area asks for that (MustVerfiyEmail; the popup's route is outside its group,
     * and a login made with someone else's address showed the operator a customer: audit 2026-11-17).
     */
    public static function customerMay(?User $user): bool
    {
        return self::enabled() && $user !== null && !Actor::impersonating() && CurrentClient::id() > 0 && CurrentClient::allows('chat')
            && (!config('settings.mail_must_verify') || $user->hasVerifiedEmail());
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
        // A visitor blocked from this address or this session does not come back as a customer at once (G7, ADV-889-2).
        if (VisitorChatTools::blocked(CallerIp::key()) || ChatVisitor::blockedHere()) {
            return __('Live chat is not available.');
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

    /**
     * The chats of a query a member may see, as canSee() decides one by one (F3): none for who is not an operator, else
     * only those of the departments that list them. Applied to the query itself, on every request (F7).
     *
     * @param  Builder<LiveChat>  $query
     * @return Builder<LiveChat>
     */
    public static function seenBy(Builder $query, ?Staff $staff): Builder
    {
        $departments = $staff !== null && self::isOperator($staff) ? self::departmentsOf($staff) : [];
        $departments === [] ? $query->whereRaw('1 = 0') : $query->whereIn('department_id', $departments);

        return $query;
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
        return in_array($chat->id, self::orphanedIds(new Collection([$chat])), true);
    }

    /**
     * The orphaned ones of a set of chats, in three queries however many there are (re-review of #475: the queue polls
     * every 5 seconds): their operators with their roles, and those operators' departments.
     *
     * @param  Collection<int, LiveChat>  $chats
     * @return list<int>
     */
    private static function orphanedIds(Collection $chats): array
    {
        $active = $chats->filter(fn (LiveChat $chat) => $chat->status === LiveChat::ACTIVE);
        if ($active->isEmpty()) {
            return [];
        }
        $ids = $active->pluck('assigned_to')->filter()->unique()->values()->all();
        $operators = Staff::query()->with('role')->whereKey($ids)->get()->keyBy('id');
        $departments = DB::table('live_chat_department_staff')->whereIn('staff_id', $ids)->get(['staff_id', 'ticket_department_id'])
            ->groupBy('staff_id')->map(fn ($rows) => $rows->pluck('ticket_department_id')->map(fn ($id) => (int) $id)->all());

        return $active->filter(function (LiveChat $chat) use ($operators, $departments) {
            $operator = $chat->assigned_to === null ? null : $operators->get($chat->assigned_to);

            return !($operator instanceof Staff && self::isOperator($operator) && $operator->hasPermission('admin.chats.reply')
                && in_array($chat->department_id, $departments->get($operator->id, []), true));
        })->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return Collection<int, LiveChat> active chats of the member's departments held by someone else */
    private static function othersActive(Staff $staff): Collection
    {
        return LiveChat::query()->where('status', LiveChat::ACTIVE)->whereIn('department_id', self::departmentsOf($staff))
            ->where(fn ($query) => $query->whereNull('assigned_to')->orWhere('assigned_to', '!=', $staff->id))->get();
    }

    /** @return Collection<int, LiveChat> chats waiting in the member's departments, and those whose operator left, oldest first */
    public static function queue(Staff $staff): Collection
    {
        if (!self::isOperator($staff)) {
            return new Collection;
        }
        $waiting = LiveChat::query()->where('status', LiveChat::WAITING)->whereIn('department_id', self::departmentsOf($staff))->get();
        $others = self::othersActive($staff);
        $orphans = self::orphanedIds($others);
        $chats = $waiting->merge($others->filter(fn (LiveChat $chat) => in_array($chat->id, $orphans, true)))->sortBy('id')->values();

        return $chats->load(['client', 'department:id,name']);
    }

    public const NO_ALERTS = ['waiting' => 0, 'chat_latest' => 0, 'chat_title' => '', 'message_latest' => 0, 'message_title' => '', 'message_url' => ''];

    /**
     * What the alert on every admin page may say of customers' chats (F21: never a customer's name or words): how many
     * wait in the member's departments, the newest message that brought one to the queue and its department, and the
     * newest customer message (or transfer to them) in the member's own chats.
     *
     * @return array{waiting: int, chat_latest: int, chat_title: string, message_latest: int, message_title: string, message_url: string}
     */
    public static function alerts(Staff $staff): array
    {
        if (!self::isOperator($staff)) {
            return self::NO_ALERTS;
        }
        // Light, every 10 seconds on every admin tab (review of #479): counts and ids, never the customers' rows. A chat
        // whose operator left counts in the title (waiting()) but rings no new alert: it is not a new chat.
        $departments = self::departmentsOf($staff);
        // By message id, not chat id (step 3β): a waiting chat's first message, or the system's line of a transfer from
        // another department, so an old chat moved here rings too. Not the customer's later messages: one chat rings
        // once, the reminder does the rest (ADV-884-3). Notes ring no one.
        $newest = LiveChatMessage::query()->join('live_chats', 'live_chats.id', '=', 'live_chat_messages.chat_id')
            ->where('live_chats.status', LiveChat::WAITING)->whereIn('live_chats.department_id', $departments)
            ->where(fn ($query) => $query->where('live_chat_messages.author_type', LiveChatMessage::SYSTEM)
                ->orWhereRaw('live_chat_messages.id = (select min(opening.id) from live_chat_messages opening where opening.chat_id = live_chat_messages.chat_id)'))
            ->orderByDesc('live_chat_messages.id')->first(['live_chat_messages.id', 'live_chats.department_id']);
        $department = $newest === null ? null : TicketDepartment::query()->find($newest->getAttribute('department_id'), ['id', 'name']);
        $mine = LiveChat::query()->where('status', LiveChat::ACTIVE)->where('assigned_to', $staff->id)
            ->whereIn('department_id', $departments)->pluck('id');
        // The customer's messages in the member's chats, and a colleague's transfer of a chat to them (step 3β): a system
        // line by someone else that is the chat's latest system line. A take-over writes its own line, newer than an old
        // transfer between two others, so it is not announced as a transfer (ADV-884-4).
        $message = $mine->isEmpty() ? null : LiveChatMessage::query()->whereIn('chat_id', $mine)
            ->where(fn ($query) => $query->whereIn('author_type', [LiveChatMessage::CLIENT, LiveChatMessage::VISITOR])
                ->orWhere(fn ($query) => $query->where('author_type', LiveChatMessage::SYSTEM)->where('staff_id', '!=', $staff->id)
                    ->whereNotExists(fn ($later) => $later->from('live_chat_messages as later')->whereColumn('later.chat_id', 'live_chat_messages.chat_id')
                        ->where('later.author_type', LiveChatMessage::SYSTEM)->whereColumn('later.id', '>', 'live_chat_messages.id'))))
            ->orderByDesc('id')->first(['id', 'chat_id', 'author_type']);

        return [
            'waiting' => self::waiting($staff),
            'chat_latest' => (int) $newest?->id,
            'chat_title' => $newest === null ? '' : __('New chat: :department', ['department' => (string) $department?->name]),
            'message_latest' => (int) $message?->id,
            'message_title' => match ($message?->author_type) {
                null => '',
                LiveChatMessage::SYSTEM => __('A chat was transferred to you'),
                default => __('New message in a customer chat'),
            },
            'message_url' => $message === null ? '' : \App\Admin\Pages\LiveChat::getUrl(['chat' => $message->chat_id]),
        ];
    }

    /** How many wait in the member's departments, and how many have no operator: the menu's badge, as the queue counts them. */
    public static function waiting(Staff $staff): int
    {
        if (!self::isOperator($staff)) {
            return 0;
        }

        return LiveChat::query()->where('status', LiveChat::WAITING)->whereIn('department_id', self::departmentsOf($staff))->count()
            + count(self::orphanedIds(self::othersActive($staff)));
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
            // Still in the department it was read in: a transfer in between moves it out of the member's reach (step 3β).
            $taken = LiveChat::query()->whereKey($chat->id)->where('status', LiveChat::WAITING)->whereNull('assigned_to')
                ->where('department_id', $chat->department_id)->update(['status' => LiveChat::ACTIVE, 'assigned_to' => $staff->id, 'updated_at' => now()]);

            return $taken === 1;
        }
        if (!self::orphaned($chat)) {
            return false;
        }

        // The take-over and its note for the staff (F20) together, or neither (re-review of #475).
        return DB::transaction(function () use ($staff, $chat) {
            $query = LiveChat::query()->whereKey($chat->id)->where('status', LiveChat::ACTIVE)->where('department_id', $chat->department_id);
            $chat->assigned_to === null ? $query->whereNull('assigned_to') : $query->where('assigned_to', $chat->assigned_to);
            if ($query->update(['assigned_to' => $staff->id, 'updated_at' => now()]) !== 1) {
                return false;
            }
            $chat->messages()->create(['author_type' => LiveChatMessage::SYSTEM, 'staff_id' => $staff->id,
                'body' => __('Taken over by :name from :from.', ['name' => $staff->name, 'from' => (string) ($chat->operator->name ?? __('no operator'))])]);

            return true;
        });
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
        if (self::staffOver($staff)) {
            return __('Too many messages, wait a moment.');
        }

        return self::write($chat, ['author_type' => LiveChatMessage::STAFF, 'staff_id' => $staff->id, 'body' => $body], participant: false, operator: $staff->id);
    }

    /** The operator closes the chat they took, or one of their department whose operator left. */
    public static function closeAsStaff(Staff $staff, LiveChat $chat): bool
    {
        $chat->refresh();
        if (!self::canReply($staff, $chat) || ($chat->assigned_to !== $staff->id && !self::orphaned($chat))) {
            return false;
        }

        // Only as it was read: a transfer or take-over in between is not closed from under its new operator (step 3β).
        return self::close($chat, asRead: true);
    }

    /**
     * An operator's internal note in a chat of their department, open (step 3β): the staff see it in the console and in
     * the client's «Chats» tab, the customer never (F20, CUSTOMER_VISIBLE). Null when written; else what to tell them.
     */
    public static function note(Staff $staff, LiveChat $chat, string $text): ?string
    {
        $chat->refresh();
        if (!self::canReply($staff, $chat)) {
            return __('You may not write here.');
        }
        $body = ChatText::clean($text);
        if ($body === null) {
            return __('The message is empty.');
        }
        if (self::staffOver($staff)) {
            return __('Too many messages, wait a moment.');
        }

        return self::write($chat, ['author_type' => LiveChatMessage::NOTE, 'staff_id' => $staff->id, 'body' => $body], participant: false, department: $chat->department_id);
    }

    /**
     * Where the member may pass their active chat (step 3β): a colleague of its department who is connected and may
     * answer it (F3), or another department that takes chats and has someone available.
     *
     * @return array{operators: list<Staff>, departments: Collection<int, TicketDepartment>}
     */
    public static function transferTargets(Staff $staff, LiveChat $chat): array
    {
        if (!self::canReply($staff, $chat) || $chat->assigned_to !== $staff->id || $chat->status !== LiveChat::ACTIVE) {
            return ['operators' => [], 'departments' => new Collection];
        }
        $operators = [];
        foreach (StaffPresence::online() as $member) {
            if ($member['staff']->id !== $staff->id && self::canReply($member['staff'], $chat)) {
                $operators[] = $member['staff'];
            }
        }

        // Only where someone can answer now, as start() asks (ADV-884-1): never a queue no one reads.
        $departments = self::departments()->filter(fn (TicketDepartment $department) => $department->id !== $chat->department_id && self::availableIn($department->id));

        return ['operators' => $operators, 'departments' => $departments->values()];
    }

    /**
     * The operator passes the chat they hold to a colleague of its department (it stays active, now theirs) or to another
     * department (it waits there in the queue, for anyone of it). One of the two targets. A note for the staff says so
     * (F20). Two at once, or a transfer and a take-over, one wins (F16): the update holds only while the chat is still as
     * it was read. Null when done; else what to tell them.
     */
    public static function transfer(Staff $staff, LiveChat $chat, ?int $toStaffId = null, ?int $toDepartmentId = null): ?string
    {
        $chat->refresh();
        if (!self::canReply($staff, $chat) || $chat->assigned_to !== $staff->id || $chat->status !== LiveChat::ACTIVE) {
            return __('You may not transfer this chat.');
        }
        if (($toStaffId === null) === ($toDepartmentId === null)) {
            return __('Choose an operator or a department.');
        }
        $targets = self::transferTargets($staff, $chat);
        if ($toStaffId !== null) {
            $target = collect($targets['operators'])->first(fn (Staff $member) => $member->id === $toStaffId);
            if (!$target instanceof Staff) {
                return __('This operator cannot take the chat.');
            }
            $changes = ['assigned_to' => $target->id];
            $to = $target->name;
        } else {
            $department = $targets['departments']->firstWhere('id', $toDepartmentId);
            if (!$department instanceof TicketDepartment) {
                return __('This department does not take chats.');
            }
            $changes = ['status' => LiveChat::WAITING, 'assigned_to' => null, 'department_id' => $department->id];
            $to = $department->name;
        }
        if (self::staffOver($staff)) {
            return __('Too many messages, wait a moment.');
        }

        return DB::transaction(function () use ($staff, $chat, $changes, $to) {
            $moved = LiveChat::query()->whereKey($chat->id)->where('status', LiveChat::ACTIVE)->where('assigned_to', $staff->id)
                ->where('department_id', $chat->department_id)->update($changes + ['updated_at' => now()]);
            if ($moved !== 1) {
                return __('This chat changed in the meantime.');
            }
            $chat->messages()->create(['author_type' => LiveChatMessage::SYSTEM, 'staff_id' => $staff->id,
                'body' => __('Transferred from :from to :to.', ['from' => $staff->name, 'to' => $to])]);

            return null;
        });
    }

    // ---- idle close (step 3β)

    /** Minutes without a message after which a chat closes by itself; 0 never. */
    public static function idleMinutes(): int
    {
        return max(0, (int) config('settings.livechat_idle_minutes', 30));
    }

    /**
     * Closes the open chats, waiting or active, where neither the customer nor an operator wrote, and no one took or
     * moved the chat, for idleMinutes(); notes and the system's lines do not count. Each one under the chat's lock, checked again there, so a message written at
     * that moment either lands first and keeps the chat open, or finds it closed (F16). A note for the staff says why.
     * Returns how many it closed.
     */
    public static function closeIdle(): int
    {
        $minutes = self::idleMinutes();
        if ($minutes === 0) {
            return 0;
        }
        $closed = 0;
        foreach (self::idle(LiveChat::query(), $minutes)->pluck('id') as $id) {
            $closed += (int) DB::transaction(function () use ($id, $minutes) {
                $chat = self::idle(LiveChat::query()->whereKey($id), $minutes)->lockForUpdate()->first();
                if ($chat === null) {
                    return false;
                }
                $chat->forceFill(['status' => LiveChat::CLOSED, 'closed_at' => now()])->save();
                $chat->messages()->create(['author_type' => LiveChatMessage::SYSTEM,
                    'body' => __('Closed after :minutes minutes without a message.', ['minutes' => $minutes])]);

                return true;
            });
        }

        return $closed;
    }

    /**
     * @param  Builder<LiveChat>  $query
     * @return Builder<LiveChat>
     */
    private static function idle(Builder $query, int $minutes): Builder
    {
        $since = now()->subMinutes($minutes);

        // Since the chat last changed hands too (updated_at: a claim, a take-over, a transfer), so one just taken or moved
        // to another department is not closed a minute later (ADV-885-1/2).
        return $query->where('status', '!=', LiveChat::CLOSED)->where('created_at', '<=', $since)->where('updated_at', '<=', $since)
            ->whereDoesntHave('messages', fn ($messages) => $messages->whereIn('author_type', LiveChatMessage::PARTICIPANTS)->where('created_at', '>', $since));
    }

    /** The account's chat with this id, closed: what the bubble shows as «the chat ended» (never another account's). */
    public static function endedChatOf(int $clientId, int $chatId): ?LiveChat
    {
        return LiveChat::query()->whereKey($chatId)->where('client_id', $clientId)->where('status', LiveChat::CLOSED)->first();
    }

    // ---- shared

    /** @return \Illuminate\Support\Collection<int, LiveChatMessage> the latest messages, oldest first */
    public static function messages(LiveChat $chat): \Illuminate\Support\Collection
    {
        return $chat->messages()->with(['staff:id,first_name,last_name,email', 'user:id,first_name,last_name,email'])->orderByDesc('id')->limit(self::WINDOW)->get()->reverse()->values();
    }

    /**
     * The whole conversation, oldest first, staff notes included: the admin's read-only history (§6α). Reads only.
     *
     * @return \Illuminate\Support\Collection<int, LiveChatMessage>
     */
    public static function history(LiveChat $chat): \Illuminate\Support\Collection
    {
        return $chat->messages()->with(['staff:id,first_name,last_name,email', 'user:id,first_name,last_name,email'])->orderBy('id')->get();
    }

    /**
     * The messages a customer may see, oldest first: only the customer's and the operators' messages, never the system's
     * or the internal notes (F20). An allowlist in the query (LiveChatMessage::CUSTOMER_VISIBLE), so a later reader on the
     * customer's side (the history of step 3γ) cannot forget it, and notes take no room in the window.
     *
     * @return \Illuminate\Support\Collection<int, LiveChatMessage>
     */
    public static function customerMessages(LiveChat $chat): \Illuminate\Support\Collection
    {
        return $chat->messages()->with(['staff:id,first_name', 'user:id,first_name'])->whereIn('author_type', LiveChatMessage::CUSTOMER_VISIBLE)
            ->orderByDesc('id')->limit(self::WINDOW)->get()->reverse()->values();
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
    public static function write(LiveChat $chat, array $message, bool $participant, ?int $operator = null, ?int $department = null): ?string
    {
        return DB::transaction(function () use ($chat, $message, $participant, $operator, $department) {
            $locked = LiveChat::query()->whereKey($chat->id)->lockForUpdate()->first();
            if ($locked === null || !$locked->isOpen()) {
                return __('This chat is closed.');
            }
            // Still theirs under the lock: a take-over in between wins (re-review of #475).
            if ($operator !== null && $locked->assigned_to !== $operator) {
                return __('You may not write here.');
            }
            // Still in the department the writer was checked for: a transfer in between moves it out of reach (ADV-884-2).
            if ($department !== null && $locked->department_id !== $department) {
                return __('You may not write here.');
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

    /** An operator's messages, notes and transfers, counted together (F13). */
    private static function staffOver(Staff $staff): bool
    {
        return self::over('live-chat-staff:' . $staff->id, 60, self::PER_MINUTE * 2);
    }

    /** Closes the chat; with $asRead only while its operator and department are still as read. */
    private static function close(LiveChat $chat, bool $asRead = false): bool
    {
        $query = LiveChat::query()->whereKey($chat->id)->where('status', '!=', LiveChat::CLOSED);
        if ($asRead) {
            $query->where('department_id', $chat->department_id);
            $chat->assigned_to === null ? $query->whereNull('assigned_to') : $query->where('assigned_to', $chat->assigned_to);
        }

        return $query->update(['status' => LiveChat::CLOSED, 'closed_at' => now(), 'updated_at' => now()]) === 1;
    }
}
