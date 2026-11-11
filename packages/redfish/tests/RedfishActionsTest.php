<?php

namespace MyCS\Redfish\Tests;

use MyCS\Redfish\RedfishClient;
use MyCS\Redfish\RedfishException;
use PHPUnit\Framework\TestCase;

final class RedfishActionsTest extends TestCase
{
    /**
     * @param  array<string, array{0: int, 1: mixed}>|null  $answers
     * @param  array<string, array{0: int, 1: mixed}>  $refusals
     */
    private function bmc(?FakeActionTransport &$http = null, ?array $answers = null, array $refusals = []): RedfishClient
    {
        $http = new FakeActionTransport($answers ?? FakeActionTransport::bmc(), $refusals);

        return new RedfishClient($http, 'operator', '0p-secret');
    }

    /** What makes $do refused, with nothing sent. */
    private function refused(callable $do, FakeActionTransport $http): string
    {
        try {
            $do();
        } catch (RedfishException $e) {
            $this->assertSame([], $http->writes, 'nothing was sent');

            return $e->getMessage();
        }
        $this->fail('not refused');
    }

    public function test_a_reset_is_posted_to_the_systems_own_target_with_the_actions_login(): void
    {
        $this->bmc($http)->reset('ForceRestart');

        [$method, $path, $body, $headers] = $http->writes[0];
        $this->assertSame(['POST', '/redfish/v1/Systems/1/Actions/ComputerSystem.Reset', ['ResetType' => 'ForceRestart']], [$method, $path, $body]);
        $this->assertSame('Basic ' . base64_encode('operator:0p-secret'), $headers['Authorization']);
        $this->assertArrayNotHasKey('If-Match', $headers);
        $this->assertCount(1, $http->writes);
    }

    public function test_a_reset_the_bmc_does_not_offer_or_mycs_does_not_send_is_refused_before_anything_is_sent(): void
    {
        $client = $this->bmc($http);

        $this->assertStringContainsString('does not offer «PowerCycle» (it offers: On, ForceOff', $this->refused(fn () => $client->reset('PowerCycle'), $http));
        $this->assertStringContainsString('not a reset MyCS sends', $this->refused(fn () => $client->reset('Nmi'), $http));
        $this->assertStringContainsString('not a reset MyCS sends', $this->refused(fn () => $client->reset('on'), $http));
    }

    public function test_a_bmc_that_says_neither_target_nor_values_gets_the_standards(): void
    {
        $answers = FakeActionTransport::bmc();
        unset($answers['/redfish/v1/Systems/1'][1]['Actions']);
        $this->bmc($http, $answers)->reset('PowerCycle');

        $this->assertSame(['POST', '/redfish/v1/Systems/1/Actions/ComputerSystem.Reset', ['ResetType' => 'PowerCycle']], array_slice($http->writes[0], 0, 3));
    }

    public function test_an_actions_target_elsewhere_is_never_used(): void
    {
        foreach (['https://evil.example/x', '/admin/reset', '/redfish/v1/../x'] as $target) {
            $answers = FakeActionTransport::bmc();
            $answers['/redfish/v1/Systems/1'][1]['Actions']['#ComputerSystem.Reset']['target'] = $target;
            $this->bmc($http, $answers)->reset('On');

            $this->assertSame('/redfish/v1/Systems/1/Actions/ComputerSystem.Reset', $http->writes[0][1]);
        }
    }

    public function test_the_locate_light_by_whichever_of_the_two_properties_the_bmc_has(): void
    {
        $this->bmc($http)->locate(true);
        [$method, $path, $body, $headers] = $http->writes[0];
        $this->assertSame(['PATCH', '/redfish/v1/Systems/1', ['IndicatorLED' => 'Blinking'], 'W/"abc123"'], [$method, $path, $body, $headers['If-Match']]);

        $this->bmc($http)->locate(false);
        $this->assertSame(['IndicatorLED' => 'Off'], $http->writes[0][2]);

        // The newer property, here on the chassis only.
        $answers = FakeActionTransport::bmc();
        unset($answers['/redfish/v1/Systems/1'][1]['IndicatorLED']);
        $answers['/redfish/v1/Chassis/1'][1]['LocationIndicatorActive'] = false;
        $this->bmc($http, $answers)->locate(true);
        $this->assertSame(['PATCH', '/redfish/v1/Chassis/1', ['LocationIndicatorActive' => true]], array_slice($http->writes[0], 0, 3));

        unset($answers['/redfish/v1/Chassis/1'][1]['LocationIndicatorActive']);
        $client = $this->bmc($http, $answers);
        $this->assertStringContainsString('no locate light', $this->refused(fn () => $client->locate(true), $http));
    }

