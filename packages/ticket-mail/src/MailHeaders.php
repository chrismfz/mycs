<?php

namespace MyCS\TicketMail;

/**
 * What staff read of a mail's headers beside its message (owner 2026-11-13: «is it legit?»): the header block as it came,
 * and the server it came from as our own server recorded it. The sender writes every other header; the topmost
 * Received with an address is our server's own line, so the one that says who handed the mail to us.
 */
final class MailHeaders
{
    /** The longest header block kept: a mail with more is cut, its first lines are the ones that matter. */
    public const MAX_BYTES = 32_768;

    /** The header block, lines unfolded as they came, cut to MAX_BYTES. */
    public static function block(string $raw): string
    {
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
        $block = str_replace("\r\n", "\n", (string) ($parts[0] ?? ''));

        return mb_strcut($block, 0, self::MAX_BYTES, 'UTF-8');
    }

    /**
     * «mail.example.gr [203.0.113.25]»: the host and address of the first Received that names an address other than our
     * own loopback, or null when none does.
     */
    public static function sendingServer(string $raw): ?string
    {
        foreach (self::received(self::block($raw)) as $line) {
            if (!preg_match('/^from\s+(\S+)(?:\s+\(([^)]*)\))?/i', $line, $m)) {
                continue;
            }
            $comment = $m[2] ?? '';
            if (!preg_match('/\[(?:IPv6:)?([0-9a-fA-F:.]+)\]/', $comment . ' ' . $m[1], $ip) || filter_var($ip[1], FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (in_array($ip[1], ['127.0.0.1', '::1'], true)) {
                continue;
            }
            // The reverse name in the comment when there is one, else the name it said hello with.
            $host = preg_match('/^([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', trim($comment), $name) ? $name[1] : (str_starts_with($m[1], '[') ? '' : $m[1]);
            $host = $host === $ip[1] ? '' : $host;

            return mb_substr(trim($host . ' [' . $ip[1] . ']'), 0, 200);
        }

        return null;
    }

    /** @return list<string> the Received headers, top first, each on one line */
    private static function received(string $block): array
    {
        $unfolded = preg_replace("/\n[ \t]+/", ' ', $block) ?? $block;
        preg_match_all('/^Received:\s*(.*)$/mi', $unfolded, $m);

        return array_map('trim', $m[1]);
    }
}
