<?php

namespace Paymenter\Extensions\Servers\FalseServer;

use App\Classes\Extension\Server;
use App\Models\Service;

/**
 * A server adapter in the old convention: it reports a refusal by returning false (ServerAdapterFailureTest).
 */
class FalseServer extends Server
{
    public function getConfig($values = []): array
    {
        return [];
    }

    public function createServer(Service $service, $settings, $properties)
    {
        return false;
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        return false;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        return false;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        return false;
    }
}
