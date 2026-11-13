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

    /** The header block as it came, valid UTF-8 (a mail's 8-bit bytes scrubbed), cut to MAX_BYTES. */
    public static function block(string $raw): string
    {
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);
        $block = str_replace("\r\n", "\n", (string) ($parts[0] ?? ''));

        return mb_strcut(mb_scrub($block, 'UTF-8'), 0, self::MAX_BYTES, 'UTF-8');
    }

    /**
     * «mail.example.gr [203.0.113.25]»: who handed the mail to our server, from our own topmost Received. Lines our
     * server added on its loopback are passed over; the first other line is ours, and when it names no address (a local
     * submission) there is none: every line under it is the sender's to write (adversarial review of #846). The host
     * is only the reverse name our server looked up, never the name the sender said hello with. Our delivery agent's
     * line («by orion with LMTP», Dovecot on cPanel and mailcow) is passed over too: the one under it is our MTA's
     * (review 3 of #846, seen on orion's mail).
     */
    public static function sendingServer(string $raw): ?string
    {
        foreach (self::received(self::block($raw)) as $line) {
            if (preg_match('/\sby\s+\S+\s+with\s+LMTPS?\b/i', $line)) {
                continue;
            }
            // What our server says of the sender: before «by», without what the sender chose (helo=, ident=).
            $from = preg_split('/\s+by\s+/i', $line, 2)[0];
            $from = preg_replace('/\b(?:helo|ident)=[^\s)]+/i', '', $from) ?? $from;
            if (!preg_match('/^from\s+(\S+)\s*(?:\(([^)]*)\))?/i', trim($from), $m)) {
                return null;
            }
            [$first, $comment] = [$m[1], trim($m[2] ?? '')];
            $ip = self::address($comment) ?? (str_starts_with($first, '[') ? self::address($first) : null);
            if ($ip === null) {
                // «from localhost» with no address: ours re-injecting; anything else names no one we can trust.
                if (strcasecmp($first, 'localhost') === 0) {
                    continue;
                }

                return null;
            }
            if (self::isLoopback($ip)) {
                continue;
            }
            $host = '';
            if (preg_match('/^([A-Za-z0-9.-]+\.[A-Za-z]{2,})\s*\[/', $comment, $name)) {
                $host = $name[1];   // Postfix: «(rdns [ip])»; «unknown» has no dot
            } elseif (str_starts_with($comment, '[') && !str_starts_with($first, '[')) {
                $host = $first;     // Exim: «from rdns ([ip]:port)»
            }

            return mb_substr(trim($host . ' [' . $ip . ']'), 0, 200);
        }

        return null;
    }

    /** 127/8 and ::1, also as an IPv4-mapped address: our server talking to itself (a filter, a local delivery). */
    private static function isLoopback(string $ip): bool
    {
        $ip = (string) preg_replace('/^::ffff:/i', '', $ip);

        return $ip === '::1' || str_starts_with($ip, '127.');
    }

    /** The first bracketed address in $text, or null. */
    private static function address(string $text): ?string
    {
        if (!preg_match('/\[(?:IPv6:)?([0-9a-fA-F:.]+)\]/', $text, $m) || filter_var($m[1], FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $m[1];
    }

    /** @return list<string> the Received headers, top first, each on one line */
    private static function received(string $block): array
    {
        $unfolded = preg_replace("/\n[ \t]+/", ' ', $block) ?? $block;
        preg_match_all('/^Received:\s*(.*)$/mi', $unfolded, $m);

        return array_map('trim', $m[1]);
    }
}
