<?php

namespace App\Services\Licensing;

use App\Models\LicenseInstall;
use App\Models\LicenseIp;
use Illuminate\Support\Carbon;
use Nixpal\Licensing\ZlicResponse;
use Throwable;

/**
 * What the licensed software says about itself on /api/v1/licensing/check (docs/licensing.md, «Τηλεμετρία»), kept per
 * license, address, product and install. Written after the answer and never changing it: every field is optional, one
 * that is missing or malformed is left out, never refused. Only for a key we know (as LicenseActivity, a key that joined
 * a bulk license counts on the bulk one); an unknown key leaves nothing here.
 */
final class LicenseTelemetry
{
    public const PRODUCTS = ['agent', 'node', 'controller'];

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
            'licensed' => is_bool($in['licensed'] ?? null) ? $in['licensed'] : null,
            'failures' => is_int($in['failures'] ?? null) && $in['failures'] >= 0 && $in['failures'] <= 1_000_000 ? $in['failures'] : null,
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
            if ($license === null) {
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
                if (LicenseInstall::query()->where('license_id', $license->id)->count() >= self::MAX_INSTALLS) {
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

        return $past && $time->gt($now->copy()->addDay()) ? null : $time;
    }
}
