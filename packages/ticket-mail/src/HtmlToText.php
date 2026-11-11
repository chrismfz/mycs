<?php

namespace MyCS\TicketMail;

/**
 * An HTML-only email as plain text (docs/tickets.md §3 ΣΤ, after Ekdosi's): scripts, styles and head dropped, blocks as
 * line breaks, tags stripped, entities decoded. Nothing of the HTML survives, so no remote image or tracker is ever
 * loaded; what is shown is this text, escaped.
 *
 * Anyone may send the HTML, so every step reads it once (sweep 8, S8-TICKETS-MAIL-1): no pattern here may start at
 * each of N places and read to the end from each (`<head>` ten thousand times with no `</head>` held the poller for
 * minutes). What has no end is looked for once, not once per opening.
 */
final class HtmlToText
{
    /** Elements dropped with what they hold. */
    private const DROPPED = ['script', 'style', 'head', 'title'];

    public static function convert(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        $html = self::withoutComments(self::withoutDropped($html));
        // Each step keeps the previous value when a regex gives up, never an empty message.
        $html = preg_replace('#</(p|blockquote)\s*>#i', "\n\n", $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(div|tr|h[1-6]|li)\s*>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(td|th)\s*>#i', ' ', $html) ?? $html;
        [$html, $rest] = self::untilLastTagEnd($html);
        $html = (preg_replace('#<li\b[^>]*>#i', '• ', $html) ?? $html) . $rest;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $text);
        // Possessive, and only from the start of a run of blanks: a run with no line break after it is read once.
        $text = preg_replace('/(?<![ \t])[ \t]++\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Without `<script|style|head|title …>…</the same>`, the first closing tag ending each. An element with no closing
     * tag after it stays (its tags are stripped later, its text shown), and so does every later one of its kind: that
     * kind is looked for no more.
     */
    private static function withoutDropped(string $html): string
    {
        [$html, $rest] = self::untilLastTagEnd($html);
        $kinds = self::DROPPED;
        $kept = '';
        $offset = 0;
        while ($kinds !== [] && preg_match('#<(' . implode('|', $kinds) . ')\b[^>]*>#i', $html, $open, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $kind = strtolower($open[1][0]);
            $after = $open[0][1] + strlen($open[0][0]);
            if (preg_match('#</' . $kind . '\s*>#i', $html, $close, PREG_OFFSET_CAPTURE, $after) !== 1) {
                $kinds = array_values(array_diff($kinds, [$kind]));

                continue;
            }
            $kept .= substr($html, $offset, $open[0][1] - $offset);
            $offset = $close[0][1] + strlen($close[0][0]);
        }

        return $kept . substr($html, $offset) . $rest;
    }

    /** Without `<!-- … -->`; a comment that never ends stays for strip_tags. */
    private static function withoutComments(string $html): string
    {
        $kept = '';
        $offset = 0;
        while (($start = strpos($html, '<!--', $offset)) !== false && ($end = strpos($html, '-->', $start + 4)) !== false) {
            $kept .= substr($html, $offset, $start - $offset);
            $offset = $end + 3;
        }

        return $kept . substr($html, $offset);
    }

    /**
     * The HTML up to its last `>`, and what follows it. No tag ends in the second part, so a pattern of `<name[^>]*>`
     * is asked only of the first, where every `<name` finds its `>`: without this, `<li ` ten thousand times and no
     * `>` after them is read to the end ten thousand times.
     *
     * @return array{0: string, 1: string}
     */
    private static function untilLastTagEnd(string $html): array
    {
        $last = strrpos($html, '>');

        return $last === false ? ['', $html] : [substr($html, 0, $last + 1), substr($html, $last + 1)];
    }
}
