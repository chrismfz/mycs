<?php

namespace MyCS\Extensions\Servers\FlakyServer;

use App\Classes\Extension\Server;
use App\Models\Service;
use RuntimeException;

/** A server that refuses the next $failures actions, then does them (ModuleActionsTest). */
class FlakyServer extends Server
{
    public static int $failures = 0;

    /** @var list<string> */
    public static array $calls = [];

    public function getConfig($values = []): array
    {
        return [];
    }

    private function act(string $action): bool
    {
        self::$calls[] = $action;
        if (self::$failures > 0) {
            self::$failures--;
            throw new RuntimeException("Server unreachable during {$action}");
        }

        return true;
    }

    public function createServer(Service $service, $settings, $properties)
    {
        return $this->act('create');
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        return $this->act('suspend');
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        return $this->act('unsuspend');
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        return $this->act('terminate');
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        return $this->act('upgrade');
    }
}
