<?php

namespace MyCS\GrEpp\Command;

use InvalidArgumentException;

/**
 * The registry's rules checked before anything is sent (the guide v4.3): a request that breaks one is refused (2306,
 * 2308) and would only cost a round trip; here it is an InvalidArgumentException, nothing sent.
 */
final class Rules
{
    /**
     * Contact ids: «prefix_» then letters, digits and «-» (not first or last, never two together), 5 to 16 in all
     * («Εντολή <create>» of contacts).
     */
    public static function contactId(string $id): string
    {
        if (preg_match('/^[A-Za-z0-9]{3}_[A-Za-z0-9](?:-?[A-Za-z0-9])*$/', $id) !== 1 || strlen($id) < 5 || strlen($id) > 16) {
            throw new InvalidArgumentException("Not a contact id the registry takes: {$id}");
        }

        return $id;
    }

    /** E.164 as «+CCC.N…» (1–3 digit country code; the number up to 15 digits with it: «Εγκυρότητα Αριθμών Τηλεφώνου»). */
    public static function phone(string $phone): string
    {
        if (preg_match('/^\+(\d{1,3})\.(\d{1,14})$/', $phone, $m) !== 1 || strlen($m[1]) + strlen($m[2]) > 15) {
            throw new InvalidArgumentException("Not a phone number the registry takes (+CCC.NUMBER): {$phone}");
        }

        return $phone;
    }

    /**
     * Text the registry takes (the guide, changes item 9 and Appendix A): UTF-8 in Basic Latin, Latin-1 Supplement,
     * Greek and Coptic, Greek Extended, without their control and excluded characters.
     */
    public static function text(string $text, string $field): string
    {
        if (!mb_check_encoding($text, 'UTF-8')
            || preg_match('/^[\x{0020}-\x{007E}\x{000A}\x{000D}\x{00A1}-\x{00AB}\x{00AD}-\x{00FF}\x{0080}-\x{0082}\x{0084}-\x{008C}\x{008E}\x{008F}\x{0091}-\x{0094}\x{0096}-\x{009C}\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]*$/u', $text) !== 1) {
            throw new InvalidArgumentException("The {$field} has characters the registry does not take (only Greek and Latin).");
        }

        return $text;
    }

    /** Registration and renewal periods: even years, 2 to 10 (the registry's FAQ; grepp.md §8). */
    public static function years(int $years): int
    {
        if ($years < 2 || $years > 10 || $years % 2 !== 0) {
            throw new InvalidArgumentException("A .gr period is 2, 4, 6, 8 or 10 years, not {$years}.");
        }

        return $years;
    }

    /** At least one change: an empty update is refused (2002, the guide's changes item 6). */
    public static function notEmpty(bool $changes): void
    {
        if (!$changes) {
            throw new InvalidArgumentException('An update without changes is refused by the registry.');
        }
    }
}
