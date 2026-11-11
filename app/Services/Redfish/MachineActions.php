<?php

namespace App\Services\Redfish;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use MyCS\Extensions\Servers\Redfish\Redfish;
use MyCS\Redfish\RedfishClient;
use MyCS\Redfish\RedfishException;
use OwenIt\Auditing\Events\AuditCustom;
use RuntimeException;
use Throwable;

/**
 * What staff may tell a Redfish machine (owner 2026-11-11, «the simple ones first»): power, its locate light, its next
 * boot once, a restart of its BMC. Each with the account its server's settings give the actions: the reads' own when
 * «use the account above» is on, or a second one set apart (so the hourly read's stays read-only); neither, and no
 * action is offered. Each is written to the server's audit
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

    /** Seconds after a power action in which no other is sent to the same machine. */
    public const POWER_PAUSE = 10;

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
        self::run($server, "power: {$type}", fn (RedfishClient $bmc) => $bmc->reset($type), power: true);
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
     * One action at a time a machine (two clicks, two staff): written to the server's audit as it is sent and again with
     * how it went (done, not done, or no answer: unknown whether done).
     *
     * @param  callable(RedfishClient): void  $action
     *
     * @throws Throwable what the BMC refused, as staff are told
     */
    private static function run(Server $server, string $what, callable $action, bool $power = false): void
    {
        $extension = ExtensionHelper::getExtension('server', $server->extension, $server->settings);
        if (!$extension instanceof Redfish || !$extension->canAct()) {
            throw new RuntimeException("{$server->name} has no account for actions: turn on «Actions: use the account above» in its server settings, or set another.");
        }
        // Longer than the slowest action (its reads and the send, each with its timeout).
        $lock = Cache::lock("redfish.action.{$server->id}", 120);
        if (!$lock->get()) {
            throw new RuntimeException("Another action is being sent to {$server->name}: try again in a moment.");
        }
        try {
            // A power action sent a moment ago is not sent again by a second click or a replayed request.
            if ($power && !Cache::lock("redfish.power.{$server->id}", self::POWER_PAUSE)->get()) {
                throw new RuntimeException("A power action was sent to {$server->name} a moment ago: wait " . self::POWER_PAUSE . ' seconds and read the machine before another.');
            }
            // Written before it is sent (review of #800): a request cut short after the send still leaves who asked.
            self::audit($server, "redfish {$what}: sending");
            try {
                $action($extension->actionClient());
            } catch (Throwable $e) {
                $unknown = $e instanceof RedfishException && $e->getCode() === RedfishException::UNANSWERED;
                rescue(fn () => self::audit($server, "redfish {$what} (" . ($unknown ? 'no answer, unknown whether done' : 'not done') . ': ' . mb_substr($e->getMessage(), 0, 200) . ')'));
                throw $e;
            }
            // The BMC took it: a failure to write that down is reported, never shown as a failed action.
            rescue(fn () => self::audit($server, "redfish {$what}: done"));
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
