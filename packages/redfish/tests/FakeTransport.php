<?php

namespace MyCS\Redfish\Tests;

use MyCS\Redfish\Transport;

final class FakeTransport implements Transport
{
    /** @var list<array{0: string, 1: array<string, string>}> path, headers */
    public array $sent = [];

    /** @param  array<string, array{0: int, 1: mixed}>  $answers  path => [status, the JSON's data or a raw body] */
    public function __construct(public array $answers = []) {}

    public function get(string $path, array $headers): array
    {
        $this->sent[] = [$path, $headers];
        if (!isset($this->answers[$path])) {
            return [404, '{"error":{"code":"Base.1.0.GeneralError"}}'];
        }
        [$status, $data] = $this->answers[$path];

        return [$status, is_string($data) ? $data : (string) json_encode($data)];
    }

    /** @return list<string> */
    public function paths(): array
    {
        return array_column($this->sent, 0);
    }

    /**
     * A one-system BMC as the DMTF's public mockups show one (made-up values).
     *
     * @return array<string, array{0: int, 1: mixed}>
     */
    public static function bmc(): array
    {
        return [
            '/redfish/v1/Systems' => [200, ['Members' => [['@odata.id' => '/redfish/v1/Systems/1']]]],
            '/redfish/v1/Systems/1' => [200, [
                'Manufacturer' => 'Contoso', 'Model' => 'SYS-1000', 'SerialNumber' => 'S000001', 'SKU' => 'SKU-1', 'HostName' => 'box.example.com',
                'BiosVersion' => '3.4', 'PowerState' => 'On', 'Status' => ['State' => 'Enabled', 'Health' => 'OK', 'HealthRollup' => 'OK'],
                'ProcessorSummary' => ['Count' => 2, 'Model' => 'Example CPU 4210'], 'MemorySummary' => ['TotalSystemMemoryGiB' => 128],
            ]],
            '/redfish/v1/Chassis' => [200, ['Members' => [['@odata.id' => '/redfish/v1/Chassis/1']]]],
            '/redfish/v1/Chassis/1' => [200, ['AssetTag' => 'RACK4-U12', 'Status' => ['Health' => 'OK'], 'Power' => ['@odata.id' => '/redfish/v1/Chassis/1/Power']]],
            '/redfish/v1/Chassis/1/Power' => [200, ['PowerControl' => [[
                'PowerConsumedWatts' => 182,
                'PowerMetrics' => ['IntervalInMin' => 5, 'MinConsumedWatts' => 170, 'AverageConsumedWatts' => 180, 'MaxConsumedWatts' => 240],
            ]]]],
        ];
    }

