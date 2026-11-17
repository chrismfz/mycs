<?php

namespace App\Support;

use App\Models\LiveChat;
use App\Models\LiveChatMessage;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * An anonymous visitor's hold on their chat (docs/livechat.md §3 Γ, step 4): a random 256-bit token that lives only in
 * the visitor's server session, never in a Livewire property, the HTML, a URL or a log (F7); the chat keeps only its
 * SHA-256. The chat's id alone reaches nothing: every call finds the chat from the session's token (F2, F7).
 *
 * The token lasts `livechat_visitor_hours` (default 24) from the visitor's last message, and never longer than the
 * session. It goes on close of the session (logout), on «Login as client» (F9) and when staff revoke it (§6α).
 *
 * Also the two labels a visitor's chat carries for the staff (§4): the site, only from the settings' list, and the
 * page's path, cleaned. Both are only text for the staff, printed escaped and never as a link.
 */
class ChatVisitor
{
    public const SESSION_KEY = 'live_chat_visitor';

    /** Set when the session's chat was found closed: the popup says it ended, and shows nothing of it (§3 Γ, F22). */
    public const ENDED_KEY = 'live_chat_visitor_ended';

    /**
     * Until when this session may start or write no visitor's chat: an operator blocked its chat (§3 Ε), so another
     * network from the same browser is no way back (ADV-889-1). Kept through a login, gone with the session.
     */
    public const BLOCKED_KEY = 'live_chat_visitor_blocked_until';

    /** Set by the popup's «Sign in», taken by the login: a login begun there never remembers the browser (G5). */
    public const LOGIN_KEY = 'live_chat_login';

    /** The longest page path kept (§4). */
    public const PAGE_MAX = 255;

    public static function guestEnabled(): bool
    {
        return LiveChats::enabled() && (bool) config('settings.livechat_guest_enabled', false);
    }

    /** Hours a visitor's token holds after their last message. */
    public static function hours(): int
    {
        return max(1, (int) config('settings.livechat_visitor_hours', 24));
    }

    /**
     * Gives the chat a new token, held by this session only. The session is renewed first, its old id destroyed (F8): a
     * session id someone planted or saw before the chat began never reaches it. Any token the session held before goes.
     */
    public static function issue(LiveChat $chat, ?Session $session = null): void
    {
        if ($chat->client_id !== null) {
            throw new \LogicException('A chat with a client is never a visitor\'s.');
        }
        $session ??= session()->driver();
        $session->regenerate(true);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $chat->forceFill(['visitor_token_hash' => self::hash($token), 'started_anonymously' => true])->save();
        $session->put(self::SESSION_KEY, $token);
        $session->forget(self::ENDED_KEY);
    }

    /**
     * The visitor's open chat of this session, while its token holds; else null. Never with a customer signed in or
     * staff in «Login as client» in the same session (§3 Δ, G2, F9): the token stays there, unused, for the move to a
     * customer's chat to find (holds()). A closed chat takes its token with it (§3 Γ, F22): the next one at the same
     * browser reads nothing of it, only that it ended (ended()). An expired or revoked token leaves the session. Never
     * a chat with a client (linked by staff: §6α), nor one frozen by a login for the move to a customer's chat (G2).
     */
    public static function chat(?Session $session = null): ?LiveChat
    {
        $session ??= session()->driver();
        $token = $session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            return null;
        }
        if (Auth::guard('web')->check() || $session->has('impersonating')) {
            return null;
        }
        $chat = LiveChat::query()->where('visitor_token_hash', self::hash($token))->whereNull('client_id')
            ->whereNull('handoff_user_id')->first();
        $since = $chat === null ? null : ($chat->last_participant_message_at ?? $chat->created_at);
        if ($chat === null || $since === null || $since->copy()->addHours(self::hours())->isPast()) {
            $session->forget(self::SESSION_KEY);

            return null;
        }
        if (!$chat->isOpen()) {
            $session->forget(self::SESSION_KEY);
            $session->put(self::ENDED_KEY, true);

            return null;
        }

