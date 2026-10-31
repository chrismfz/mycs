<?php

namespace App\Services\Simulation;

use App\Classes\Extension\Server;
use App\Models\Service;

/**
 * A server that does nothing but remember (`mycs:simulate`): each account's state, and what it was asked, as «suspend #123».
 * It stands in for cPanel, mailcow, Centova and the rest, so the cron's decisions are seen without a request to any server.
 */
class SimServer extends Server
{
    /** @var array<int, string> service id => active|suspended|terminated */
    public static array $state = [];

    /** @var list<string> */
    public static array $calls = [];

    public function getConfig($values = []): array
    {
        return [];
    }

    private function act(Service $service, string $what, string $to): bool
    {
        self::$calls[] = "{$what} #{$service->id}";
        self::$state[$service->id] = $to;

        return true;
    }

    public function createServer(Service $service, $settings, $properties)
    {
        return $this->act($service, 'create', 'active');
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        return $this->act($service, 'suspend', 'suspended');
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        return $this->act($service, 'unsuspend', 'active');
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        return $this->act($service, 'terminate', 'terminated');
    }
}
