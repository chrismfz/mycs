<?php

namespace App\Classes\Extension;

use App\Models\Service;
use LogicException;

/**
 * The base of a module with no API (OfflineServer): it offers every server action, so that each reaches
 * ExtensionHelper, which makes it a task for staff instead of calling here.
 */
abstract class OfflineMachineServer extends Server implements OfflineServer
{
    public function getConfig($values = []): array
    {
        return [];
    }

    public function getProductConfig($values = []): array
    {
        return [];
    }

    public function testConfig(): bool|string
    {
        return true;
    }

    public function noun(): string
    {
        return 'machine';
    }

    public function createServer(Service $service, $settings, $properties)
    {
        throw new LogicException('An offline machine is created by hand (ExtensionHelper makes it a task).');
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        throw new LogicException('An offline machine is suspended by hand (ExtensionHelper makes it a task).');
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        throw new LogicException('An offline machine is unsuspended by hand (ExtensionHelper makes it a task).');
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        throw new LogicException('An offline machine is terminated by hand (ExtensionHelper makes it a task).');
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        throw new LogicException('An offline machine is changed by hand (ExtensionHelper makes it a task).');
    }
}
