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
        foreach (glob(dirname(__DIR__, 2) . '/lang/*/*.php') ?: [] as $file) {
            foreach (self::duplicates((string) file_get_contents($file)) as $key) {
                $twice[] = basename(dirname($file)) . '/' . basename($file) . ": {$key}";
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
            if ($token === '[' || (is_array($token) && $token[0] === T_ARRAY)) {
                $seen[] = [];
            } elseif ($token === ']' || ($token === ')' && count($seen) > 1)) {
                array_pop($seen);
            } elseif (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_ARROW) {
                $key = stripslashes(substr($token[1], 1, -1));
                $level = count($seen) - 1;
                if (isset($seen[$level][$key])) {
                    $found[] = $key;
                }
                $seen[$level][$key] = true;
            }
        }

        return $found;
    }
}
