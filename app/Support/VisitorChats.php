<?php

namespace App\Support;

use App\Livewire\Contact;
use App\Models\LiveChat;
use App\Models\LiveChatMessage;
use App\Models\TicketDepartment;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Chats of anonymous visitors from our sites (docs/livechat.md §3, §4, §7 «Επισκέπτης», step 4): started from the popup
 * /chat, reached afterwards only through the token in the visitor's session (ChatVisitor). Everything the visitor sends
 * is checked here, on the server, on every call: the department (F17), the name and email (§3 Β, F6), the limits per
 * network address and per chat, counted before they are checked (§3 Ε, F13). A session with a customer, or with staff
 * in «Login as client», never starts one (§3 Δ, F9).
 */
class VisitorChats
{
    /** Chats one network address may start in 10 minutes, and keep open at once (§3 Ε). */
    public const STARTS_PER_10_MINUTES = 3;

    public const OPEN_PER_IP = 3;

    /** A visitor's messages a minute, per chat (its token), and from one network address over all its chats. */
    public const PER_MINUTE = 20;

    public const PER_MINUTE_PER_IP = 40;

    public const NAME_MAX = 60;

    public const EMAIL_MAX = 190;

    /**
     * Departments a visitor may chat with: offered to guests (not hidden, not for clients only) and with someone listed
     * to answer (F17).
     *
     * @return Collection<int, TicketDepartment>
     */
    public static function departments(): Collection
    {
        return TicketDepartment::query()->offered(guest: true)->whereHas('chatStaff')->get(['id', 'name']);
    }

    /** @return Collection<int, TicketDepartment> those of departments() with someone available now (§5α) */
    public static function availableDepartments(): Collection
    {
        return self::departments()->filter(fn (TicketDepartment $department) => LiveChats::availableIn($department->id))->values();
    }

    /**
     * The department a chat goes to: the one asked for when a visitor may chat there and someone is available, else the
     * first that has someone (F17: a hidden or clients-only department, or one asked for in the launcher's link, is only
     * a wish). Null when no one is available at all.
     */
    public static function departmentFor(?int $wanted): ?TicketDepartment
    {
        $available = self::availableDepartments();

        return $available->firstWhere('id', $wanted) ?? $available->first();
    }

    /** This session may write as a visitor: the setting on, no customer signed in, no staff in «Login as client», its address and session not blocked. */
    public static function may(): bool
    {
        return ChatVisitor::guestEnabled() && !Auth::guard('web')->check() && !Actor::impersonating()
            && !VisitorChatTools::blocked(CallerIp::key()) && !ChatVisitor::blockedHere();
    }

    /**
     * The «Chat» button on MyCS's own pages for a visitor (step 4, owner 2026-11-17): while they may chat (may(): on, not
     * signed in, not «Login as client», not blocked) and someone is available in a department that takes visitors, by
     * the client area's cached availability (LiveChats::availableDepartmentIds). A page a little stale may show it; the
     * popup then says no one is there.
     */
    public static function buttonShown(): bool
    {
        if (!self::may()) {
            return false;
        }
        $available = LiveChats::availableDepartmentIds();

        return $available !== [] && TicketDepartment::query()->offered(guest: true)->whereKey($available)->exists();
    }

