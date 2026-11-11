<?php

namespace MyCS\Redfish\Tests;

use MyCS\Redfish\Machine;
use MyCS\Redfish\RedfishClient;
use MyCS\Redfish\RedfishException;
use PHPUnit\Framework\TestCase;

final class RedfishClientTest extends TestCase
{
    /** @param  array<string, array{0: int, 1: mixed}>  $answers */
    private function read(array $answers, ?FakeTransport &$http = null): Machine
    {
        $http = new FakeTransport($answers);

        return (new RedfishClient($http, 'reader', 's3cret'))->machine();
    }

    public function test_it_reads_the_machine_its_health_and_its_power(): void
    {
        $machine = $this->read(FakeTransport::bmc(), $http);

        $this->assertSame(['Contoso', 'SYS-1000', 'S000001', 'SKU-1', 'RACK4-U12', 'box.example.com', '3.4'],
            [$machine->manufacturer, $machine->model, $machine->serial, $machine->sku, $machine->assetTag, $machine->hostname, $machine->biosVersion]);
        $this->assertSame('On', $machine->powerState);
        $this->assertTrue($machine->isOn());
        $this->assertSame('OK', $machine->health);
        $this->assertFalse($machine->needsAttention());
        $this->assertSame([2, 'Example CPU 4210', 128.0], [$machine->cpuCount, $machine->cpuModel, $machine->memoryGiB]);
        $this->assertSame([182.0, 170.0, 180.0, 240.0, 5], [$machine->watts, $machine->wattsMin, $machine->wattsAverage, $machine->wattsMax, $machine->wattsIntervalMinutes]);
        $this->assertSame(1, $machine->systems);
    }

    public function test_it_only_reads_and_signs_in_with_basic_on_every_request(): void
    {
        $this->read(FakeTransport::bmc(), $http);

        $this->assertSame(['/redfish/v1/Systems', '/redfish/v1/Systems/1', '/redfish/v1/Chassis', '/redfish/v1/Chassis/1', '/redfish/v1/Chassis/1/Power'], $http->paths());
        foreach ($http->sent as [, $headers]) {
            $this->assertSame('Basic ' . base64_encode('reader:s3cret'), $headers['Authorization']);
        }
    }

    public function test_the_worst_health_of_system_and_chassis_is_the_machines(): void
    {
        $answers = FakeTransport::bmc();
        $answers['/redfish/v1/Chassis/1'][1]['Status'] = ['Health' => 'OK', 'HealthRollup' => 'Critical']; // a power supply gone
        $machine = $this->read($answers);

        $this->assertSame('Critical', $machine->health);
        $this->assertTrue($machine->needsAttention());

        $answers['/redfish/v1/Chassis/1'][1]['Status'] = ['Health' => 'OK'];
        $answers['/redfish/v1/Systems/1'][1]['Status'] = ['Health' => 'Warning', 'HealthRollup' => 'OK'];
        $this->assertSame('Warning', $this->read($answers)->health);
    }

    public function test_a_health_it_does_not_know_is_no_health(): void
    {
        $answers = FakeTransport::bmc();
        $answers['/redfish/v1/Systems/1'][1]['Status'] = ['Health' => 'Fine'];
        $answers['/redfish/v1/Chassis/1'][1]['Status'] = 'OK';
        $machine = $this->read($answers);

        $this->assertNull($machine->health);
        $this->assertFalse($machine->needsAttention());
    }

    public function test_a_machine_that_is_off(): void
    {
        $answers = FakeTransport::bmc();
        $answers['/redfish/v1/Systems/1'][1]['PowerState'] = 'Off';
        $answers['/redfish/v1/Chassis/1/Power'][1] = ['PowerControl' => [['PowerConsumedWatts' => 9]]];
        $machine = $this->read($answers);

        $this->assertFalse($machine->isOn());
        $this->assertSame(9.0, $machine->watts);
        $this->assertNull($machine->wattsAverage);
    }

