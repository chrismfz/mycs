<?php

namespace Nixpal\Mailcow;

/** Quota/usage figures for the overview tables. */
final class Usage
{
    public static function bytes($value)
    {
        if (is_int($value) || is_float($value)) {
            return max(0, (int) $value);
        }
        $value = str_replace([',', ' '], '', trim((string) $value));

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    public static function format($bytes)
    {
        $bytes = self::bytes($bytes);
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $n = $bytes / 1024;
        $i = 0;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return sprintf('%.2f %s', $n, $units[$i]);
    }

    /** Percentage used, or null without a quota. */
    public static function percent($used, $quota)
    {
        $quota = self::bytes($quota);

        return $quota > 0 ? min(999.9, round(self::bytes($used) / $quota * 100, 1)) : null;
    }

    /**
     * Totals over MailcowClient::overview() rows: used, quota, allocated (bytes), mailboxes, aliases.
     */
    public static function totals(array $overview)
    {
        $t = ['used' => 0, 'quota' => 0, 'allocated' => 0, 'mailboxes' => 0, 'aliases' => 0];
        foreach ($overview as $row) {
            $d = $row['domain'] ?? [];
            $t['used'] += self::bytes($d['bytes_total'] ?? 0);
            $t['quota'] += self::bytes($d['max_quota_for_domain'] ?? 0);
            $t['allocated'] += self::bytes($d['quota_used_in_domain'] ?? 0);
            $t['mailboxes'] += count($row['mailboxes'] ?? []);
            $t['aliases'] += count($row['aliases'] ?? []);
        }

        return $t;
    }
}