    /**
     * The same BMC with its parts: fans, temperatures, two power supplies, a processor, memory (one slot empty), a
     * storage controller with two drives (made-up values).
     *
     * @return array<string, array{0: int, 1: mixed}>
     */
    public static function bmcWithParts(): array
    {
        $ok = ['State' => 'Enabled', 'Health' => 'OK'];
        $answers = self::bmc();
        $answers['/redfish/v1/Systems/1'][1] += [
            'Processors' => ['@odata.id' => '/redfish/v1/Systems/1/Processors'],
            'Memory' => ['@odata.id' => '/redfish/v1/Systems/1/Memory'],
            'Storage' => ['@odata.id' => '/redfish/v1/Systems/1/Storage'],
        ];
        $answers['/redfish/v1/Systems/1'][1]['ProcessorSummary'] = ['Count' => 1, 'Model' => 'Example processor family'];
        $answers['/redfish/v1/Chassis/1/Power'][1]['PowerSupplies'] = [
            ['Name' => 'PSU 1', 'Model' => 'PWS-1K', 'PowerInputWatts' => 95, 'PowerCapacityWatts' => 1000, 'Status' => $ok],
            ['Name' => 'PSU 2', 'Model' => 'PWS-1K', 'PowerInputWatts' => 92, 'PowerCapacityWatts' => 1000, 'Status' => $ok],
            ['Name' => 'PSU 3', 'Status' => ['State' => 'Absent']],
        ];

        return $answers + [
            '/redfish/v1/Chassis/1/Thermal' => [200, [
                'Fans' => [
                    ['Name' => 'FAN1', 'Reading' => 5400, 'ReadingUnits' => 'RPM', 'Status' => $ok],
                    ['FanName' => 'FAN2', 'Reading' => 40, 'ReadingUnits' => 'Percent', 'Status' => $ok],
                    ['Name' => 'FAN3', 'Reading' => null, 'Status' => ['State' => 'Absent']],
                ],
                'Temperatures' => [
                    ['Name' => 'CPU Temp', 'ReadingCelsius' => 48, 'UpperThresholdNonCritical' => 90, 'UpperThresholdCritical' => 100, 'Status' => $ok],
                    ['Name' => 'Inlet Temp', 'ReadingCelsius' => 23.5, 'UpperThresholdNonCritical' => 40, 'Status' => $ok],
                    ['Name' => 'Spare', 'ReadingCelsius' => null, 'Status' => $ok],
                ],
            ]],
            '/redfish/v1/Systems/1/Processors' => [200, ['Members' => [['@odata.id' => '/redfish/v1/Systems/1/Processors/CPU1']]]],
            '/redfish/v1/Systems/1/Processors/CPU1' => [200, ['Socket' => 'CPU1', 'Model' => 'Example CPU 4585PX 16-Core', 'TotalCores' => 16, 'TotalThreads' => 32, 'Status' => $ok]],
            '/redfish/v1/Systems/1/Memory' => [200, ['Members' => [
                ['@odata.id' => '/redfish/v1/Systems/1/Memory/DIMM.Socket.A1'], ['@odata.id' => '/redfish/v1/Systems/1/Memory/DIMM.Socket.A2'],
            ]]],
            '/redfish/v1/Systems/1/Memory/DIMM.Socket.A1' => [200, ['DeviceLocator' => 'DIMMA1', 'CapacityMiB' => 65536, 'MemoryDeviceType' => 'DDR5', 'OperatingSpeedMhz' => 4800, 'Status' => $ok]],
            '/redfish/v1/Systems/1/Memory/DIMM.Socket.A2' => [200, ['DeviceLocator' => 'DIMMA2', 'CapacityMiB' => 0, 'Status' => ['State' => 'Absent']]],
            '/redfish/v1/Systems/1/Storage' => [200, ['Members' => [['@odata.id' => '/redfish/v1/Systems/1/Storage/RAID.1']]]],
            '/redfish/v1/Systems/1/Storage/RAID.1' => [200, ['Drives' => [
                ['@odata.id' => '/redfish/v1/Systems/1/Storage/RAID.1/Drives/Disk.Bay.0:Enclosure.Internal.0-1:RAID.1'],
                ['@odata.id' => '/redfish/v1/Systems/1/Storage/RAID.1/Drives/Disk.Bay.1:Enclosure.Internal.0-1:RAID.1'],
            ]]],
            '/redfish/v1/Systems/1/Storage/RAID.1/Drives/Disk.Bay.0:Enclosure.Internal.0-1:RAID.1' => [200, ['Name' => 'Disk 0', 'Model' => 'EXAMPLE-SSD-960', 'SerialNumber' => 'D0001',
                'CapacityBytes' => 960197124096, 'MediaType' => 'SSD', 'Protocol' => 'SATA', 'Status' => $ok, 'FailurePredicted' => false, 'PredictedMediaLifeLeftPercent' => 97]],
            '/redfish/v1/Systems/1/Storage/RAID.1/Drives/Disk.Bay.1:Enclosure.Internal.0-1:RAID.1' => [200, ['Name' => 'Disk 1', 'Model' => 'EXAMPLE-SSD-960', 'SerialNumber' => 'D0002',
                'CapacityBytes' => 960197124096, 'MediaType' => 'SSD', 'Protocol' => 'SATA', 'Status' => $ok, 'FailurePredicted' => false, 'PredictedMediaLifeLeftPercent' => 96]],
        ];
    }
}
