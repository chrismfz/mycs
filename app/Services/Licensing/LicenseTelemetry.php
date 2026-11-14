<?php

namespace App\Services\Licensing;

use App\Models\License;
use App\Models\LicenseInstall;
use App\Models\LicenseIp;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nixpal\Licensing\ZlicResponse;
use Throwable;

/**
 * What the licensed software says about itself on /licensing/v1/check (docs/licensing.md, «Τηλεμετρία»), kept per
 * license, address, product and install. Written after the answer and never changing it: every field is optional, one
 * that is missing or malformed is left out, never refused. Only for a key we know (as LicenseActivity, a key that joined
 * a bulk license counts on the bulk one); an unknown key leaves nothing here.
 */
final class LicenseTelemetry
{
    public const PRODUCTS = ['agent', 'node', 'controller'];

    /** Installs seen in this many days count towards MAX_INSTALLS: the older ones wait for the retention (review 2-3). */
    public const MAX_INSTALLS_DAYS = 30;

    /** Installs kept per license: a key in many hands, or one making up install ids, stops growing the table here. */
    public const MAX_INSTALLS = 1000;

    /** The client's clock is off by more than this: shown to staff (a clock turned back keeps a local key valid). */
    public const SKEW_FLAG = 86400;

    /**
     * The fields of `telemetry` that pass, the others dropped.
     *
     * @return array{product: string, install_id: string, version?: string, build_time?: Carbon, commit?: string,
     *     binary_sha256?: string, os?: string, arch?: string, clock_skew?: int, licensed?: bool, failures?: int}
     */
    public static function parse(mixed $telemetry, ?Carbon $now = null): array
    {
        $now ??= now();
        $in = is_array($telemetry) ? $telemetry : [];
        $text = fn (string $key, string $pattern) => is_string($in[$key] ?? null) && preg_match($pattern, $in[$key]) === 1 ? $in[$key] : null;

        $product = strtolower((string) $text('product', '/^[A-Za-z]{1,16}$/'));
        $fields = [
            'product' => in_array($product, self::PRODUCTS, true) ? $product : '',
            'install_id' => (string) $text('install_id', '/^[A-Za-z0-9-]{8,64}$/'),
            'version' => $text('version', '/^[0-9A-Za-z.+_-]{1,40}$/'),
            'commit' => ($commit = $text('commit', '/^[0-9a-fA-F]{4,40}$/')) === null ? null : strtolower($commit),
            'binary_sha256' => ($sha = $text('sha256', '/^[0-9a-fA-F]{64}$/')) === null ? null : strtolower($sha),
            'os' => $text('os', '/^[A-Za-z0-9._\/-]{1,32}$/'),
            'arch' => $text('arch', '/^[A-Za-z0-9._-]{1,16}$/'),
            'build_time' => self::time($in['build_time'] ?? null, $now, past: true),
            'clock_skew' => ($client = self::time($in['client_time'] ?? null, $now)) === null
                ? null : (int) max(-2_000_000_000, min(2_000_000_000, $client->getTimestamp() - $now->getTimestamp())),
            // JSON's true/false, or a form's "1"/"0"/"true"/"false" (review R5).
            'licensed' => is_bool($in['licensed'] ?? null) ? $in['licensed']
                : (is_string($in['licensed'] ?? null) ? ['1' => true, 'true' => true, '0' => false, 'false' => false][strtolower($in['licensed'])] ?? null : null),
            'failures' => ($failures = is_int($in['failures'] ?? null) ? $in['failures']
                : (is_string($in['failures'] ?? null) && preg_match('/^\d{1,7}$/', $in['failures']) === 1 ? (int) $in['failures'] : null)) !== null
                && $failures >= 0 && $failures <= 1_000_000 ? $failures : null,
        ];

        return array_filter($fields, fn ($value) => $value !== null);
    }

    /**
     * After the answer to a known key. Nothing here throws to the caller: the answer is already decided.
     *
     * @param  mixed  $telemetry  the request's `telemetry` (anything a client sent)
     */
    public static function record(string $key, ZlicResponse $response, string $ip, mixed $telemetry): void
    {
        try {
            $license = MycsLicenseRepository::answering($key);
            if ($license === null || !self::fromItsPlace($license, $response, $ip)) {
                return;
            }
            $now = now();
            $fields = self::parse($telemetry, $now);
            $where = ['license_id' => $license->id, 'ip' => LicenseIp::normalize($ip) ?? mb_substr($ip, 0, 45),
                'product' => $fields['product'], 'install_id' => $fields['install_id']];
            unset($fields['product'], $fields['install_id']);
            $status = mb_substr((string) ($response->body['status'] ?? ''), 0, 16);

            $row = LicenseInstall::query()->where($where)->first();
            if ($row === null) {
                if (LicenseInstall::query()->where('license_id', $license->id)->where('last_seen_at', '>=', $now->copy()->subDays(self::MAX_INSTALLS_DAYS))
                    ->count() >= self::MAX_INSTALLS) {
                    return;
                }
                LicenseInstall::query()->insertOrIgnore($where + $fields + ['last_status' => $status, 'checks' => 1,
                    'first_seen_at' => $now, 'last_seen_at' => $now]);

                return; // a check of the same install that made it meanwhile loses only this one's update
            }

            $changes = $fields + ['last_status' => $status, 'checks' => $row->checks + 1, 'last_seen_at' => $now];
            if (isset($fields['version']) && $row->version !== null && $fields['version'] !== $row->version) {
                $changes['previous_version'] = $row->version;
            }
            // A build older than the one it ran before: someone went back (to a build without the newer checks?).
            if (isset($fields['build_time']) && $row->build_time !== null && $fields['build_time']->lt($row->build_time)) {
                $changes['downgraded_at'] = $now;
            }
            $row->update($changes);
        } catch (Throwable $e) {
            report($e); // never changes the answer
        }
    }

