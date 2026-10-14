<?php

namespace App\Services\Licensing;

use App\Models\License;
use Illuminate\Support\Facades\DB;
use Nixpal\Licensing\ZlicResponse;

/**
 * Signs of life per license, beyond WHMCS (which keeps only last access): the last check answered Active, from which IP
 * and client, the last failure and why, and a count of ok / failed checks per day. Two writes per check, no row each.
 */
class LicenseActivity
{
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
            : ['last_failure_at' => $now, 'last_failure' => mb_substr((string) ($response->body['message'] ?? $response->body['description'] ?? $response->body['status'] ?? ''), 0, 255),
                'last_seen_ip' => mb_substr($ip, 0, 64), 'last_seen_client' => mb_substr($client, 0, 255)]);

        $column = $ok ? 'ok' : 'failed';
        $day = $now->toDateString();
        // One row per license and day: created by the first check, counted up by the rest.
        $updated = DB::table('license_check_days')->where(['license_id' => $license->id, 'day' => $day])->increment($column);
        if ($updated === 0) {
            DB::table('license_check_days')->insertOrIgnore(['license_id' => $license->id, 'day' => $day, 'ok' => $ok ? 1 : 0, 'failed' => $ok ? 0 : 1]);
        }
    }
}
