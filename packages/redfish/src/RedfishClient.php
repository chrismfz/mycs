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

    /** The most members read of one collection (DIMMs, drives): a BMC that lists more is not followed further. */
    private const MEMBERS = 64;

    /** What a firmware says where nobody filled a value in: no value. */
    private const PLACEHOLDER = '/^(to be filled by o\.?\s?e\.?\s?m\.?|default string|not specified|not available|n\/?a|none|null|unknown|system (serial|product) (number|name)|chassis serial number|0+|x+|\.+|-+)$/i';

    /** @var float|null the time by which the parts must be read (machine(parts: true)); null: no limit */
    private ?float $until = null;

    /** Whether a part was left unread because the time ran out. */
    private bool $cut = false;

    /** @var array<string, mixed>|null the chassis' Power resource of this read (its power supplies are in it too) */
    private ?array $lastPower = null;

    public function __construct(
        private readonly Transport $http,
        private readonly string $username,
        private readonly string $password,
    ) {
        if (trim($username) === '') {
            throw new RedfishException('No BMC username.');
        }
    }

    /**
     * @param  bool  $parts  also its fans, temperatures, power supplies, processors, memory modules and drives: one
     *                       request each for most (tens of requests, seconds each on a slow BMC), within $budget seconds;
     *                       past that the remaining parts are left out and the machine says so (partsComplete)
     */
    public function machine(bool $parts = false, float $budget = 45.0): Machine
    {
        $this->until = $this->lastPower = null;
        $this->cut = false;
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
        $detail = [];
        if ($parts) {
            $this->until = microtime(true) + $budget;
            $detail = [
                'fans' => [], 'temperatures' => [],
                'powerSupplies' => $this->powerSupplies($this->lastPower),
                'processors' => $this->each($system, $systems[0], 'Processors', self::processor(...)),
                'memory' => $this->each($system, $systems[0], 'Memory', self::memory(...)),
                'drives' => $this->drives($system, $systems[0]),
            ];
            if ($chassisPath !== null) {
                $thermal = $this->get(self::link($chassis['Thermal'] ?? null) ?? self::under($chassisPath, 'Thermal'), optional: true) ?? [];
                $detail['fans'] = self::list($thermal['Fans'] ?? null, self::fan(...));
                $detail['temperatures'] = self::list($thermal['Temperatures'] ?? null, self::temperature(...));
            }
            $detail += ['partsRead' => true, 'partsComplete' => !$this->wasCut()];
        }

        $processors = is_array($system['ProcessorSummary'] ?? null) ? $system['ProcessorSummary'] : [];
        $memory = is_array($system['MemorySummary'] ?? null) ? $system['MemorySummary'] : [];

        return new Machine(...([
            'manufacturer' => self::text($system['Manufacturer'] ?? null),
            'model' => self::text($system['Model'] ?? null),
            'serial' => self::text($system['SerialNumber'] ?? null) ?? self::text($chassis['SerialNumber'] ?? null),
            'sku' => self::text($system['SKU'] ?? null),
            'assetTag' => self::text($system['AssetTag'] ?? null) ?? self::text($chassis['AssetTag'] ?? null),
            'hostname' => self::text($system['HostName'] ?? null),
            'biosVersion' => self::text($system['BiosVersion'] ?? null),
            'powerState' => self::text($system['PowerState'] ?? null),
            'health' => self::worst(self::health($system), self::health($chassis)),
            'cpuCount' => self::number($processors['Count'] ?? null) === null ? null : (int) self::number($processors['Count']),
            // The summary's model is a family on some BMCs («AMD Zen processor family»): the first processor's own, when read.
            'cpuModel' => ($detail['processors'][0]['model'] ?? null) ?? self::text($processors['Model'] ?? null),
            'memoryGiB' => self::number($memory['TotalSystemMemoryGiB'] ?? null),
            'watts' => $power['watts'] ?? null,
            'wattsMin' => $power['min'] ?? null,
            'wattsAverage' => $power['average'] ?? null,
            'wattsMax' => $power['max'] ?? null,
            'wattsIntervalMinutes' => isset($power['interval']) ? (int) $power['interval'] : null,
            'systems' => count($systems),
        ] + $detail));
    }

    /**
     * A collection under the system (Processors, Memory): each member read and made a row; members that are not there
     * (an empty socket or slot) left out.
     *
     * @param  array<string, mixed>  $system
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private function each(array $system, string $systemPath, string $collection, callable $row): array
    {
        $rows = [];
        foreach (array_slice($this->members(self::link($system[$collection] ?? null) ?? self::under($systemPath, $collection), optional: true), 0, self::MEMBERS) as $path) {
            $resource = $this->get($path, optional: true);
            $made = $resource === null || self::absent($resource) ? null : $row($resource);
            if ($made !== null) {
                $rows[] = $made;
            }
        }

        return $rows;
    }

    /**
     * The drives the BMC knows: those of each storage controller it lists. Disks on a controller the BMC does not see
     * (an onboard SATA port with software RAID, on many boards) are simply not there.
     *
     * @param  array<string, mixed>  $system
     * @return list<array<string, mixed>>
     */
    private function drives(array $system, string $systemPath): array
    {
        $rows = $seen = [];
        foreach (array_slice($this->members(self::link($system['Storage'] ?? null) ?? self::under($systemPath, 'Storage'), optional: true), 0, 16) as $storagePath) {
            $storage = $this->get($storagePath, optional: true) ?? [];
            foreach (is_array($storage['Drives'] ?? null) ? $storage['Drives'] : [] as $reference) {
                $path = self::link($reference);
                if ($path === null || isset($seen[$path]) || count($seen) >= self::MEMBERS) {
                    continue;
                }
                $seen[$path] = true;
                $drive = $this->get($path, optional: true);
                if ($drive === null || self::absent($drive)) {
                    continue;
                }
                $bytes = self::number($drive['CapacityBytes'] ?? null);
                $rows[] = [
                    'name' => self::text($drive['Name'] ?? null) ?? self::text($drive['Id'] ?? null),
                    'model' => self::text($drive['Model'] ?? null),
                    'serial' => self::text($drive['SerialNumber'] ?? null),
                    'gb' => $bytes === null ? null : round($bytes / 1e9, 1),
                    'media' => self::text($drive['MediaType'] ?? null),
                    'protocol' => self::text($drive['Protocol'] ?? null),
                    'health' => self::health($drive),
                    'failing' => ($drive['FailurePredicted'] ?? null) === true,
                    'life' => self::number($drive['PredictedMediaLifeLeftPercent'] ?? null),
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $power  the chassis' Power resource
     * @return list<array<string, mixed>>
     */
    private function powerSupplies(?array $power): array
    {
        return self::list($power['PowerSupplies'] ?? null, fn (array $psu) => [
            'name' => self::text($psu['Name'] ?? null),
            'model' => self::text($psu['Model'] ?? null),
            'watts' => self::number($psu['PowerInputWatts'] ?? null) ?? self::number($psu['LastPowerOutputWatts'] ?? null),
            'capacity' => self::number($psu['PowerCapacityWatts'] ?? null),
            'health' => self::health($psu),
        ]);
    }

    /**
     * The rows of a list inside a resource (Fans, Temperatures, PowerSupplies), those that are there.
     *
     * @param  callable(array<string, mixed>): ?array<string, mixed>  $row
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $items, callable $row): array
    {
        $rows = [];
        foreach (array_slice(is_array($items) ? $items : [], 0, self::MEMBERS) as $item) {
            $made = is_array($item) && !self::absent($item) ? $row($item) : null;
            if ($made !== null) {
                $rows[] = $made;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $fan
     * @return array<string, mixed>
     */
    private static function fan(array $fan): array
    {
        $reading = self::number($fan['Reading'] ?? null);
        $percent = ($fan['ReadingUnits'] ?? null) === 'Percent';

        return ['name' => self::text($fan['Name'] ?? null) ?? self::text($fan['FanName'] ?? null), 'rpm' => $percent ? null : $reading,
            'percent' => $percent ? $reading : null, 'health' => self::health($fan)];
    }

    /**
     * @param  array<string, mixed>  $sensor
     * @return array<string, mixed>|null
     */
    private static function temperature(array $sensor): ?array
    {
        $celsius = self::number($sensor['ReadingCelsius'] ?? null);

        return $celsius === null ? null : ['name' => self::text($sensor['Name'] ?? null), 'celsius' => $celsius,
            'warning' => self::number($sensor['UpperThresholdNonCritical'] ?? null), 'critical' => self::number($sensor['UpperThresholdCritical'] ?? null),
            'health' => self::health($sensor)];
    }

    /**
     * @param  array<string, mixed>  $cpu
     * @return array<string, mixed>
     */
    private static function processor(array $cpu): array
    {
        $whole = fn (mixed $value) => self::number($value) === null ? null : (int) self::number($value);

        return ['name' => self::text($cpu['Socket'] ?? null) ?? self::text($cpu['Name'] ?? null), 'model' => self::text($cpu['Model'] ?? null),
            'cores' => $whole($cpu['TotalCores'] ?? null), 'threads' => $whole($cpu['TotalThreads'] ?? null), 'health' => self::health($cpu)];
    }

    /**
     * @param  array<string, mixed>  $dimm
     * @return array<string, mixed>|null
     */
    private static function memory(array $dimm): ?array
    {
        $mib = self::number($dimm['CapacityMiB'] ?? null);
        if ($mib === null || $mib <= 0) {
            return null; // an empty slot, on the BMCs that list those too
        }
        $mhz = self::number($dimm['OperatingSpeedMhz'] ?? null);

        return ['name' => self::text($dimm['DeviceLocator'] ?? null) ?? self::text($dimm['Name'] ?? null), 'gib' => round($mib / 1024, 1),
            'type' => self::text($dimm['MemoryDeviceType'] ?? null), 'mhz' => $mhz === null ? null : (int) $mhz, 'health' => self::health($dimm)];
    }

    /** Whether a part was left unread because the time ran out (set by get() while the parts were read). */
    private function wasCut(): bool
    {
        return $this->cut;
    }

    /** @param  array<string, mixed>  $resource  not there: an empty slot, bay or socket the BMC still lists */
    private static function absent(array $resource): bool
    {
        return is_array($resource['Status'] ?? null) && ($resource['Status']['State'] ?? null) === 'Absent';
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
        $legacy = $this->lastPower = $this->get(self::link($chassis['Power'] ?? null) ?? self::under($chassisPath, 'Power'), optional: true);
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
        if ($optional && $this->until !== null && microtime(true) >= $this->until) {
            $this->cut = true;

            return null;
        }
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
        if (!is_string($path) || preg_match('~^/redfish/v1/[A-Za-z0-9._\-/:+@=,\~]{1,300}$~D', $path) !== 1 || str_contains($path, '..') || str_contains($path, '//')) {
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

        return $text === '' || preg_match(self::PLACEHOLDER, $text) === 1 ? null : $text;
    }

    private static function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 ? (float) $value : null;
    }
}
