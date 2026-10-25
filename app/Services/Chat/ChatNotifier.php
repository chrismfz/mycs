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

    /** A link in the chat's markdown, its text without what would break it. */
    public static function link(string $text, string $url): string
    {
        return '[' . str_replace(['[', ']'], ['(', ')'], $text) . '](' . $url . ')';
    }
}
