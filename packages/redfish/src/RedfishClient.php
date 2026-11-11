<?php

namespace MyCS\Redfish;

/**
 * One server's BMC read over Redfish (DMTF DSP0266) with HTTP Basic, which every implementation accepts: no session to
 * open or leave behind. It only reads (GET): a read-only BMC account is all it needs, and all it should be given.
 *
 * Read: the first system (model, serial, power state, health, processors, memory), its first chassis (asset tag, the
 * health of fans and power supplies) and the chassis' power (the watts drawn now; the BMC's min/average/max).
 */
final class RedfishClient
{
    private const ROOT = '/redfish/v1';

    /** The longest text kept of what a BMC says. */
    private const TEXT = 200;

    public function __construct(
        private readonly Transport $http,
        private readonly string $username,
        private readonly string $password,
    ) {
        if (trim($username) === '') {
            throw new RedfishException('No BMC username.');
        }
    }

    public function machine(): Machine
    {
        $systems = $this->members(self::ROOT . '/Systems');
        if ($systems === []) {
            throw new RedfishException('The BMC lists no system.');
        }
        $system = $this->get($systems[0]);
        // The system's own chassis where it says one (a BMC may list enclosures too), else the first listed.
        $links = is_array($system['Links'] ?? null) && is_array($system['Links']['Chassis'] ?? null) ? $system['Links']['Chassis'] : [];
        $chassisPath = self::link($links[0] ?? null) ?? $this->members(self::ROOT . '/Chassis', optional: true)[0] ?? null;
        $chassis = $chassisPath === null ? [] : ($this->get($chassisPath, optional: true) ?? []);
        $power = $chassisPath === null ? [] : $this->power($chassisPath, $chassis);

        $processors = is_array($system['ProcessorSummary'] ?? null) ? $system['ProcessorSummary'] : [];
        $memory = is_array($system['MemorySummary'] ?? null) ? $system['MemorySummary'] : [];

        return new Machine(
            manufacturer: self::text($system['Manufacturer'] ?? null),
            model: self::text($system['Model'] ?? null),
            serial: self::text($system['SerialNumber'] ?? null) ?? self::text($chassis['SerialNumber'] ?? null),
            sku: self::text($system['SKU'] ?? null),
            assetTag: self::text($system['AssetTag'] ?? null) ?? self::text($chassis['AssetTag'] ?? null),
            hostname: self::text($system['HostName'] ?? null),
            biosVersion: self::text($system['BiosVersion'] ?? null),
            powerState: self::text($system['PowerState'] ?? null),
            health: self::worst(self::health($system), self::health($chassis)),
            cpuCount: self::number($processors['Count'] ?? null) === null ? null : (int) self::number($processors['Count']),
            cpuModel: self::text($processors['Model'] ?? null),
            memoryGiB: self::number($memory['TotalSystemMemoryGiB'] ?? null),
            watts: $power['watts'] ?? null,
            wattsMin: $power['min'] ?? null,
            wattsAverage: $power['average'] ?? null,
            wattsMax: $power['max'] ?? null,
            wattsIntervalMinutes: isset($power['interval']) ? (int) $power['interval'] : null,
            systems: count($systems),
        );
    }

    /**
     * The chassis' power: its «Power» resource (every BMC of the last ten years: its first PowerControl), or the
     * newer «EnvironmentMetrics» where Power is gone. Nothing when the BMC has neither: the machine is still read.
     *
     * @param  array<string, mixed>  $chassis
     * @return array{watts?: float, min?: float, average?: float, max?: float, interval?: float}
     */
    private function power(string $chassisPath, array $chassis): array
    {
        $legacy = $this->get(self::link($chassis['Power'] ?? null) ?? self::under($chassisPath, 'Power'), optional: true);
        // The first control is the machine's total; further ones, where a BMC has them, are its parts (review of #794).
        $control = is_array($legacy['PowerControl'] ?? null) && is_array($legacy['PowerControl'][0] ?? null) ? $legacy['PowerControl'][0] : [];
        $watts = self::number($control['PowerConsumedWatts'] ?? null);
        if ($watts !== null) {
            $out = ['watts' => $watts];
            $metrics = is_array($control['PowerMetrics'] ?? null) ? $control['PowerMetrics'] : [];
            foreach (['min' => 'MinConsumedWatts', 'average' => 'AverageConsumedWatts', 'max' => 'MaxConsumedWatts', 'interval' => 'IntervalInMin'] as $key => $field) {
                $value = self::number($metrics[$field] ?? null);
                if ($value !== null) {
                    $out[$key] = $value;
                }
            }

            return $out;
        }
        $metrics = $this->get(self::link($chassis['EnvironmentMetrics'] ?? null) ?? self::under($chassisPath, 'EnvironmentMetrics'), optional: true);
        $reading = self::number(is_array($metrics['PowerWatts'] ?? null) ? ($metrics['PowerWatts']['Reading'] ?? null) : null);

        return $reading === null ? [] : ['watts' => $reading];
    }

