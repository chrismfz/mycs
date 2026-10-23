<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Chat text in and out (docs/livechat.md §3 Α, F5, F6): whoever wrote it, it is untrusted. In: control and format
 * characters (bidi overrides, zero-width) go, and a message is at most MAX characters. Out: plain text, escaped, with
 * its line breaks; a bare http(s) address becomes a link that shows its whole address and opens elsewhere. No markdown,
 * no images, no HTML of anyone's.
 *
 * render() is the one `{!! !!}` the chat's views may use (StaffChatTest checks the views).
 */
class ChatText
{
    public const MAX = 2000;

    /**
     * Control characters but the line break, and Unicode format characters (\p{Cf}); the zero-width joiner only where
     * it does not join a pictograph (or its emoji selector or skin tone) to the next, so 👩‍💻 or ❤️‍🔥 keeps it (review
     * of #373).
     */
    private const STRIP = '/[\x00-\x09\x0B-\x1F\x7F]|(?!(?<=[\p{Extended_Pictographic}\x{FE0F}\x{1F3FB}-\x{1F3FF}])\x{200D}(?=\p{Extended_Pictographic}))\p{Cf}/u';

    /** A bare address, found in the raw text (never in escaped text, where it would swallow `&quot;`: review of #373). */
    private const URL = '~(https?://[^\s<>"\'`.,;:!?()]+[^\s<>"\'`]*)~i';

    /** The text as it will be kept, or null when nothing is left to send. */
    public static function clean(string $text): ?string
    {
        $text = (string) preg_replace(self::STRIP, '', str_replace(["\r\n", "\r"], "\n", $text));
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));

        return $text === '' ? null : mb_substr($text, 0, self::MAX);
    }

    public static function render(string $text): HtmlString
    {
        $html = '';
        foreach (preg_split(self::URL, (string) preg_replace(self::STRIP, '', $text), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $index => $piece) {
            if ($index % 2 === 0) {
                $html .= e($piece);

                continue;
            }
            // Trailing punctuation belongs to the sentence, not the address.
            $url = rtrim($piece, '.,;:!?)');
            $html .= '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer nofollow" class="underline break-all">' . e($url) . '</a>'
                . e(substr($piece, strlen($url)));
        }

        return new HtmlString(nl2br($html, false));
    }
}
