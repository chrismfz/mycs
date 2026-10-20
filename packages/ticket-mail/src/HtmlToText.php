<?php

namespace MyCS\TicketMail;

/**
 * An HTML-only email as plain text (docs/tickets.md §3 ΣΤ, after Ekdosi's): scripts, styles and head dropped, blocks as
 * line breaks, tags stripped, entities decoded. Nothing of the HTML survives, so no remote image or tracker is ever
 * loaded; what is shown is this text, escaped.
 */
final class HtmlToText
{
    public static function convert(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        // Each step keeps the previous value when a regex gives up (a huge body), never an empty message.
        $html = preg_replace('#<(script|style|head|title)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
        $html = preg_replace('#<!--.*?-->#s', '', $html) ?? $html;
        $html = preg_replace('#</(p|blockquote)\s*>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(div|tr|h[1-6]|li)\s*>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(td|th)\s*>#i', ' ', $html) ?? $html;
        $html = preg_replace('#<li\b[^>]*>#i', '• ', $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