    /**
     * A collection's members, as paths.
     *
     * @return list<string>
     */
    private function members(string $path, bool $optional = false): array
    {
        $collection = $this->get($path, $optional) ?? [];
        $paths = [];
        foreach (is_array($collection['Members'] ?? null) ? $collection['Members'] : [] as $member) {
            $link = self::link($member);
            if ($link !== null) {
                $paths[] = $link;
            }
        }

        return $paths;
    }

    /**
     * @return ($optional is true ? array<string, mixed>|null : array<string, mixed>)
     */
    private function get(string $path, bool $optional = false): ?array
    {
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($this->username . ':' . $this->password),
            'Accept' => 'application/json',
            'OData-Version' => '4.0',
        ];
        [$status, $body] = $this->http->get($path, $headers);
        // Some BMCs redirect a path to its other form, with or without the last «/». That form is asked once, on this
        // BMC: where the redirect itself points is never read or followed.
        if (in_array($status, [301, 302, 307, 308], true)) {
            [$status, $body] = $this->http->get(str_ends_with($path, '/') ? rtrim($path, '/') : $path . '/', $headers);
        }
        // A refused login is said whatever was asked: never read as «this BMC has no such resource».
        if ($status === 401) {
            throw new RedfishException('The BMC refused the login (401): check the username and password.', 401);
        }
        if ($status === 0) {
            throw new RedfishException('The BMC did not answer: check its address and that MyCS reaches the management network.');
        }
        $decoded = $status >= 200 && $status < 300 ? json_decode($body, true) : null;
        if (is_array($decoded)) {
            return $decoded;
        }
        if ($optional) {
            return null;
        }
        throw new RedfishException(match (true) {
            $status === 403 => "The BMC account may not read {$path} (403): give it the read-only role.",
            $status === 404 => "No {$path} on this BMC (404): no Redfish, or a firmware too old for it.",
            $status >= 200 && $status < 300 => "Not a Redfish answer at {$path}.",
            default => "HTTP {$status} for {$path}.",
        }, $status);
    }

    /**
     * A link the BMC gave ({"@odata.id": "/redfish/v1/..."}), only as a plain path under the Redfish root: whatever a
     * BMC answers, the next request goes to that BMC and nowhere else.
     */
    private static function link(mixed $reference): ?string
    {
        $path = is_array($reference) ? ($reference['@odata.id'] ?? null) : null;
        if (!is_string($path) || preg_match('~^/redfish/v1/[A-Za-z0-9._\-/]{1,200}$~D', $path) !== 1 || str_contains($path, '..') || str_contains($path, '//')) {
            return null;
        }

        return $path; // as given: a BMC that wants its last «/» gets it
    }

    private static function under(string $path, string $child): string
    {
        return rtrim($path, '/') . '/' . $child;
    }

    /** @param  array<string, mixed>  $resource */
    private static function health(array $resource): ?string
    {
        $status = is_array($resource['Status'] ?? null) ? $resource['Status'] : [];

        return self::worst(self::level($status['HealthRollup'] ?? null), self::level($status['Health'] ?? null));
    }

    private static function level(mixed $value): ?string
    {
        return in_array($value, [Machine::HEALTH_OK, Machine::HEALTH_WARNING, Machine::HEALTH_CRITICAL], true) ? $value : null;
    }

    private static function worst(?string $a, ?string $b): ?string
    {
        foreach ([Machine::HEALTH_CRITICAL, Machine::HEALTH_WARNING, Machine::HEALTH_OK] as $level) {
            if ($a === $level || $b === $level) {
                return $level;
            }
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $text = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string) $value));
        $text = function_exists('mb_substr') ? mb_substr($text, 0, self::TEXT) : substr($text, 0, self::TEXT);

        return $text === '' ? null : $text;
    }

    private static function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 ? (float) $value : null;
    }
}
