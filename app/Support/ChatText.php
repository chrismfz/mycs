<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Chat text in and out (docs/livechat.md §3 Α, F5, F6): whoever wrote it, it is untrusted. In: control and format
 * characters (bidi overrides, zero-width) go, and a message is at most MAX characters. Out: plain text, escaped, with
 * its line breaks; a bare http(s) address becomes a link that shows its whole address and opens elsewhere. No markdown,
 * no images, no HTML of anyone's.
 *
 * render() is the one `{!! !!}` the chat's views may use (ChatTextTest checks the views).
 */
class ChatText
{
    public const MAX = 2000;

    /** Control characters but the line break, and Unicode format characters (\p{Cf}). */
    private const STRIP = '/[\x00-\x09\x0B-\x1F\x7F\p{Cf}]/u';

    /** The text as it will be kept, or null when nothing is left to send. */
    public static function clean(string $text): ?string
    {
        $text = (string) preg_replace(self::STRIP, '', str_replace(["\r\n", "\r"], "\n", $text));
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));

        return $text === '' ? null : mb_substr($text, 0, self::MAX);
    }

    public static function render(string $text): HtmlString
    {
        $escaped = e((string) preg_replace(self::STRIP, '', $text));
        $linked = preg_replace_callback(
            '~\bhttps?://[^\s<>"\']+~i',
            function (array $match): string {
                // Trailing punctuation belongs to the sentence, not the address.
                $url = rtrim($match[0], '.,;:!?)');
                $rest = substr($match[0], strlen($url));

                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer nofollow" class="underline break-all">' . $url . '</a>' . $rest;
            },
            $escaped,
        );

        return new HtmlString(nl2br((string) $linked, false));
    }
}
