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
    ) {}

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
