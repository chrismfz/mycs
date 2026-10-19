<?php

namespace MyCS\GrEpp\Command;

use InvalidArgumentException;
use MyCS\GrEpp\Data\DsRecord;

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
    public static function contactId(string $id, ?string $prefix = null): string
    {
        if (preg_match('/^[A-Za-z0-9]{3}_[A-Za-z0-9](?:-?[A-Za-z0-9])*\z/', $id) !== 1 || strlen($id) < 5 || strlen($id) > 16) {
            throw new InvalidArgumentException("Not a contact id the registry takes: {$id}");
        }
        // Ours, where it becomes a registrant or a new contact (only our own contacts are ours to use: the FAQ;
        // adversarial ADV-12 of #186). The prefix is the connection's (c60).
        if ($prefix !== null && strcasecmp(substr($id, 0, 4), "{$prefix}_") !== 0) {
            throw new InvalidArgumentException("{$id} is not one of our contacts ({$prefix}_…).");
        }

        return $id;
    }

    /** E.164 as «+CCC.N…» (1–3 digit country code; the number up to 15 digits with it: «Εγκυρότητα Αριθμών Τηλεφώνου»). */
    public static function phone(string $phone): string
    {
        if (preg_match('/^\+(\d{1,3})\.(\d{1,14})\z/', $phone, $m) !== 1 || strlen($m[1]) + strlen($m[2]) > 15) {
            throw new InvalidArgumentException("Not a phone number the registry takes (+CCC.NUMBER): {$phone}");
        }

        return $phone;
    }

    /**
     * Text the registry takes (the guide, changes item 9 and Appendix A): UTF-8 in Basic Latin, Latin-1 Supplement,
     * Greek and Coptic, Greek Extended, without their control and excluded characters.
     */
    public static function text(string $text, string $field, bool $multiline = false): string
    {
        // NFC first: decomposed Greek (α + U+0301, as some keyboards and pastes give it) is the same text, composed
        // (review 7 of #186).
        if (mb_check_encoding($text, 'UTF-8')) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }
        if (!$multiline && preg_match('/[\r\n]/', $text) === 1) {
            throw new InvalidArgumentException("The {$field} must be one line.");
        }
        if (mb_strlen($text) > 255) {
            throw new InvalidArgumentException("The {$field} is longer than 255 characters.");
        }
        if (!mb_check_encoding($text, 'UTF-8')
            || preg_match('/^[\x{0020}-\x{007E}\x{000A}\x{000D}\x{00A1}-\x{00AB}\x{00AD}-\x{00FF}\x{0080}-\x{0082}\x{0084}-\x{008C}\x{008E}\x{008F}\x{0091}-\x{0094}\x{0096}-\x{009C}\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]*$/u', $text) !== 1) {
            throw new InvalidArgumentException("The {$field} has characters the registry does not take (only Greek and Latin).");
        }

        return $text;
    }

    /** The Latin (int) postal info: ASCII only (the guide, contact:create; review 2 of #186). */
    public static function ascii(string $text, string $field): string
    {
        $text = self::text($text, $field);
        if (preg_match('/^[\x20-\x7E]*$/', $text) !== 1) {
            throw new InvalidArgumentException("The Latin {$field} must be ASCII only (no Greek, no accents).");
        }

        return $text;
    }

    /**
     * A domain or host name as sent: NFC, lower case, no trailing dot, in Unicode (the registry refuses punycode:
     * «xn--» labels are turned back, adversarial ADV-6 of #186; one that is not punycode is refused).
     */
    public static function name(string $name): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new InvalidArgumentException('A name has a control character.'); // before any trim() drops one silently
        }
        $name = rtrim(trim($name), '.');
        $labels = explode('.', $name);
        foreach ($labels as $i => $label) {
            if (str_starts_with(strtolower($label), 'xn--')) {
                $unicode = idn_to_utf8(strtolower($label), IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);
                if ($unicode === false || str_starts_with($unicode, 'xn--')) {
                    throw new InvalidArgumentException("Not a name the registry takes (punycode): {$name}");
                }
                $labels[$i] = $unicode;
            }
        }
        $name = implode('.', $labels);
        $name = mb_strtolower(\Normalizer::normalize($name, \Normalizer::FORM_C) ?: $name);
        if ($name === '' || !str_contains($name, '.')) {
            throw new InvalidArgumentException("Not a domain name: {$name}");
        }

        return self::text($name, 'name');
    }

    /** Another registrar's contact id (eppcom clIDType: 3–16), to remove from a domain (review 5 of #186). */
    public static function anyContactId(string $id): string
    {
        if (preg_match('/^\S{3,16}\z/', $id) !== 1) {
            throw new InvalidArgumentException("Not a contact id: {$id}");
        }

        return $id;
    }

    /** An email address (the registry mails the registrant at it). */
    public static function email(string $email): string
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException("Not an email address: {$email}");
        }

        return self::text($email, 'email');
    }

    /** A phone extension: digits only. */
    public static function extension(string $extension): string
    {
        if (preg_match('/^\d{1,10}\z/', $extension) !== 1) {
            throw new InvalidArgumentException("A phone extension is digits only: {$extension}");
        }

        return $extension;
    }

    /** A real calendar day (Y-m-d), not only its shape (review 9 of #186). */
    public static function day(string $day): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if ($date === false || $date->format('Y-m-d') !== $day) {
            throw new InvalidArgumentException("Not a day (Y-m-d): {$day}");
        }

        return $day;
    }

    /** @param  array<string, list<string>>  $contacts  only admin, tech, billing (review 4 of #186) */
    public static function roles(array $contacts): void
    {
        $unknown = array_diff(array_keys($contacts), ['admin', 'tech', 'billing']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Contact roles are admin, tech and billing, not: ' . implode(', ', $unknown));
        }
        foreach ($contacts as $role => $ids) {
            if (count($ids) !== count(array_unique($ids))) {
                throw new InvalidArgumentException("The same {$role} contact twice.");
            }
        }
    }

    /** Registration and renewal periods: even years, 2 to 10 (the registry's FAQ; grepp.md §8). */
    public static function years(int $years): int
    {
        if ($years < 2 || $years > 10 || $years % 2 !== 0) {
            throw new InvalidArgumentException("A .gr period is 2, 4, 6, 8 or 10 years, not {$years}.");
        }

        return $years;
    }

    /**
     * Nameservers of a domain: one normalised list, at most 16 (the guide; adversarial ADV-11 of #186).
     *
     * @param  list<string>  $hosts
     * @return list<string>
     */
    public static function nameservers(array $hosts): array
    {
        $hosts = array_values(array_unique(array_map([self::class, 'name'], $hosts)));
        if (count($hosts) > 16) {
            throw new InvalidArgumentException('At most 16 nameservers.');
        }

        return $hosts;
    }

    /** @param  array<string, list<string>>  $contacts  at most 11 in all (the guide) */
    public static function contactCount(array $contacts): void
    {
        if (array_sum(array_map('count', $contacts)) > 11) {
            throw new InvalidArgumentException('At most 11 contacts.');
        }
    }

    /** At least one change: an empty update is refused (2002, the guide's changes item 6). */
    public static function notEmpty(bool $changes): void
    {
        if (!$changes) {
            throw new InvalidArgumentException('An update without changes is refused by the registry.');
        }
    }

    /** The key algorithms the registry takes (its DNSSEC guide v1.5): 3, 5, 6, 7, 8, 10, 12, 13, 14. */
    public const DS_ALGORITHMS = [3, 5, 6, 7, 8, 10, 12, 13, 14];

    /** Its digest types and their hex lengths: SHA-1, SHA-256, GOST R 34.11-94, SHA-384. */
    public const DS_DIGESTS = [1 => 40, 2 => 64, 3 => 64, 4 => 96];

    /** At most this many DS records in one add or remove (2308). */
    public const DS_MAX = 16;

    /**
     * DS records as the registry takes them: a key tag 0–65535, an algorithm and digest type it knows, a digest of that
     * type's length in hex (upper-cased); none twice; at most 16.
     *
     * @param  list<DsRecord>  $records
     * @return list<DsRecord>
     */
    public static function dsRecords(array $records): array
    {
        if (count($records) > self::DS_MAX) {
            throw new InvalidArgumentException('At most ' . self::DS_MAX . ' DS records at once.');
        }
        $clean = [];
        foreach ($records as $record) {
            if ($record->keyTag < 0 || $record->keyTag > 65535) {
                throw new InvalidArgumentException("A DS key tag is 0 to 65535, not {$record->keyTag}.");
            }
            if (!in_array($record->algorithm, self::DS_ALGORITHMS, true)) {
                throw new InvalidArgumentException("The .gr registry does not take DNSSEC algorithm {$record->algorithm}.");
            }
            $length = self::DS_DIGESTS[$record->digestType] ?? null;
            if ($length === null) {
                throw new InvalidArgumentException("The .gr registry does not take digest type {$record->digestType}.");
            }
            $digest = strtoupper($record->digest);
            if (preg_match('/^[0-9A-F]{' . $length . '}$/', $digest) !== 1) {
                throw new InvalidArgumentException("A digest of type {$record->digestType} is {$length} hex digits.");
            }
            $record = new DsRecord($record->keyTag, $record->algorithm, $record->digestType, $digest);
            foreach ($clean as $seen) {
                if ($seen->same($record)) {
                    throw new InvalidArgumentException("The same DS record twice (key tag {$record->keyTag}).");
                }
            }
            $clean[] = $record;
        }

        return $clean;
    }
}
