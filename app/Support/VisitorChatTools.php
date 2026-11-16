<?php

namespace App\Support;

use App\Models\Client;
use App\Models\LiveChat;
use App\Models\LiveChatMessage;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use OwenIt\Auditing\Events\AuditCustom;

/**
 * The operator's tools for a visitor's chat, and the move to a customer's chat after a login (docs/livechat.md §3 Δ,
 * §3 Ε, §6α, step 4, PR 3).
 *
 * - «Ask to log in» (G6): the server's fixed line, never the operator's; the popup shows a «Sign in» button.
 * - The move (G1–G3, G7, G8): only an interactive login (Actions\Auth\Login::execute) in the session holding the
 *   visitor's token freezes the chat for that login at once; the token reaches nothing from then on. The customer then
 *   confirms (a POST): the visitor's chat closes, a new chat of the account opens with the same operator, and only the
 *   staff see that it continues the other. The visitor's words never become the account's. Unconfirmed for
 *   HANDOFF_MINUTES, declined, blocked, or the login may not chat: the visitor's chat just closes.
 * - Block (§3 Ε): the chat's network address may start or write no visitor's chat for BLOCK_HOURS, and the chat closes.
 *   It holds after a login too (G7).
 * - Manual link to a client (§6α, F2): a closed visitor's chat only, with `admin.chats.link` and the member's password
 *   again; the token is revoked, the chat shows in the client's «Chats» tab in the admin, never to the customer.
 */
class VisitorChatTools
{
    public const HANDOFF_MINUTES = 10;

    public const BLOCK_HOURS = 24;

    // ---- «Ask to log in»

    /** The operator who holds a visitor's active chat asks them to log in. False when they may not. */
    public static function askLogin(Staff $staff, LiveChat $chat): bool
    {
        $chat->refresh();
        if (!self::holding($staff, $chat)) {
            return false;
        }

        return DB::transaction(function () use ($staff, $chat) {
            $asked = LiveChat::query()->whereKey($chat->id)->where('status', LiveChat::ACTIVE)->where('assigned_to', $staff->id)
                ->whereNull('client_id')->update(['login_requested_at' => now(), 'updated_at' => now()]);
            if ($asked !== 1) {
                return false;
            }
            $chat->messages()->create(['author_type' => LiveChatMessage::SYSTEM, 'staff_id' => $staff->id,
                'body' => __(':name asked the visitor to log in.', ['name' => $staff->name])]);

            return true;
        });
    }

    // ---- block

    public static function blocked(string $ip): bool
    {
        return $ip !== '' && Cache::has(self::blockKey($ip));
    }

    /** An operator who may answer the chat blocks its visitor's address, and the chat closes. False when they may not. */
    public static function block(Staff $staff, LiveChat $chat): bool
    {
        $chat->refresh();
        if (!LiveChats::canReply($staff, $chat) || !$chat->isVisitor() || $chat->ip === null || $chat->ip === '') {
            return false;
        }
        Cache::put(self::blockKey($chat->ip), true, now()->addHours(self::BLOCK_HOURS));
        DB::transaction(function () use ($staff, $chat) {
            $closed = LiveChat::query()->whereKey($chat->id)->where('status', '!=', LiveChat::CLOSED)
                ->update(['status' => LiveChat::CLOSED, 'closed_at' => now(), 'updated_at' => now()]);
            $chat->messages()->create(['author_type' => LiveChatMessage::SYSTEM, 'staff_id' => $staff->id,
                'body' => __(':name blocked the visitor for :hours hours.', ['name' => $staff->name, 'hours' => self::BLOCK_HOURS])
                    . ($closed === 1 ? '' : ' ' . __('The chat was already closed.'))]);
        });

        return true;
    }

    // ---- the move to a customer's chat

    /**
     * Called by the interactive login only (G3), after its new session: the visitor's open chat this session holds is
     * frozen for this login. From now on no token reaches it (ChatVisitor::chat()), from this session or any other (G2).
     */
    public static function freeze(User $user): void
    {
        $token = Session::get(ChatVisitor::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            return;
        }
        LiveChat::query()->where('visitor_token_hash', hash('sha256', $token))->whereNull('client_id')->whereNull('handoff_user_id')
            ->where('status', '!=', LiveChat::CLOSED)->update(['handoff_user_id' => $user->id, 'handoff_at' => now(), 'updated_at' => now()]);
    }

    /** The visitor's chat frozen for this login in this session, still open: what the popup asks about (G8). */
    public static function pending(User $user): ?LiveChat
    {
        $token = Session::get(ChatVisitor::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            return null;
        }

        return LiveChat::query()->where('visitor_token_hash', hash('sha256', $token))->whereNull('client_id')
            ->where('handoff_user_id', $user->id)->where('status', '!=', LiveChat::CLOSED)
            ->where('handoff_at', '>', now()->subMinutes(self::HANDOFF_MINUTES))->first();
    }