    /** A visitor's name as kept: one line, no control or format characters; null when empty or too long (§3 Β). */
    public static function name(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F\p{Cc}\p{Cf}]/u', $name) === 1 || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > self::NAME_MAX) {
            return null;
        }

        return (string) preg_replace('/\s+/u', ' ', $name);
    }

    /** A visitor's email as kept: one we could mail (F6) and not one of ours, lower case; null when empty or not one (§3 Β). */
    public static function email(?string $email): ?string
    {
        $email = trim((string) $email);
        if ($email === '' || strlen($email) > self::EMAIL_MAX || preg_match('/[\x00-\x20\x7F\p{Cc}\p{Cf}"\'<>`\\\\]/u', $email) === 1
            || !Contact::mailable($email) || Contact::ours($email)) {
            return null;
        }

        return strtolower($email);
    }

    /**
     * Starts a visitor's chat: what they typed is checked here, the department chosen here, the session renewed and given
     * the chat's token. The caller has checked the captcha. Null when started; else what to tell the visitor.
     *
     * @param  array{department?: int|null, name?: string|null, email?: string|null, message?: string, src?: string|null, page?: string|null}  $input
     */
    public static function start(array $input): ?string
    {
        if (!self::may()) {
            return __('Live chat is not available.');
        }
        if (ChatVisitor::chat() !== null) {
            return __('Your chat is already open.');
        }
        $name = self::name($input['name'] ?? null);
        if ($name === null && trim((string) ($input['name'] ?? '')) !== '') {
            return __('The name may have up to :max characters, on one line.', ['max' => self::NAME_MAX]);
        }
        $email = self::email($input['email'] ?? null);
        if ($email === null && trim((string) ($input['email'] ?? '')) !== '') {
            return __('This email address is not valid.');
        }
        $body = ChatText::clean((string) ($input['message'] ?? ''));
        if ($body === null) {
            return __('The message is empty.');
        }
        $department = self::departmentFor(isset($input['department']) ? (int) $input['department'] : null);
        if ($department === null) {
            return __('No one is available in this department right now.');
        }
        $ip = CallerIp::key();
        // Counted first, then checked (F13): per network address, whatever session or browser it uses.
        if (self::over('live-chat-visitor-start:' . $ip, 600, self::STARTS_PER_10_MINUTES)) {
            return __('Too many chats started, wait a few minutes.');
        }

        // Open chats per address, even when two start at once (F13).
        try {
            $chat = Cache::lock('live-chat-visitor-open:' . $ip, 10)->block(5, function () use ($ip, $department, $name, $email, $body, $input) {
                if (LiveChat::query()->where('ip', $ip)->whereNull('client_id')->where('status', '!=', LiveChat::CLOSED)->count() >= self::OPEN_PER_IP) {
                    return null;
                }

                return DB::transaction(function () use ($ip, $department, $name, $email, $body, $input) {
                    $chat = LiveChat::query()->create([
                        'department_id' => $department->id,
                        'status' => LiveChat::WAITING,
                        'started_anonymously' => true,
                        'visitor_name' => $name,
                        'visitor_email' => $email,
                        'ip' => $ip,
                        'src' => ChatVisitor::site($input['src'] ?? null),
                        'page' => ChatVisitor::page($input['page'] ?? null),
                        'last_participant_message_at' => now(),
                    ]);
                    $chat->messages()->create(['author_type' => LiveChatMessage::VISITOR, 'body' => $body]);

                    return $chat;
                });
            });
        } catch (LockTimeoutException) {
            $chat = null; // the address is busy starting another: as over its limit
        }
        if (!$chat instanceof LiveChat) {
            return __('Too many chats are open from your network, wait a few minutes.');
        }
        // A new session id, the old one destroyed, before the token goes in it (F8).
        ChatVisitor::issue($chat);

        return null;
    }

    /** The visitor's message in their chat. Null when sent; else what to tell them. */
    public static function post(LiveChat $chat, string $text): ?string
    {
        if (!self::may() || ChatVisitor::chat()?->id !== $chat->id) {
            return __('You may not write here.');
        }
        $body = ChatText::clean($text);
        if ($body === null) {
            return __('The message is empty.');
        }
        if (self::over('live-chat-visitor:' . $chat->id, 60, self::PER_MINUTE)
            || self::over('live-chat-visitor-ip:' . CallerIp::key(), 60, self::PER_MINUTE_PER_IP)) {
            return __('Too many messages, wait a moment.');
        }

        return LiveChats::write($chat, ['author_type' => LiveChatMessage::VISITOR, 'body' => $body], participant: true);
    }

    /** The visitor ends their chat; the popup then says it ended (ChatVisitor::ended()). */
    public static function end(LiveChat $chat): void
    {
        if (self::may() && ChatVisitor::chat()?->id === $chat->id) {
            LiveChat::query()->whereKey($chat->id)->where('status', '!=', LiveChat::CLOSED)
                ->update(['status' => LiveChat::CLOSED, 'closed_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Counted first, then checked (F13). */
    private static function over(string $key, int $seconds, int $limit): bool
    {
        RateLimiter::hit($key, $seconds);

        return RateLimiter::attempts($key) > $limit;
    }
}
