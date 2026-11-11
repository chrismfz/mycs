<?php

namespace App\Admin\Resources\RedfishMachineResource;

use App\Models\RedfishMachine;
use App\Services\Redfish\MachineActions;
use App\Services\Redfish\MachineSync;
use App\Support\Redact;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Throwable;

/**
 * A machine's actions on its page (owner 2026-11-11): power, locate light, boot once, BMC restart. Shown only when its
 * server has an actions' account, to staff who may update servers; each asks first and says who has the machine; one
 * that interrupts what runs on it asks for the machine's name too. What was sent is in the server's audit.
 */
final class MachineActionButtons
{
    /** @return list<Action|ActionGroup> */
    public static function make(): array
    {
        $power = [];
        foreach (MachineActions::POWER as $type => [$label, $does, $interrupts]) {
            $power[] = self::button('power' . $type, $label, $does, fn (RedfishMachine $record) => MachineActions::power($record->server, $type), typed: $interrupts,
                done: 'The BMC took it. The machine\'s state changes in a few seconds: «Read now» shows it.')
                ->color($interrupts ? 'danger' : 'gray');
        }
        $boot = [];
        foreach (MachineActions::BOOT as $target => $label) {
            $boot[] = self::button('boot' . $target, $label, "The next boot only will be from: {$label}. The machine is not restarted now.",
                fn (RedfishMachine $record) => MachineActions::bootOnce($record->server, $target), reread: true);
        }
        $boot[] = self::button('bootCancel', 'Cancel boot once', 'A boot-once set before is forgotten: the next boot is as the BIOS says.',
            fn (RedfishMachine $record) => MachineActions::bootOnce($record->server, null), reread: true);

        return [
            ActionGroup::make($power)->label('Power')->icon('ri-shut-down-line')->button()->color('gray')->visible(self::offered(...)),
            ActionGroup::make([
                self::button('locateOn', 'Locate light on', 'Turns on the machine\'s locate light (UID), to find it in the rack. It stays on until turned off.',
                    fn (RedfishMachine $record) => MachineActions::locate($record->server, true), reread: true),
                self::button('locateOff', 'Locate light off', 'Turns the machine\'s locate light (UID) off.',
                    fn (RedfishMachine $record) => MachineActions::locate($record->server, false), reread: true),
                ...$boot,
                self::button('bmcRestart', 'Restart the BMC', 'Restarts the BMC itself, for when it hangs. The machine keeps running; the BMC does not answer for a minute or two.',
                    fn (RedfishMachine $record) => MachineActions::resetBmc($record->server)),
            ])->label('More')->icon('ri-more-2-line')->button()->color('gray')->visible(self::offered(...)),
        ];
    }

    public static function offered(RedfishMachine $record): bool
    {
        return $record->server !== null && (bool) auth('staff')->user()?->can('update', $record) && MachineActions::offered($record->server);
    }

    /**
     * @param  Closure(RedfishMachine): void  $run
     * @param  bool  $typed  the machine's name is typed to confirm (it interrupts what runs on it)
     * @param  bool  $reread  its summary is read again after, to show the change
     */
    private static function button(string $name, string $label, string $does, Closure $run, bool $typed = false, bool $reread = false, ?string $done = null): Action
    {
        return Action::make($name)->label($label)
            ->authorize('update')
            ->visible(self::offered(...))
            ->requiresConfirmation()
            ->modalHeading(fn (RedfishMachine $record) => "{$label}: {$record->server->name}")
            ->modalDescription(fn (RedfishMachine $record) => $does . ' ' . self::whose($record))
            ->modalSubmitActionLabel($label)
            ->schema($typed ? [TextInput::make('name')->label('Type the machine\'s name to confirm')->required()->autocomplete(false)
                ->rule(fn (RedfishMachine $record) => function (string $attribute, mixed $value, Closure $fail) use ($record) {
                    if (trim((string) $value) !== $record->server->name) {
                        $fail('That is not this machine\'s name.');
                    }
                })] : [])
            ->action(function (RedfishMachine $record) use ($run, $label, $reread, $done) {
                try {
                    $run($record);
                } catch (Throwable $e) {
                    Notification::make()->danger()->title("{$label}: not done")->body(e(Redact::text($e->getMessage())))->persistent()->send();

                    return;
                }
                if ($reread) {
                    rescue(fn () => MachineSync::sync($record->server, budget: 0.0), report: false); // its summary only
                    $record->refresh();
                }
                Notification::make()->success()->title("{$label}: sent")->body($done)->send();
            });
    }

    /** Who is affected, said in every confirmation. */
    private static function whose(RedfishMachine $record): string
    {
        $given = RedfishMachine::givenTo($record->server);

        return match (true) {
            $given['services'] !== [] => 'This machine is given to: ' . implode('; ', array_column($given['services'], 'label')) . '.',
            $given['own'] => 'This is a machine of our own: check what runs on it.',
            default => 'This machine is free.',
        };
    }
}
