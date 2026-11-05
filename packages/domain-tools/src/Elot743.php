<?php

namespace Nixpal\DomainTools;

/**
 * Greek in Latin letters by ELOT 743 (the transcription Greek passports use): what registrars that take ASCII contact
 * data get (WG-23). «Θεσσαλονίκη» is «Thessaloniki», «ΕΥΑΓΓΕΛΟΣ» is «EVANGELOS», «Μπάμπης» is «Bampis» (μπ: b at a
 * word's start or end, mp inside). Accents and diaeresis go; a diaeresis or an accent on the first letter breaks a
 * digraph (άυλος: aylos, Ευρωπαϊκή: Evropaiki). Decomposed accents (NFD), polytonic letters, the Greek question mark
 * and ano teleia and «» are taken care of too (review of #669); anything else that is not Greek is left as it is.
 */
final class Elot743
{
    /** @var array<string, string> each letter's lower-case base, without its accent or diaeresis */
    private const BASE = [
        'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω', 'ϊ' => 'ι', 'ϋ' => 'υ', 'ΐ' => 'ι', 'ΰ' => 'υ',
        'Ά' => 'α', 'Έ' => 'ε', 'Ή' => 'η', 'Ί' => 'ι', 'Ό' => 'ο', 'Ύ' => 'υ', 'Ώ' => 'ω', 'Ϊ' => 'ι', 'Ϋ' => 'υ',
        'Α' => 'α', 'Β' => 'β', 'Γ' => 'γ', 'Δ' => 'δ', 'Ε' => 'ε', 'Ζ' => 'ζ', 'Η' => 'η', 'Θ' => 'θ', 'Ι' => 'ι', 'Κ' => 'κ', 'Λ' => 'λ',
        'Μ' => 'μ', 'Ν' => 'ν', 'Ξ' => 'ξ', 'Ο' => 'ο', 'Π' => 'π', 'Ρ' => 'ρ', 'Σ' => 'σ', 'Τ' => 'τ', 'Υ' => 'υ', 'Φ' => 'φ', 'Χ' => 'χ',
        'Ψ' => 'ψ', 'Ω' => 'ω', 'ς' => 'σ',
    ];

    /** @var array<string, string> one letter */
    private const ONE = [
        'α' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'ζ' => 'z', 'η' => 'i', 'θ' => 'th', 'ι' => 'i', 'κ' => 'k', 'λ' => 'l',
        'μ' => 'm', 'ν' => 'n', 'ξ' => 'x', 'ο' => 'o', 'π' => 'p', 'ρ' => 'r', 'σ' => 's', 'τ' => 't', 'υ' => 'y', 'φ' => 'f', 'χ' => 'ch',
        'ψ' => 'ps', 'ω' => 'o',
    ];

    /** @var array<string, string> two letters that are one sound (αυ, ευ, ηυ and μπ have rules of their own) */
    private const TWO = ['ου' => 'ou', 'ντ' => 'nt', 'γγ' => 'ng', 'γκ' => 'gk', 'γξ' => 'nx', 'γχ' => 'nch'];

    /** After αυ/ευ/ηυ these make it «f» (and a word's end); anything else «v». */
    private const UNVOICED = ['θ', 'κ', 'ξ', 'π', 'σ', 'τ', 'φ', 'χ', 'ψ'];

    /** @var array<string, string> letters' other forms (symbols, the micro sign), and Greek punctuation */
    private const OTHER = [
        'ϐ' => 'β', 'ϑ' => 'θ', 'ϕ' => 'φ', 'ϲ' => 'σ', 'ϱ' => 'ρ', 'ϰ' => 'κ', 'µ' => 'μ',
        "\u{037E}" => ';', "\u{0387}" => ';', '«' => '"', '»' => '"',
    ];

    /** @var array<string, string> a vowel and its accented form */
    private const ACCENTED = ['α' => 'ά', 'ε' => 'έ', 'η' => 'ή', 'ι' => 'ί', 'ο' => 'ό', 'υ' => 'ύ', 'ω' => 'ώ',
        'Α' => 'Ά', 'Ε' => 'Έ', 'Η' => 'Ή', 'Ι' => 'Ί', 'Ο' => 'Ό', 'Υ' => 'Ύ', 'Ω' => 'Ώ'];

    /** @var array<string, string> ι and υ with a diaeresis */
    private const DIAERESIS = ['ι' => 'ϊ', 'υ' => 'ϋ', 'Ι' => 'Ϊ', 'Υ' => 'Ϋ'];

