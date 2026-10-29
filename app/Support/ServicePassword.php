<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * A service account's password as staff and customers set it at its server («Change password on server»): our rule,
 * and a generated one that meets it and the modules' own (cPanel's strength, mailcow's default policy, Centova).
 */
class ServicePassword
{
    public const LENGTH = 16;

    /** Lower, upper and digits without the look-alikes (0/O, 1/l/I), and the few symbols every module takes (Centova's form, mailcow's policy, a shell). */
    private const SETS = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKMNPQRSTUVWXYZ', '23456789', '-_.!@'];

    public static function rule(): Password
    {
        return Password::min(10)->letters()->mixedCase()->numbers();
    }

    /** A CSPRNG password with one of each set at least, shuffled (random_int, not str_shuffle). */
    public static function generate(int $length = self::LENGTH): string
    {
        $length = max(12, $length);
        $chars = array_map(fn (string $set) => $set[random_int(0, strlen($set) - 1)], self::SETS);
        $all = implode('', self::SETS);
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
