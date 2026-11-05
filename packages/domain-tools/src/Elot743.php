<?php

namespace Nixpal\DomainTools;

/**
 * Greek in Latin letters by ELOT 743 (the transcription Greek passports use): what registrars that take ASCII contact
 * data get (WG-23). «Θεσσαλονίκη» is «Thessaloniki», «ΕΥΑΓΓΕΛΟΣ» is «EVANGELOS», «Μπάμπης» is «Bampis» (μπ: b at a
 * word's start or end, mp inside). Accents and diaeresis go; a diaeresis or an accent on the first letter breaks a
 * digraph (άυλος: aylos, Ευρωπαϊκή: Evropaiki). Everything that is not Greek is left as it is.
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

    /**
     * @param  string  $text  UTF-8
     * @return string the same, its Greek in Latin letters
     */
    public static function transliterate($text)
    {
        $chars = preg_split('//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return (string) $text;
        }
        $out = '';
        $count = count($chars);
        $i = 0;
        while ($i < $count) {
            if (!isset(self::BASE[$chars[$i]]) && !isset(self::ONE[$chars[$i]])) {
                $out .= $chars[$i++];

                continue;
            }
            // One Greek word at a time.
            $word = [];
            while ($i < $count && (isset(self::BASE[$chars[$i]]) || isset(self::ONE[$chars[$i]]))) {
                $word[] = $chars[$i++];
            }
            $out .= self::word($word);
        }

        return $out;
    }

    /** @param  list<string>  $chars  one Greek word */
    private static function word(array $chars)
    {
        $letters = [];
        foreach ($chars as $char) {
            $base = self::BASE[$char] ?? $char;
            $letters[] = [
                'base' => $base,
                'upper' => $char !== $base && $char !== 'ς' && !in_array($char, ['ά', 'έ', 'ή', 'ί', 'ό', 'ύ', 'ώ', 'ϊ', 'ϋ', 'ΐ', 'ΰ'], true),
                'accent' => in_array($char, ['ά', 'έ', 'ή', 'ί', 'ό', 'ύ', 'ώ', 'Ά', 'Έ', 'Ή', 'Ί', 'Ό', 'Ύ', 'Ώ', 'ΐ', 'ΰ'], true),
                'diaeresis' => in_array($char, ['ϊ', 'ϋ', 'ΐ', 'ΰ', 'Ϊ', 'Ϋ'], true),
            ];
        }
        $allUpper = count(array_filter($letters, function (array $l) {
            return $l['upper'];
        })) === count($letters) && count($letters) > 1;

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
            $out .= self::cased($latin, $a['upper'], $allUpper);
        }

        return $out;
    }

    /** A capital is all capitals in a word of capitals (ΘΕΣΗ: THESI), else only its first letter (Θέση: Thesi). */
    private static function cased($latin, $upper, $allUpper)
    {
        if (!$upper) {
            return $latin;
        }

        return $allUpper ? strtoupper($latin) : ucfirst($latin);
    }
}