    public function test_the_first_power_control_is_the_machines_total(): void
    {
        $answers = FakeTransport::bmc();
        $answers['/redfish/v1/Chassis/1/Power'][1] = ['PowerControl' => [
            ['PowerConsumedWatts' => 160.5, 'PowerMetrics' => ['MaxConsumedWatts' => 200]], // the total
            ['PowerConsumedWatts' => 100], // its processors
            ['PowerConsumedWatts' => 40], // its memory
        ]];
        $machine = $this->read($answers);

        $this->assertSame(160.5, $machine->watts);
        $this->assertSame(200.0, $machine->wattsMax);
    }

    public function test_a_redirect_to_the_paths_other_form_is_asked_once_on_the_same_bmc(): void
    {
        $answers = FakeTransport::bmc();
        $answers['/redfish/v1/Systems/'] = $answers['/redfish/v1/Systems'];
        $answers['/redfish/v1/Systems'] = [308, ''];
        $answers['/redfish/v1/Systems/'][1]['Members'] = [['@odata.id' => '/redfish/v1/Systems/1/']];
        $answers['/redfish/v1/Systems/1/'] = $answers['/redfish/v1/Systems/1'];
        unset($answers['/redfish/v1/Systems/1']);
        $machine = $this->read($answers, $http);

        $this->assertSame('SYS-1000', $machine->model);
        $this->assertSame(['/redfish/v1/Systems', '/redfish/v1/Systems/', '/redfish/v1/Systems/1/'], array_slice($http->paths(), 0, 3));

        // A BMC that only ever redirects is not chased.
        try {
            $this->read(['/redfish/v1/Systems' => [301, ''], '/redfish/v1/Systems/' => [301, '']], $http);
            $this->fail();
        } catch (RedfishException $e) {
            $this->assertStringContainsString('HTTP 301', $e->getMessage());
            $this->assertCount(2, $http->sent);
        }
    }

    public function test_the_systems_own_chassis_is_read_before_the_first_listed(): void
    {
        $answers = FakeTransport::bmc();
        $answers['/redfish/v1/Systems/1'][1]['Links'] = ['Chassis' => [['@odata.id' => '/redfish/v1/Chassis/Self']]];
        $answers['/redfish/v1/Chassis/Self'] = [200, ['AssetTag' => 'OWN', 'Status' => ['Health' => 'Warning']]];
        $answers['/redfish/v1/Chassis/Self/Power'] = [200, ['PowerControl' => [['PowerConsumedWatts' => 77]]]];
        $machine = $this->read($answers, $http);

        $this->assertSame(['OWN', 'Warning', 77.0], [$machine->assetTag, $machine->health, $machine->watts]);
        $this->assertNotContains('/redfish/v1/Chassis', $http->paths());
    }

    public function test_power_from_environment_metrics_where_power_is_gone(): void
    {
        $answers = FakeTransport::bmc();
        unset($answers['/redfish/v1/Chassis/1/Power'], $answers['/redfish/v1/Chassis/1'][1]['Power']);
        $answers['/redfish/v1/Chassis/1/EnvironmentMetrics'] = [200, ['PowerWatts' => ['Reading' => 201.5]]];

        $this->assertSame(201.5, $this->read($answers)->watts);
    }

    public function test_a_bmc_without_power_or_chassis_is_still_read(): void
    {
        $answers = FakeTransport::bmc();
        unset($answers['/redfish/v1/Chassis/1/Power']);
        $this->assertNull($this->read($answers)->watts);

        unset($answers['/redfish/v1/Chassis'], $answers['/redfish/v1/Chassis/1']);
        $machine = $this->read($answers, $http);
        $this->assertSame('SYS-1000', $machine->model);
        $this->assertNull($machine->assetTag);
        $this->assertSame(['/redfish/v1/Systems', '/redfish/v1/Systems/1', '/redfish/v1/Chassis'], $http->paths());
    }

