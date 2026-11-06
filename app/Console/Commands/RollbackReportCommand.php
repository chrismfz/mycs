<?php

namespace App\Console\Commands;

use App\Services\Ops\RollbackReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The report of a return to WHMCS (cutover-runbook §3): what the MyCS did since the cut-over, part by part, and what to
 * do with each in WHMCS. Read only. --csv writes one file per part (for a script that passes them back).
 */
class RollbackReportCommand extends Command
{
    protected $signature = 'mycs:rollback-report
        {--since= : The cut-over: when the MyCS opened (e.g. "2026-11-14 22:00", the instance\'s time zone)}
        {--until= : Up to (default: now)}
        {--csv= : A directory: one CSV per part}
        {--all : Also the parts with nothing in them}';

    protected $description = 'What the MyCS did since the cut-over that goes back to WHMCS (read-only)';

    public function handle(): int
    {
        try {
            $since = Carbon::parse((string) $this->option('since'));
            $until = $this->option('until') ? Carbon::parse((string) $this->option('until')) : null;
        } catch (Throwable) {
            $since = null;
        }
        if (!$this->option('since') || $since === null) {
            $this->error('--since is the cut-over\'s time, e.g. --since="2026-11-14 22:00".');

            return self::INVALID;
        }
        if ($since->isFuture()) {
            $this->error('--since is in the future.');

            return self::INVALID;
        }

        $dir = $this->option('csv');
        if ($dir !== null && !is_dir((string) $dir) && !@mkdir((string) $dir, 0700, true)) {
            $this->error("Cannot write to {$dir}.");

            return self::FAILURE;
        }

        $sections = (new RollbackReport($since, $until ?? null))->sections();
        $this->info('Since ' . $since->format('Y-m-d H:i') . ($until ? ' until ' . $until->format('Y-m-d H:i') : '') . ' (' . config('app.timezone') . ')');
        $this->table(['part', 'rows'], array_map(fn ($s) => [$s['title'], count($s['rows'])], $sections));

        foreach ($sections as $section) {
            if ($dir !== null) {
                $this->csv(rtrim((string) $dir, '/') . '/' . $section['key'] . '.csv', $section['columns'], $section['rows']);
            }
            if ($section['rows'] === [] && !$this->option('all')) {
                continue;
            }
            $this->newLine();
            $this->line('<options=bold>' . $section['title'] . ' (' . count($section['rows']) . ')</>');
            $this->line('In WHMCS: ' . $section['todo']);
            if ($section['rows'] !== []) {
                $this->table($section['columns'], $section['rows']);
            }
        }
        if ($dir !== null) {
            $this->newLine();
            $this->info("CSV in {$dir} (customers' data: delete it when done).");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $columns
     * @param  list<list<string>>  $rows
     */
    private function csv(string $path, array $columns, array $rows): void
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            return;
        }
        fputcsv($handle, $columns, escape: '');
        foreach ($rows as $row) {
            // A cell starting with = + - @ is read as a formula by a spreadsheet: quoted as text.
            fputcsv($handle, array_map(fn (string $cell) => preg_match('/^(?:[=+@\t\r]|-(?!\d))/', $cell) ? "'" . $cell : $cell, $row), escape: '');
        }
        fclose($handle);
        @chmod($path, 0600);
    }
}
