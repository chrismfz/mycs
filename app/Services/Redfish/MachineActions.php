<?php

namespace App\Services\Redfish;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use MyCS\Extensions\Servers\Redfish\Redfish;
use MyCS\Redfish\RedfishClient;
use OwenIt\Auditing\Events\AuditCustom;
use RuntimeException;
use Throwable;

/**
 * What staff may tell a Redfish machine (owner 2026-11-11, «the simple ones first»): power, its locate light, its next
 * boot once, a restart of its BMC. Each with the server's **actions' account** (an Operator, set apart from the
 * read-only one of the hourly read): a server without one offers no action. Each is written to the server's audit
 * (who, what, whether the BMC took it) before anything else is told. Staff only; nothing here is a customer's.
 */
final class MachineActions
{
    /** Power actions: the standard's name => [label, what it does, whether it interrupts what runs on the machine]. */
    public const POWER = [
        'On' => ['Power on', 'Turns the machine on.', false],
        'GracefulShutdown' => ['Shut down', 'Asks the operating system to shut down (as a short press of the power button). An OS that ignores it stays on.', true],
        'GracefulRestart' => ['Restart', 'Asks the operating system to restart.', true],
        'ForceOff' => ['Force off', 'Cuts the power at once, as holding the power button: unsaved data is lost.', true],
        'ForceRestart' => ['Force restart', 'Resets the machine at once, without the operating system: unsaved data is lost.', true],
        'PowerCycle' => ['Power cycle', 'Power off, then on again: unsaved data is lost.', true],
    ];

    /** Boot-once sources: the standard's name => label. */
    public const BOOT = ['Pxe' => 'Network (PXE)', 'BiosSetup' => 'BIOS setup', 'Hdd' => 'Its disk', 'Cd' => 'CD / virtual media'];

    public static function offered(Server $server): bool
    {
        try {
            $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
        } catch (Throwable) {
            return false;
        }

        return $extension instanceof Redfish && $extension->canAct();
    }

    public static function power(Server $server, string $type): void
    {
        self::run($server, "power: {$type}", fn (RedfishClient $bmc) => $bmc->reset($type));
    }

    public static function locate(Server $server, bool $on): void
    {
        self::run($server, 'locate light: ' . ($on ? 'on' : 'off'), fn (RedfishClient $bmc) => $bmc->locate($on));
    }

    public static function bootOnce(Server $server, ?string $target): void
    {
        self::run($server, 'boot once: ' . ($target ?? 'cancelled'), fn (RedfishClient $bmc) => $bmc->bootOnce($target));
    }

    public static function resetBmc(Server $server): void
    {
        self::run($server, 'BMC restart', fn (RedfishClient $bmc) => $bmc->resetBmc());
    }

    /**
     * One action at a time a machine (two clicks, two staff): sent, and written to the server's audit with how it went.
     *
     * @param  callable(RedfishClient): void  $action
     *
     * @throws Throwable what the BMC refused, as staff are told
     */
    private static function run(Server $server, string $what, callable $action): void
    {
        $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
        if (!$extension instanceof Redfish || !$extension->canAct()) {
            throw new RuntimeException("{$server->name} has no actions' account: set one in its server settings.");
        }
        $lock = Cache::lock("redfish.action.{$server->id}", 60);
        if (!$lock->get()) {
            throw new RuntimeException("Another action is being sent to {$server->name}: try again in a moment.");
        }
        try {
            $action($extension->actionClient());
            self::audit($server, "redfish {$what}");
        } catch (Throwable $e) {
            self::audit($server, "redfish {$what} (not done: " . mb_substr($e->getMessage(), 0, 200) . ')');
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private static function audit(Server $server, string $action): void
    {
        $server->auditEvent = 'extension_action';
        $server->isCustomEvent = true;
        $server->auditCustomOld = [];
        $server->auditCustomNew = ['action' => $action];
        Event::dispatch(new AuditCustom($server));
    }
}
