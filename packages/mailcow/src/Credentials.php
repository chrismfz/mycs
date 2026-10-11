<?php

namespace Nixpal\Mailcow;

/** Domain-admin username/password for new services. */
final class Credentials
{
    /**
     * Lowercase letters and digits from the domain's first label (mailcow usernames are
     * alphanumeric), 4-12 characters, plus random digits when needed for uniqueness.
     *
     * @param  callable(string): bool|null  $isFree  checks a candidate against mailcow
     */
    public static function username($domain, $isFree = null)
    {
        $label = strtolower((string) strtok((string) $domain, '.'));
        $base = substr(preg_replace('/[^a-z0-9]/', '', $label), 0, 8);
        if ($base === '' || ctype_digit($base[0])) {
            $base = 'mc' . $base;
        }
        $base = str_pad($base, 4, '0');
        $candidate = $base;
        for ($i = 0; $isFree && !call_user_func($isFree, $candidate) && $i < 20; $i++) {
            $candidate = substr($base, 0, 8) . random_int(1000, 9999);
        }

        return $candidate;
    }

    /** 16 characters with upper, lower, digit and symbol (mailcow's default policy). */
    public static function password($length = 16)
    {
        $sets = ['abcdefghjkmnpqrstuvwxyz', 'ABCDEFGHJKMNPQRSTUVWXYZ', '23456789', '!@#%^*-_=+'];
        $chars = [];
        foreach ($sets as $set) {
            $chars[] = $set[random_int(0, strlen($set) - 1)];
        }
        $all = implode('', $sets);
        while (count($chars) < max(12, (int) $length)) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
