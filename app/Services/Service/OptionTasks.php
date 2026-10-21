<?php

namespace App\Services\Service;

use App\Admin\Resources\ServiceResource;
use App\Helpers\NotificationHelper;
use App\Models\ModuleAction;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The options staff do by hand (ConfigOption::staff_task; owner 2026-10-21: a dedicated IP, an extra IPv4, never by the
 * module): a service taking one (it starts with it, or an upgrade adds it) or giving it back (a downgrade, its end) opens
 * a task for staff, a Pending Module Action they close when done, with a mail. Not on an option that did not change.
 */
final class OptionTasks
{
    public const ACTION = 'staff_task';

    /** A value that means «without it» (its env, else its name), in English or Greek. */
    public const OFF = ['', '0', 'no', 'none', 'off', 'false', 'όχι', 'οχι', 'χωρίς', 'χωρις', 'κανένα', 'κανενα', 'καμία', 'καμια'];

    /**
     * Its staff options that are on, as their value reads: option id => «Dedicated IP: Yes».
     *
     * @return array<int, string>
     */
    public static function on(Service $service): array
    {
        $on = [];
        foreach ($service->configs()->with(['configOption', 'configValue'])->get() as $config) {
            $option = $config->configOption;
            $value = $config->configValue;
            if ($option === null || !$option->staff_task || $value === null) {
                continue;
            }
            $raw = mb_strtolower(trim((string) ($value->env_variable ?? $value->name)));
            if (in_array($raw, self::OFF, true)) {
                continue; // «No», «None»: not taken
            }
            $on[$option->id] = $option->name . ': ' . $value->name;
        }

        return $on;
    }

    /**
     * What changed between two readings of its staff options: a task for each taken and each given back.
     *
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    public static function changed(Service $service, array $before, array $after): void
    {
        $tasks = [];
        foreach ($after as $id => $label) {
            if (!array_key_exists($id, $before)) {
                $tasks[$id] = "Give: {$label}";
            } elseif ($before[$id] !== $label) {
                $tasks[$id] = "Change: {$before[$id]} → {$label}"; // 2 IPs → 1: not «give» (reviews of #296)
            }
        }
        foreach ($before as $id => $label) {
            if (!array_key_exists($id, $after)) {
                $tasks[$id] = "Take back: {$label}";
            }
        }
        foreach ($tasks as $id => $task) {
            self::open($service, (string) $id, $task);
        }
    }

    private static function open(Service $service, string $optionId, string $task): void
    {
        $what = "{$task} — service #{$service->id}" . ($service->domain ? " ({$service->domain})" : '');
        // The same task still open (its status flapping: active, pending, active): not twice.
        if (ModuleAction::query()->open()->where('service_id', $service->id)->where('action', self::ACTION)->where('subject', $optionId)
            ->where('last_error', 'like', $task . ' —%')->exists()) {
            return;
        }
        ModuleAction::query()->create(['service_id' => $service->id, 'action' => self::ACTION, 'subject' => $optionId,
            'status' => ModuleAction::STATUS_OPEN, 'attempts' => 0, 'last_attempt_at' => now(), 'given_up_at' => now(),
            'last_error' => "{$what}. Do it by hand, then mark it resolved."]);
        DB::afterCommit(function () use ($service, $what) {
            try {
                NotificationHelper::sendSystemEmailNotification("Staff task: {$what}", e("{$what}. It is listed in Pending Module Actions: mark it resolved once done.")
                    . '<br><a href="' . e(ServiceResource::getUrl('edit', ['record' => $service->id], panel: 'admin')) . '">' . e('Service #' . $service->id) . '</a>');
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
