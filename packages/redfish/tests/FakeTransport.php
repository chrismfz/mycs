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
}