    /**
     * The customer confirms: under the chat's lock, and only while it is still open, frozen for them, not blocked, and
     * they may chat for the account they are in (G7). Null when the new chat is open; else what to tell them (the
     * visitor's chat is closed then).
     */
    public static function confirm(User $user): ?string
    {
        $pending = self::pending($user);
        if ($pending === null) {
            ChatVisitor::forget();

            return __('This chat can no longer be continued.');
        }
        $clientId = CurrentClient::id();
        $refusal = DB::transaction(function () use ($user, $pending, $clientId) {
            $chat = LiveChat::query()->whereKey($pending->id)->lockForUpdate()->first();
            if ($chat === null || !$chat->isOpen() || $chat->client_id !== null || $chat->handoff_user_id !== $user->id) {
                return __('This chat can no longer be continued.');
            }
            $may = LiveChats::customerMay($user) && !self::blocked((string) $chat->ip) && LiveChats::openChatOf($clientId) === null;
            $chat->forceFill(['status' => LiveChat::CLOSED, 'closed_at' => now(), 'visitor_token_hash' => null])->save();
            if (!$may) {
                $chat->messages()->create(['author_type' => LiveChatMessage::SYSTEM, 'body' => __('Closed: the login did not continue this chat.')]);

                return __('This chat can no longer be continued.');
            }
            $next = LiveChat::query()->create([
                'client_id' => $clientId,
                'user_id' => $user->id,
                'department_id' => $chat->department_id,
                'status' => $chat->assigned_to !== null ? LiveChat::ACTIVE : LiveChat::WAITING,
                'assigned_to' => $chat->assigned_to,
                'last_participant_message_at' => now(),
            ]);
            $next->forceFill(['continued_from' => $chat->id])->save();
            // For the staff only (F20): which login continued, and where.
            $chat->messages()->create(['author_type' => LiveChatMessage::SYSTEM,
                'body' => __('Continued by the login :email as chat #:id.', ['email' => (string) $user->email, 'id' => $next->id])]);
            $next->messages()->create(['author_type' => LiveChatMessage::SYSTEM,
                'body' => __('Continues the visitor\'s chat #:id after a login (:email). Ask again for what was asked before.', ['id' => $chat->id, 'email' => (string) $user->email])]);

            return null;
        });
        ChatVisitor::forget();

        return $refusal;
    }

    /** The customer declines (or closes the popup and comes back): the visitor's chat closes. */
    public static function decline(User $user): void
    {
        $pending = self::pending($user);
        if ($pending !== null) {
            LiveChat::query()->whereKey($pending->id)->where('handoff_user_id', $user->id)->where('status', '!=', LiveChat::CLOSED)
                ->update(['status' => LiveChat::CLOSED, 'closed_at' => now(), 'visitor_token_hash' => null, 'updated_at' => now()]);
        }
        ChatVisitor::forget();
    }

    /** The frozen chats no one confirmed in HANDOFF_MINUTES close (the minute's command). Returns how many. */
    public static function expireHandoffs(): int
    {
        return LiveChat::query()->whereNotNull('handoff_user_id')->whereNull('client_id')->where('status', '!=', LiveChat::CLOSED)
            ->where('handoff_at', '<=', now()->subMinutes(self::HANDOFF_MINUTES))
            ->update(['status' => LiveChat::CLOSED, 'closed_at' => now(), 'visitor_token_hash' => null, 'updated_at' => now()]);
    }

    // ---- the manual link (§6α)

    /** Tries of the member's password per quarter-hour. */
    public const LINK_TRIES = 5;

    /** Null when linked; else what to tell the member. */
    public static function link(Staff $staff, int $chatId, Client $client, string $password): ?string
    {
        if (!$staff->hasPermission('admin.chats.link')) {
            return __('You may not link chats.');
        }
        $key = 'live-chat-link-reauth:' . $staff->id;
        if (RateLimiter::tooManyAttempts($key, self::LINK_TRIES)) {
            return __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]);
        }
        if ($password === '' || !Hash::check($password, (string) $staff->password)) {
            RateLimiter::hit($key, 900);

            return __('The password is not right.');
        }
        $chat = LiveChat::query()->find($chatId);
        if ($chat === null || !LiveChats::canSee($staff, $chat) || !$chat->isVisitor()) {
            return __('No visitor\'s chat with this number.');
        }
        if ($chat->isOpen()) {
            return __('Only a closed chat may be linked.');
        }
        $linked = LiveChat::query()->whereKey($chat->id)->whereNull('client_id')->where('status', LiveChat::CLOSED)
            ->update(['client_id' => $client->id, 'linked_by' => $staff->id, 'linked_at' => now(), 'visitor_token_hash' => null, 'updated_at' => now()]);
        if ($linked !== 1) {
            return __('No visitor\'s chat with this number.');
        }
        // In the client's audit, ids only (F12).
        $client->auditEvent = 'live_chat_linked';
        $client->isCustomEvent = true;
        $client->auditCustomOld = [];
        $client->auditCustomNew = ['live_chat_id' => $chat->id, 'staff_id' => $staff->id];
        event(new AuditCustom($client));

        return null;
    }

    /** The member holds the visitor's active chat. */
    private static function holding(Staff $staff, LiveChat $chat): bool
    {
        return LiveChats::canReply($staff, $chat) && $chat->isVisitor() && $chat->status === LiveChat::ACTIVE && $chat->assigned_to === $staff->id;
    }

    private static function blockKey(string $ip): string
    {
        return 'live-chat-blocked:' . $ip;
    }
}