        return $chat;
    }

    /** The chat's token, wherever it is held, is blocked until $until (VisitorChatTools::block). */
    public static function blockToken(LiveChat $chat, \DateTimeInterface $until): void
    {
        if ($chat->visitor_token_hash !== null) {
            Cache::put('live-chat-blocked-token:' . $chat->visitor_token_hash, $until->getTimestamp(), $until);
        }
    }

    /** This session was blocked: by its mark, or by the token of a chat an operator blocked (then marked now). */
    public static function blockedHere(?Session $session = null): bool
    {
        $session ??= session()->driver();
        $until = $session->get(self::BLOCKED_KEY);
        if (is_int($until) && $until > time()) {
            return true;
        }
        $token = $session->get(self::SESSION_KEY);
        $until = is_string($token) && $token !== '' ? Cache::get('live-chat-blocked-token:' . self::hash($token)) : null;
        if (is_int($until) && $until > time()) {
            $session->put(self::BLOCKED_KEY, $until);

            return true;
        }

        return false;
    }

    /** The session's chat closed since it was last seen: what the popup says instead of showing it. */
    public static function ended(?Session $session = null): bool
    {
        return (bool) ($session ?? session()->driver())->get(self::ENDED_KEY, false);
    }

    /**
     * What the visitor may read of their chat, oldest first: their own and the operators' messages, never the system's
     * or the staff's notes (F20); an allowlist in the query (LiveChatMessage::VISITOR_VISIBLE).
     *
     * @return Collection<int, LiveChatMessage>
     */
    public static function messages(LiveChat $chat): Collection
    {
        return $chat->messages()->with('staff:id,first_name')->whereIn('author_type', LiveChatMessage::VISITOR_VISIBLE)
            ->orderByDesc('id')->limit(LiveChats::WINDOW)->get()->reverse()->values();
    }

    /** This session holds a visitor's token, whether or not it still opens a chat. */
    public static function holds(?Session $session = null): bool
    {
        return ($session ?? session()->driver())->has(self::SESSION_KEY);
    }

    /** The session lets go of its token (the chat stays; another visit starts afresh). */
    public static function forget(?Session $session = null): void
    {
        ($session ?? session()->driver())->forget([self::SESSION_KEY, self::ENDED_KEY]);
    }

    /** The chat's token stops working, wherever it is held (§6α, F2); `started_anonymously` stays. */
    public static function revoke(LiveChat $chat): void
    {
        LiveChat::query()->whereKey($chat->id)->update(['visitor_token_hash' => null]);
        $chat->visitor_token_hash = null;
    }

    /**
     * The site a chat came from, as the settings' list names it, or this instance's own host (§4, F17); null for anything else. Compared without
     * case, kept as listed.
     */
    public static function site(?string $src): ?string
    {
        $src = strtolower(trim((string) $src));
        if ($src === '') {
            return null;
        }
        // This instance's own host too: the «Chat» button on MyCS's own pages names it (owner 2026-11-17).
        $own = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        foreach ([...(array) config('settings.livechat_sites', []), $own] as $site) {
            if (is_string($site) && strtolower(trim($site)) === $src) {
                return trim($site);
            }
        }

        return null;
    }

    /**
     * The page the chat was opened from (§4): a path only, without query or fragment, at most PAGE_MAX characters, with
     * no control, format (bidi, zero-width), private, unassigned, blank-letter (Hangul fillers) or space characters. Null when that is not what came.
     */
    public static function page(?string $page): ?string
    {
        $page = (string) $page;
        $page = substr($page, 0, (int) strcspn($page, '?#'));
        if (!str_starts_with($page, '/') || str_starts_with($page, '//') || !mb_check_encoding($page, 'UTF-8')
            || preg_match('/[\x00-\x20\x7F\p{Cc}\p{Cf}\p{Co}\p{Cn}\p{Z}\x{115F}\x{1160}\x{3164}\x{FFA0}\\\\]/u', $page) === 1) {
            return null;
        }

        return mb_substr($page, 0, self::PAGE_MAX);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
