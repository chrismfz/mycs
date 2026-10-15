<?php

namespace App\Console\Commands\Ops;

use App\Services\Ops\Health;
use Illuminate\Console\Command;

/** Is MyCS well? Exit 0 ok, 1 warn, 2 fail: for a monitor, or a shell before and after a deploy (docs/ops.md). */
class HealthCommand extends Command
{
    use PrintsReport;

    protected $signature = 'mycs:health {--json : JSON output}';

    protected $description = 'Read-only health report: build, scheduler, cron, queue, mail, module actions, licenses';

    public function handle(): int
    {
        $report = Health::report();
        if ($this->option('json')) {
            $this->report($report);
        } else {
            $this->line('MyCS health: ' . strtoupper($report['status']));
            foreach ($report['checks'] as $name => $check) {
                $mark = match ($check['status']) {
                    Health::OK => '<info>ok  </info>',
                    Health::WARN => '<comment>warn</comment>',
                    default => '<error>fail</error>',
                };
                $this->line("  {$mark} " . str_pad($name, 15) . $check['detail']);
            }
        }

        return match ($report['status']) {
            Health::OK => self::SUCCESS,
            Health::WARN => 1,
            default => 2,
        };
    }
}