    public function test_the_next_boot_once_and_its_cancel(): void
    {
        $this->bmc($http)->bootOnce('Pxe');
        $this->assertSame(['PATCH', '/redfish/v1/Systems/1', ['Boot' => ['BootSourceOverrideEnabled' => 'Once', 'BootSourceOverrideTarget' => 'Pxe']]], array_slice($http->writes[0], 0, 3));

        $this->bmc($http)->bootOnce(null);
        $this->assertSame(['Boot' => ['BootSourceOverrideEnabled' => 'Disabled']], $http->writes[0][2]);

        $client = $this->bmc($http);
        $this->assertStringContainsString('does not boot once from «Cd» (it offers: None, Pxe, Hdd, BiosSetup)', $this->refused(fn () => $client->bootOnce('Cd'), $http));
        $this->assertStringContainsString('not a boot source MyCS sets', $this->refused(fn () => $client->bootOnce('Usb'), $http));
    }

    public function test_the_bmcs_own_restart(): void
    {
        $this->bmc($http)->resetBmc();
        $this->assertSame(['POST', '/redfish/v1/Managers/1/Actions/Manager.Reset', ['ResetType' => 'GracefulRestart']], array_slice($http->writes[0], 0, 3));

        $answers = FakeActionTransport::bmc();
        $answers['/redfish/v1/Managers/1'][1]['Actions']['#Manager.Reset']['ResetType@Redfish.AllowableValues'] = ['ForceRestart'];
        $this->bmc($http, $answers)->resetBmc();
        $this->assertSame(['ResetType' => 'ForceRestart'], $http->writes[0][2]);

        $answers['/redfish/v1/Managers/1'][1]['Actions']['#Manager.Reset']['ResetType@Redfish.AllowableValues'] = ['ResetToDefaults'];
        $client = $this->bmc($http, $answers);
        $this->assertStringContainsString('offers no restart of itself', $this->refused(fn () => $client->resetBmc(), $http));
    }

    public function test_the_machines_read_says_its_locate_light_and_boot_once(): void
    {
        $answers = FakeActionTransport::bmc();
        $machine = $this->bmc($http, $answers)->machine();
        $this->assertSame([false, null], [$machine->locating, $machine->bootOnce]);

        $answers['/redfish/v1/Systems/1'][1]['IndicatorLED'] = 'Blinking';
        $answers['/redfish/v1/Systems/1'][1]['Boot'] = ['BootSourceOverrideEnabled' => 'Once', 'BootSourceOverrideTarget' => 'Pxe'];
        $machine = $this->bmc($http, $answers)->machine();
        $this->assertSame([true, 'Pxe'], [$machine->locating, $machine->bootOnce]);

        $answers['/redfish/v1/Systems/1'][1]['Boot'] = ['BootSourceOverrideEnabled' => 'Continuous', 'BootSourceOverrideTarget' => 'Pxe'];
        $this->assertNull($this->bmc($http, $answers)->machine()->bootOnce);
        $this->assertNull((new RedfishClient(new FakeTransport(FakeTransport::bmc()), 'r', 'x'))->machine()->locating, 'a BMC that says nothing of it');
    }

    public function test_what_a_bmc_refuses_is_said_in_its_own_words_without_the_password(): void
    {
        $target = 'POST /redfish/v1/Systems/1/Actions/ComputerSystem.Reset';
        $cases = [
            'did not answer: it may or may not have done it' => [0, ''],
            'refused the actions\' login (401)' => [401, ''],
            'needs the Operator role' => [403, ''],
            'The BMC refused (HTTP 400): The system is already powered on.' => [400, ['error' => ['message' => 'general', '@Message.ExtendedInfo' => [['Message' => "The system is\nalready powered on."]]]]],
            'The BMC refused (HTTP 409): busy' => [409, ['error' => ['message' => 'busy']]],
            'The BMC refused (HTTP 500).' => [500, '<html>'],
        ];
        foreach ($cases as $says => $answer) {
            try {
                $this->bmc($http, null, [$target => $answer])->reset('On');
                $this->fail($says);
            } catch (RedfishException $e) {
                $this->assertStringContainsString($says, $e->getMessage());
                $this->assertStringNotContainsString('0p-secret', $e->getMessage());
                $this->assertCount(1, $http->writes, 'sent once, never again');
            }
        }
    }

    public function test_the_client_that_reads_cannot_change_anything(): void
    {
        $http = new FakeTransport(FakeActionTransport::bmc());
        $client = new RedfishClient($http, 'reader', 's3cret');

        foreach ([fn () => $client->reset('ForceOff'), fn () => $client->locate(true), fn () => $client->bootOnce('Pxe'), fn () => $client->resetBmc()] as $do) {
            try {
                $do();
                $this->fail('a reading client changed something');
            } catch (RedfishException $e) {
                $this->assertSame('This connection to the BMC only reads.', $e->getMessage());
            }
        }
    }

    public function test_an_etag_that_is_not_one_is_not_sent_back(): void
    {
        $answers = FakeActionTransport::bmc();
        $answers['/redfish/v1/Systems/1'][1]['@odata.etag'] = "x\r\nX-Injected: 1";
        $this->bmc($http, $answers)->locate(true);

        $this->assertArrayNotHasKey('If-Match', $http->writes[0][3]);
    }
}