    /**
     * Whether the caller is where the license runs, so what it says is kept. The PSK is the product's, in every client: it
     * proves nothing. «Active» is answered only to a bound address (or a listed one, a bulk license's). «Suspended» and
     * «Expired» are signed to any address: kept only from a bound or listed one, else anyone holding an ended key could
     * fill its installs and its signals (review 2-1 of #863). «Invalid», limits and errors: never.
     */
    public static function fromItsPlace(License $license, ZlicResponse $response, string $ip): bool
    {
        $status = $response->body['status'] ?? null;
        if ($status === 'Active') {
            return true;
        }
        if ($status !== 'Suspended' && $status !== 'Expired') {
            return false;
        }
        $address = LicenseIp::normalize($ip);
        if ($address === null) {
            return false;
        }
        $bound = array_map(fn ($entry) => LicenseIp::normalize(trim($entry)), explode(',', (string) $license->valid_ip));

        return in_array($address, $bound, true)
            || LicenseIp::query()->where('license_id', $license->id)->where('ip', $address)->whereNull('removed_at')->exists();
    }

    /**
     * For a licenses query: the license runs as more than one install of a product since $since. Installs by their own
     * id, not by row: the address of one install may change, and agent and node on one key are two products (review R3).
     */
    public static function moreThanOneInstall(Carbon $since): \Closure
    {
        return fn (QueryBuilder $query) => $query->selectRaw('1')->from('license_installs')
            ->whereColumn('license_installs.license_id', 'licenses.id')->where('install_id', '!=', '')->where('last_seen_at', '>=', $since)
            ->groupBy('product')->havingRaw('COUNT(DISTINCT install_id) > 1');
    }

    /** For a licenses query: how many install ids it ran as since $since. */
    public static function installsSince(Carbon $since): QueryBuilder
    {
        return DB::table('license_installs')->selectRaw('COUNT(DISTINCT install_id)')
            ->whereColumn('license_installs.license_id', 'licenses.id')->where('install_id', '!=', '')->where('last_seen_at', '>=', $since);
    }

    /** One install for staff: «node 2026.11.14.2129 · 192.0.2.10 · 2 hours ago · ⚠ downgrade (was …)». */
    public static function describe(LicenseInstall $install): string
    {
        $parts = [trim(($install->product === '' ? '?' : $install->product) . ' ' . ($install->version ?? '')), $install->ip, $install->last_seen_at->diffForHumans()];
        if ($install->os !== null || $install->arch !== null) {
            $parts[] = trim(($install->os ?? '') . '/' . ($install->arch ?? ''), '/');
        }
        if ($install->licensed === false) {
            $parts[] = 'degraded' . ($install->failures ? " ({$install->failures} failures)" : '');
        }
        if ($install->downgraded_at !== null) {
            $parts[] = '⚠ downgrade ' . $install->downgraded_at->format('d/m/Y') . ($install->previous_version ? " (was {$install->previous_version})" : '');
        }
        if ($install->clock_skew !== null && abs($install->clock_skew) > self::SKEW_FLAG) {
            $parts[] = '⚠ clock ' . ($install->clock_skew > 0 ? '+' : '-') . intdiv(abs($install->clock_skew), 86400) . 'd';
        }

        return implode(' · ', $parts);
    }

    /** A unix time or a date the client sent; a build time only in the past (and after 2015), with a day's slack. */
    private static function time(mixed $value, Carbon $now, bool $past = false): ?Carbon
    {
        try {
            $time = match (true) {
                is_int($value) => Carbon::createFromTimestamp($value),
                is_float($value) && is_finite($value) && abs($value) < 1e11 => Carbon::createFromTimestamp((int) $value),
                is_string($value) && preg_match('/^\d{9,11}$/', $value) === 1 => Carbon::createFromTimestamp((int) $value),
                is_string($value) && strlen($value) <= 40 && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 => Carbon::parse($value, 'UTC'),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
        if ($time === null || $time->year < 2015 || $time->year > 2100) {
            return null;
        }
        // In the app's timezone, as Eloquent stores and reads every date: a date sent with its offset (+02:00, a DST
        // change) would be kept as its wall time (review R2), one in UTC read back hours off (review 2-2).
        $time = $time->setTimezone($now->getTimezone());

        return $past && $time->gt($now->copy()->addDay()) ? null : $time;
    }
}