    /**
     * @param  string  $text  UTF-8
     * @return string the same, its Greek in Latin letters
     */
    public static function transliterate($text)
    {
        $text = (string) $text;
        // Invalid UTF-8: its bad bytes dropped, the rest still transliterated (review of #669).
        if ($text !== '' && preg_match('//u', $text) !== 1) {
            $text = str_replace("\u{FFFD}", '', htmlspecialchars_decode(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES));
        }
        // Before NFC, which makes the ano teleia a middle dot (and the Greek question mark «;» already).
        $text = strtr($text, ["\u{0387}" => ';', "\u{037E}" => ';']);
        if (class_exists('Normalizer')) {
            $composed = \Normalizer::normalize($text, \Normalizer::FORM_C);
            $text = is_string($composed) ? $composed : $text;
        }
        $split = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($split === false) {
            return $text;
        }
        // Each character as a letter of BASE/ONE where it is one; a combining mark left after a Greek letter goes.
        $chars = [];
        foreach ($split as $char) {
            $char = self::fold($char);
            $last = end($chars);
            if ($last !== false && self::isLetter($last) && preg_match('/^\p{M}$/u', $char) === 1) {
                continue;
            }
            $chars[] = $char;
        }
        // Greek in capitals only (one-letter words included: «Θ. ΠΑΠΑΔΟΠΟΥΛΟΣ» is «TH. PAPADOPOULOS»).
        $greek = array_values(array_filter($chars, [self::class, 'isLetter']));
        $capitals = count($greek) > 1 && count(array_filter($greek, [self::class, 'isUpper'])) === count($greek);

        $out = '';
        $count = count($chars);
        $i = 0;
        while ($i < $count) {
            if (!self::isLetter($chars[$i])) {
                $out .= $chars[$i++];

                continue;
            }
            // One Greek word at a time.
            $word = [];
            while ($i < $count && self::isLetter($chars[$i])) {
                $word[] = $chars[$i++];
            }
            $out .= self::word($word, $capitals);
        }

        return $out;
    }

    private static function isLetter($char)
    {
        return isset(self::BASE[$char]) || isset(self::ONE[$char]);
    }

    private static function isUpper($char)
    {
        return isset(self::BASE[$char]) && $char !== 'ς' && !in_array($char, ['ά', 'έ', 'ή', 'ί', 'ό', 'ύ', 'ώ', 'ϊ', 'ϋ', 'ΐ', 'ΰ'], true);
    }

    /** A letter's other form as the letter; a polytonic one (ἀρχή, NFD) as its monotonic: diaeresis kept, an accent as tonos. */
    private static function fold($char)
    {
        if (self::isLetter($char)) {
            return $char;
        }
        if (isset(self::OTHER[$char])) {
            return self::OTHER[$char];
        }
        if (!class_exists('Normalizer')) {
            return $char;
        }
        $decomposed = \Normalizer::normalize($char, \Normalizer::FORM_D);
        $parts = is_string($decomposed) ? preg_split('//u', $decomposed, -1, PREG_SPLIT_NO_EMPTY) : false;
        if ($parts === false || count($parts) < 2 || !self::isLetter($parts[0])) {
            return $char;
        }
        $marks = array_slice($parts, 1);
        foreach ($marks as $mark) {
            if (preg_match('/^\p{M}$/u', $mark) !== 1) {
                return $char;
            }
        }
        if (in_array("\u{0308}", $marks, true) && isset(self::DIAERESIS[$parts[0]])) {
            return self::DIAERESIS[$parts[0]];
        }
        // Acute, grave or circumflex (perispomeni): the one accent of monotonic Greek.
        if (array_intersect($marks, ["\u{0301}", "\u{0300}", "\u{0342}"]) !== [] && isset(self::ACCENTED[$parts[0]])) {
            return self::ACCENTED[$parts[0]];
        }

        return $parts[0];
    }

    /** @param  list<string>  $chars  one Greek word */
    private static function word(array $chars, $capitals)
    {
        $letters = [];
        foreach ($chars as $char) {
            $letters[] = [
                'base' => self::BASE[$char] ?? $char,
                'upper' => self::isUpper($char),
                'accent' => in_array($char, ['ά', 'έ', 'ή', 'ί', 'ό', 'ύ', 'ώ', 'Ά', 'Έ', 'Ή', 'Ί', 'Ό', 'Ύ', 'Ώ', 'ΐ', 'ΰ'], true),
                'diaeresis' => in_array($char, ['ϊ', 'ϋ', 'ΐ', 'ΰ', 'Ϊ', 'Ϋ'], true),
            ];
        }

        $out = '';
        $n = count($letters);
        for ($k = 0; $k < $n; $k += $take) {
            $a = $letters[$k];
            $b = $letters[$k + 1] ?? null;
            $pair = $b !== null && !$b['diaeresis'] && !$a['accent'] ? $a['base'] . $b['base'] : null;
            $take = 1;
            if ($pair !== null && in_array($pair, ['αυ', 'ευ', 'ηυ'], true)) {
                $next = $letters[$k + 2]['base'] ?? null;
                $latin = self::ONE[$a['base']] . ($next === null || in_array($next, self::UNVOICED, true) ? 'f' : 'v');
                $take = 2;
            } elseif ($pair === 'μπ') {
                $latin = $k === 0 || $k + 2 >= $n ? 'b' : 'mp';
                $take = 2;
            } elseif ($pair !== null && isset(self::TWO[$pair])) {
                $latin = self::TWO[$pair];
                $take = 2;
            } else {
                $latin = self::ONE[$a['base']];
            }
            if ($a['upper']) {
                // All capitals when what is next to it is a capital too (ΘΕΣΗ: THESI, ΜΠ inside ΜπΑΜΠΗΣ: MP), or in a
                // text of capitals only; else only its first letter (Θέση: Thesi, Μπάμπης: Bampis).
                $neighbour = $take === 2 ? $letters[$k + 1]['upper'] : ($letters[$k + 1]['upper'] ?? ($k > 0 ? $letters[$k - 1]['upper'] : $capitals));
                $latin = $neighbour ? strtoupper($latin) : ucfirst($latin);
            }
            $out .= $latin;
        }

        return $out;
    }
}
