<?php

namespace App\Mcp\Tools;

use App\Models\CronStat;
use App\Models\Setting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('cron_history')]
#[Description('What the daily cron did each day (Automation Status): per day, each step and how many it processed (invoices created, services suspended, mails sent, logs pruned…), and when the scheduler and the daily cron last ran. For "did the cron run / why was nothing invoiced". Read only.')]
#[IsReadOnly]
#[IsIdempotent]
class CronHistoryTool extends McpTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('Days back (default 7, at most 90).'),
            'step' => $schema->string()->description('Only this step (e.g. invoices_created, services_suspended).'),
        ];
    }

    protected function permission(): string
    {
        return 'admin.cron_stats.view';
    }

    protected function run(Request $request): array
    {
        $days = max(1, min(90, (int) ($request->get('days') ?? 7)));
        $last = fn (string $key) => Setting::query()->where('key', $key)->where('settingable_type', CronStat::class)->value('value');
        $rows = CronStat::query()->where('date', '>=', now()->subDays($days - 1)->toDateString())
            ->when(filled($request->get('step')), fn ($query) => $query->where('key', (string) $request->get('step')))
            ->orderByDesc('date')->orderBy('key')->get(['key', 'value', 'date']);

        return [
            'last_scheduler_run' => $last('last_scheduler_run'),
            'last_cron_run' => $last('last_cron_run'),
            'days' => $rows->groupBy(fn (CronStat $stat) => $stat->date->toDateString())
                ->map(fn ($stats) => $stats->groupBy('key')->map(fn ($same) => $same->sum(fn (CronStat $stat) => (int) $stat->value))->all())->all(),
        ];
    }
}
