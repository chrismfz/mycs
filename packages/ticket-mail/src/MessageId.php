<?php

namespace MyCS\TicketMail;

/**
 * The Message-IDs of our mail (docs/tickets.md §3 Α): `tk.{128 random bits}@{domain}`, the only thread keys. A reply
 * joins a ticket only when one of its In-Reply-To / References is exactly one we generated and stored: never a number,
 * never a subject.
 */
final class MessageId
{
    private const OURS = '/^tk\.[0-9a-f]{32}@[a-z0-9.-]+$/';

    /** A new Message-ID of ours (without angle brackets). */
    public static function generate(string $domain): string
    {
        return 'tk.' . bin2hex(random_bytes(16)) . '@' . strtolower($domain);
    }

    /** Ours by its form: what a stored id must look like to thread (a guessable one never is). */
    public static function isOurs(string $id): bool
    {
        return preg_match(self::OURS, $id) === 1;
    }

    /** As stored and compared: trimmed, without angle brackets; null when empty or absurd. */
    public static function normalise(?string $id): ?string
    {
        $id = trim(trim((string) $id), '<>');
        $id = trim($id);

        return $id === '' || strlen($id) > 250 || preg_match('/[\s<>]/', $id) === 1 ? null : $id;
    }

    /**
     * The ids of an In-Reply-To or References header: `<a@b> <c@d>`, also without brackets or comma separated.
     *
     * @return list<string>
     */
    public static function listFrom(?string $header): array
    {
        $header = (string) $header;
        if (preg_match_all('/<([^<>\s]+)>/', $header, $matches) > 0) {
            $parts = $matches[1];
        } else {
            $parts = preg_split('/[\s,]+/', trim($header)) ?: [];
        }

        $ids = [];
        foreach ($parts as $part) {
            if (($id = self::normalise($part)) !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
