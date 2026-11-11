<?php

namespace MyCS\Redfish\Tests;

use MyCS\Redfish\ActionTransport;

/** A BMC that also takes changes: each POST or PATCH is kept, and answered 204 unless told otherwise. */
final class FakeActionTransport implements ActionTransport
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: array<string, string>}> method, path, body, headers */
    public array $writes = [];

    private FakeTransport $reads;

    /**
     * @param  array<string, array{0: int, 1: mixed}>  $answers  the reads, by path
     * @param  array<string, array{0: int, 1: mixed}>  $refusals  «METHOD path» => [status, body]
     */
    public function __construct(array $answers, public array $refusals = [])
    {
        $this->reads = new FakeTransport($answers);
    }

    public function get(string $path, array $headers): array
    {
        return $this->reads->get($path, $headers);
    }

    public function send(string $method, string $path, array $headers, array $json): array
    {
        $this->writes[] = [$method, $path, $json, $headers];
        [$status, $data] = $this->refusals["{$method} {$path}"] ?? [204, ''];

        return [$status, is_string($data) ? $data : (string) json_encode($data)];
    }

    /**
     * A BMC with its actions, as the DMTF's mockups show them (made-up values).
     *
     * @return array<string, array{0: int, 1: mixed}>
     */
    public static function bmc(): array
    {
        $answers = FakeTransport::bmc();
        $answers['/redfish/v1/Systems/1'][1] += [
            '@odata.etag' => 'W/"abc123"',
            'IndicatorLED' => 'Off',
            'Boot' => ['BootSourceOverrideEnabled' => 'Disabled', 'BootSourceOverrideTarget' => 'None',
                'BootSourceOverrideTarget@Redfish.AllowableValues' => ['None', 'Pxe', 'Hdd', 'BiosSetup']],
            'Actions' => ['#ComputerSystem.Reset' => ['target' => '/redfish/v1/Systems/1/Actions/ComputerSystem.Reset',
                'ResetType@Redfish.AllowableValues' => ['On', 'ForceOff', 'GracefulShutdown', 'GracefulRestart', 'ForceRestart']]],
        ];

        return $answers + [
            '/redfish/v1/Managers' => [200, ['Members' => [['@odata.id' => '/redfish/v1/Managers/1']]]],
            '/redfish/v1/Managers/1' => [200, ['Actions' => ['#Manager.Reset' => ['target' => '/redfish/v1/Managers/1/Actions/Manager.Reset']]]],
        ];
    }
}
