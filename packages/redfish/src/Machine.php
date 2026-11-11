<?php

namespace MyCS\Redfish;

/**
 * What a BMC says about its machine, as read now. Every text is the BMC's own (shortened, no control characters):
 * shown escaped, never trusted. Null: the BMC did not say.
 */
final class Machine
{
    public const HEALTH_OK = 'OK';

    public const HEALTH_WARNING = 'Warning';

    public const HEALTH_CRITICAL = 'Critical';

    public function __construct(
        public readonly ?string $manufacturer = null,
        public readonly ?string $model = null,
        public readonly ?string $serial = null,
        public readonly ?string $sku = null,
        public readonly ?string $assetTag = null,
        public readonly ?string $hostname = null,
        public readonly ?string $biosVersion = null,
        /** «On», «Off», «PoweringOn», «PoweringOff» */
        public readonly ?string $powerState = null,
        /** The worst of the system's and its chassis' health: OK, Warning or Critical */
        public readonly ?string $health = null,
        public readonly ?int $cpuCount = null,
        public readonly ?string $cpuModel = null,
        public readonly ?float $memoryGiB = null,
        /** What it draws now, in watts */
        public readonly ?float $watts = null,
        /** The BMC's own figures over its last interval (minutes) */
        public readonly ?float $wattsMin = null,
        public readonly ?float $wattsAverage = null,
        public readonly ?float $wattsMax = null,
        public readonly ?int $wattsIntervalMinutes = null,
        /** Systems behind this BMC (a multi-node chassis has several): the first one is read */
        public readonly int $systems = 1,
        /** Its locate light (UID): on, off, or not said */
        public readonly ?bool $locating = null,
        /** What it boots from the next time only (Pxe, BiosSetup, …); null: as its BIOS says */
        public readonly ?string $bootOnce = null,
        /**
         * Its parts, read only when asked for (RedfishClient::machine(parts: true)); each list as the BMC gave it.
         *
         * @var list<array{name: ?string, rpm: ?float, percent: ?float, health: ?string}>
         */
        public readonly array $fans = [],
        /** @var list<array{name: ?string, celsius: float, warning: ?float, critical: ?float, health: ?string}> */
        public readonly array $temperatures = [],
        /** @var list<array{name: ?string, model: ?string, watts: ?float, capacity: ?float, health: ?string}> */
        public readonly array $powerSupplies = [],
        /** @var list<array{name: ?string, model: ?string, cores: ?int, threads: ?int, health: ?string}> */
        public readonly array $processors = [],
        /** @var list<array{name: ?string, gib: ?float, type: ?string, mhz: ?int, health: ?string}> */
        public readonly array $memory = [],
        /** @var list<array{name: ?string, model: ?string, serial: ?string, gb: ?float, media: ?string, protocol: ?string, health: ?string, failing: bool, life: ?float}> */
        public readonly array $drives = [],
        /** Whether its parts were asked for, and all of them read (false: the BMC was too slow, some lists are short) */
        public readonly bool $partsRead = false,
        public readonly bool $partsComplete = false,
    ) {}

    /**
     * The parts staff should look at, in words: any whose health is not OK, a drive that predicts its failure or has
     * under 10% of its life left, a temperature at its warning threshold.
     *
     * @return list<string>
     */
    public function attention(): array
    {
        $out = [];
        $lists = ['Fan' => $this->fans, 'Temperature' => $this->temperatures, 'Power supply' => $this->powerSupplies, 'Processor' => $this->processors,
            'Memory' => $this->memory, 'Drive' => $this->drives];
        foreach ($lists as $kind => $parts) {
            foreach ($parts as $i => $part) {
                $name = $part['name'] ?? ($kind . ' ' . ($i + 1));
                $why = match (true) {
                    ($part['health'] ?? null) !== null && $part['health'] !== self::HEALTH_OK => $part['health'],
                    ($part['failing'] ?? false) === true => 'failure predicted',
                    // 0 is what a disk that keeps no such count answers (review of #798): only a count above it is one.
                    isset($part['life']) && $part['life'] > 0 && $part['life'] < 10 => rtrim(rtrim(number_format($part['life'], 1, '.', ''), '0'), '.') . '% life left',
                    // At its warning threshold, or its critical one where the BMC has no warning (iLO).
                    isset($part['celsius']) && ($limit = ($part['warning'] ?? null) ?: ($part['critical'] ?? null)) > 0 && $part['celsius'] >= $limit => $part['celsius'] . ' °C',
                    default => null,
                };
                if ($why !== null) {
                    $out[] = "{$name}: {$why}";
                }
            }
        }

        return $out;
    }

    /** The air it takes in, in °C: the sensor named inlet / ambient / system temp, where the BMC has one. */
    public function inletCelsius(): ?float
    {
        foreach (['/inlet|ambient|intake/i', '/^system temp|front panel/i'] as $pattern) {
            foreach ($this->temperatures as $sensor) {
                $name = (string) ($sensor['name'] ?? '');
                // A power supply's own inlet is not the machine's.
                if (preg_match($pattern, $name) === 1 && preg_match('~p/?s\b|psu|power supply~i', $name) !== 1) {
                    return $sensor['celsius'];
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> as kept (JSON) and given back to fromArray */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param  array<string, mixed>  $data  a toArray() kept earlier: fields since added are their defaults, unknown ones dropped */
    public static function fromArray(array $data): self
    {
        $known = array_intersect_key($data, array_flip(array_map(fn (\ReflectionParameter $p) => $p->getName(),
            (new \ReflectionMethod(self::class, '__construct'))->getParameters())));

        try {
            return new self(...$known);
        } catch (\TypeError) {
            return new self;
        }
    }

    public function isOn(): ?bool
    {
        return $this->powerState === null ? null : $this->powerState === 'On';
    }

    /** Whether staff should look: anything said that is not «OK». */
    public function needsAttention(): bool
    {
        return $this->health !== null && $this->health !== self::HEALTH_OK;
    }
}
