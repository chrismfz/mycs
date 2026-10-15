<?php

namespace App\Services\Licensing;

use App\Models\License;
use App\Models\LicenseAccessLog;
use Illuminate\Support\Facades\DB;
use Nixpal\Licensing\ZlicResponse;

/**
 * Signs of life per license, beyond WHMCS (which keeps only last access): the last check answered Active, from which IP
 * and client, the last failure and why, and a count of ok / failed checks per day. Two writes per check, no row each;
 * in the access log (where WHMCS writes only failures) one «License Active» a day per address.
 */
class LicenseActivity
{
    public const ACTIVE = 'License Active';

    public static function record(string $key, ZlicResponse $response, string $ip, string $client): void
    {
        $license = License::query()->where('license_key', $key)->first(['id']);
        if ($license === null) {
            return;
        }
        $ok = ($response->body['status'] ?? null) === 'Active';
        $now = now();
        License::query()->whereKey($license->id)->update($ok
            ? ['last_ok_at' => $now, 'last_seen_ip' => mb_substr($ip, 0, 64), 'last_seen_client' => mb_substr($client, 0, 255)]
            // A failure says when, why and from where, without overwriting where it last worked (anyone may send one).
            : ['last_failure_at' => $now, 'last_failure' => mb_substr(trim((string) ($response->body['message'] ?? $response->body['description'] ?? $response->body['status'] ?? '') . ' (' . $ip . ')'), 0, 255)]);

        $column = $ok ? 'ok' : 'failed';
        $day = $now->toDateString();
        // One row per license and day: created by the first check, counted up by the rest.
        $updated = DB::table('license_check_days')->where(['license_id' => $license->id, 'day' => $day])->increment($column);
        if ($updated === 0
            && DB::table('license_check_days')->insertOrIgnore(['license_id' => $license->id, 'day' => $day, 'ok' => $ok ? 1 : 0, 'failed' => $ok ? 0 : 1]) === 0) {
            // Another check of the same license made the day's row meanwhile (review R6): count this one on it.
            DB::table('license_check_days')->where(['license_id' => $license->id, 'day' => $day])->increment($column);
        }

        // After the counting: a failed write loses only this row. Domain and directory are the license's bindings
        // (failure rows have the caller's reverse DNS and zlic.php's directory).
        if ($ok && MycsLicenseRepository::firstToday($license->id, $ip, self::ACTIVE, onError: false)) {
            LicenseAccessLog::query()->create([
                'license_id' => $license->id,
                'domain' => mb_substr((string) ($response->body['validdomain'] ?? ''), 0, 255),
                'ip' => mb_substr($ip, 0, 64),
                'directory' => mb_substr((string) ($response->body['validdirectory'] ?? ''), 0, 255),
                'message' => self::ACTIVE,
                'created_at' => $now,
            ]);
        }
    }
}
