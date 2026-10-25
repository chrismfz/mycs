<?php

namespace App\Services\Chat;

use App\Jobs\SendChatNotification;
use App\Models\ChatNotificationRule;
use Throwable;

/**
 * Tells each enabled rule that asks for $event: one queued post each, after the change is committed. The text is made
 * only when a rule asks (no query, no relation loaded, otherwise).
 */
final class ChatNotifier
{
    /** @param  string|callable(): string  $text */
    public static function notify(string $event, string|callable $text): void
    {
        try {
            $rules = ChatNotificationRule::query()->where('enabled', true)->get()
                ->filter(fn (ChatNotificationRule $rule) => in_array($event, (array) $rule->events, true));
            if ($rules->isEmpty()) {
                return;
            }
            $text = is_string($text) ? $text : $text();
            foreach ($rules as $rule) {
                SendChatNotification::dispatch($rule->id, $text)->afterCommit();
            }
        } catch (Throwable $e) {
            report($e); // a chat never fails what it tells of
        }
    }

    /** A link in the chat's markdown; its text is escaped as any customer's text is. */
    public static function link(string $text, string $url): string
    {
        return '[' . self::escape($text) . '](' . $url . ')';
    }

    /**
     * A customer's text (a ticket's subject, a name, a reason) as plain text in the chat (review of #456): its mentions
     * broken (a zero-width space after «@», Slack's «<!…>» disarmed), so it pings no one, and its markdown escaped, so
     * it links nowhere.
     */
    public static function escape(string $text): string
    {
        $text = str_replace(["\r", "\n"], ' ', $text);
        $text = (string) preg_replace('/<!/', "<\u{200B}!", $text);
        $text = (string) preg_replace('/@/u', "@\u{200B}", $text);

        // Not «#»: a heading only at a line's start, and each message starts with its emoji.
        return (string) preg_replace('/([\\\\`*_\[\]()~>|!])/', '\\\\$1', $text);
    }
}
