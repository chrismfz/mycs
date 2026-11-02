<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A key written twice in a lang file: PHP keeps the last one without a word. The card's expiry lost its date so (account.
 * expires: «Expires :date» for cards, then «Expires» for invitations; owner's live test, 2026-11-03).
 */
class LangKeysUniqueTest extends TestCase
{
    public function test_no_lang_file_has_a_key_twice_in_one_array(): void
    {
        $twice = [];
        $root = dirname(__DIR__, 2);
        $files = [...(glob($root . '/lang/*/*.php') ?: []), ...(glob($root . '/extensions/*/*/resources/lang/*/*.php') ?: [])];
        foreach ($files as $file) {
            foreach (self::duplicates((string) file_get_contents($file)) as $key) {
                $twice[] = substr($file, strlen($root) + 1) . ": {$key}";
            }
        }

        $this->assertSame([], $twice, 'keys written twice (the first is lost)');
    }

    /** The JSON ones too: json_decode keeps the last of a key written twice, as silently. */
    public function test_no_json_lang_file_has_a_key_twice(): void
    {
        $twice = [];
        foreach (glob(dirname(__DIR__, 2) . '/lang/*.json') ?: [] as $file) {
            preg_match_all('/^\s*("(?:[^"\\\\]|\\\\.)*")\s*:/m', (string) file_get_contents($file), $keys);
            foreach (array_count_values(array_map(fn (string $key) => (string) json_decode($key), $keys[1])) as $key => $count) {
                if ($count > 1) {
                    $twice[] = basename($file) . ": {$key}";
                }
            }
        }

        $this->assertSame([], $twice, 'keys written twice (the first is lost)');
    }

    /** @return list<string> the keys found twice in one array, at any depth */
    private static function duplicates(string $php): array
    {
        $tokens = array_values(array_filter(token_get_all($php), fn ($token) => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $seen = [[]];
        $found = [];
        foreach ($tokens as $i => $token) {
            // Every bracket opens a level (a function call's too, so its «)» closes its own: review of #587).
            if ($token === '[' || $token === '(') {
                $seen[] = [];
            } elseif (($token === ']' || $token === ')') && count($seen) > 1) {
                array_pop($seen);
            } elseif (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER], true) && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_ARROW) {
                $key = $token[0] === T_LNUMBER ? $token[1] : $token[1][0] . stripslashes(substr($token[1], 1, -1));
                $level = count($seen) - 1;
                if (isset($seen[$level][$key])) {
                    $found[] = $token[0] === T_LNUMBER ? $key : substr($key, 1); // compared with its quote, told without
                }
                $seen[$level][$key] = true;
            }
        }

        return $found;
    }
}