    public function test_nonsense_readings_are_not_readings(): void
    {
        $answers = FakeTransport::bmc();
        $answers['/redfish/v1/Chassis/1/Power'][1] = ['PowerControl' => [['PowerConsumedWatts' => '182'], ['PowerConsumedWatts' => 5]]];
        $answers['/redfish/v1/Systems/1'][1]['ProcessorSummary'] = ['Count' => 'two', 'Model' => ['x']];
        $answers['/redfish/v1/Systems/1'][1]['MemorySummary'] = 'lots';
        $machine = $this->read($answers);

        $this->assertNull($machine->watts);
        $this->assertSame([null, null, null], [$machine->cpuCount, $machine->cpuModel, $machine->memoryGiB]);
    }

    public function test_a_bmcs_text_is_shortened_and_loses_its_control_characters(): void
    {
        $answers = FakeTransport::bmc();
        $answers['/redfish/v1/Systems/1'][1]['Model'] = "  SYS\n\t1000\x00" . str_repeat('x', 500);
        $answers['/redfish/v1/Systems/1'][1]['SerialNumber'] = '   ';
        $answers['/redfish/v1/Chassis/1'][1]['SerialNumber'] = 'C-77';
        $machine = $this->read($answers);

        $this->assertStringStartsWith('SYS 1000 xxx', (string) $machine->model);
        $this->assertSame(200, strlen((string) $machine->model));
        $this->assertSame('C-77', $machine->serial); // the chassis', when the system says none
    }

    public function test_a_link_elsewhere_is_never_followed(): void
    {
        foreach (['https://evil.example/redfish/v1/Systems/1', '//evil.example/x', '/redfish/v1/../../admin', '/other/1', '/redfish/v1/Systems/1?x=1', '/redfish/v1/Systems//1', "/redfish/v1/Systems/1\n", 7] as $link) {
            $answers = FakeTransport::bmc();
            $answers['/redfish/v1/Systems'][1]['Members'] = [['@odata.id' => $link]];
            try {
                $this->read($answers, $http);
                $this->fail('read a system from ' . json_encode($link));
            } catch (RedfishException $e) {
                $this->assertSame('The BMC lists no system.', $e->getMessage());
            }
            $this->assertSame(['/redfish/v1/Systems'], $http->paths());
        }
    }

    public function test_the_first_of_several_systems_is_read_and_their_number_kept(): void
    {
        $answers = FakeTransport::bmc();
        $answers['/redfish/v1/Systems'][1]['Members'][] = ['@odata.id' => '/redfish/v1/Systems/2'];

        $this->assertSame(2, $this->read($answers)->systems);
    }

    public function test_a_refused_login_is_said_even_on_an_optional_read(): void
    {
        $answers = FakeTransport::bmc();
        $answers['/redfish/v1/Chassis/1/Power'] = [401, ''];
        try {
            $this->read($answers);
            $this->fail();
        } catch (RedfishException $e) {
            $this->assertSame(401, $e->getCode());
            $this->assertStringNotContainsString('s3cret', $e->getMessage());
        }
    }

    public function test_why_a_bmc_cannot_be_read(): void
    {
        $cases = [
            'refused the login' => [401, ''],
            'did not answer' => [0, ''],
            'may not read' => [403, ''],
            'firmware too old' => [404, ''],
            'Not a Redfish answer' => [200, '<html>login</html>'],
            'HTTP 500' => [500, 'oops'],
        ];
        foreach ($cases as $says => $answer) {
            try {
                $this->read(['/redfish/v1/Systems' => $answer]);
                $this->fail($says);
            } catch (RedfishException $e) {
                $this->assertStringContainsString($says, $e->getMessage());
            }
        }
    }

    public function test_no_username_is_refused_before_any_request(): void
    {
        $this->expectException(RedfishException::class);
        new RedfishClient(new FakeTransport, ' ', 'x');
    }
}
