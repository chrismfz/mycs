<?php

namespace App\Support;

use App\Models\LiveChat;
use Illuminate\Contracts\Session\Session;

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
        $session ??= session()->driver();
        $session->regenerate(true);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $chat->forceFill(['visitor_token_hash' => self::hash($token), 'started_anonymously' => true])->save();
        $session->put(self::SESSION_KEY, $token);
    }

    /**
     * The visitor's chat of this session, open or closed (the popup says when it ended), while its token holds; else null,
     * and an expired or revoked token leaves the session. Never a chat with a client (linked by staff: §6α).
     */
    public static function chat(?Session $session = null): ?LiveChat
    {
        $session ??= session()->driver();
        $token = $session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            return null;
        }
        $chat = LiveChat::query()->where('visitor_token_hash', self::hash($token))->whereNull('client_id')->first();
        $since = $chat === null ? null : ($chat->last_participant_message_at ?? $chat->created_at);
        if ($chat === null || $since === null || $since->copy()->addHours(self::hours())->isPast()) {
            $session->forget(self::SESSION_KEY);

            return null;
        }

        return $chat;
    }

    /** This session holds a visitor's token, whether or not it still opens a chat. */
    public static function holds(?Session $session = null): bool
    {
        return ($session ?? session()->driver())->has(self::SESSION_KEY);
    }

    /** The session lets go of its token (the chat stays; another visit starts afresh). */
    public static function forget(?Session $session = null): void
    {
        ($session ?? session()->driver())->forget(self::SESSION_KEY);
    }

    /** The chat's token stops working, wherever it is held (§6α, F2); `started_anonymously` stays. */
    public static function revoke(LiveChat $chat): void
    {
        LiveChat::query()->whereKey($chat->id)->update(['visitor_token_hash' => null]);
        $chat->visitor_token_hash = null;
    }

    /**
     * The site a chat came from, as the settings' list names it (§4, F17); null for anything else. Compared without
     * case, kept as listed.
     */
    public static function site(?string $src): ?string
    {
        $src = strtolower(trim((string) $src));
        if ($src === '') {
            return null;
        }
        foreach ((array) config('settings.livechat_sites', []) as $site) {
            if (is_string($site) && strtolower(trim($site)) === $src) {
                return trim($site);
            }
        }

        return null;
    }

    /**
     * The page the chat was opened from (§4): a path only, without query or fragment, at most PAGE_MAX characters, with
     * no control, format (bidi, zero-width) or space characters. Null when that is not what came.
     */
    public static function page(?string $page): ?string
    {
        $page = (string) $page;
        $page = substr($page, 0, (int) strcspn($page, '?#'));
        if (!str_starts_with($page, '/') || str_starts_with($page, '//') || !mb_check_encoding($page, 'UTF-8')
            || preg_match('/[\x00-\x20\x7F\p{Cc}\p{Cf}\p{Z}\\\\]/u', $page) === 1) {
            return null;
        }

        return mb_substr($page, 0, self::PAGE_MAX);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
