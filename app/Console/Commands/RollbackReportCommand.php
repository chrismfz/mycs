<?php

namespace App\Console\Commands;

use App\Services\Ops\RollbackReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
        $since = $this->moment('since');
        if ($since === null) {
            $this->error('--since is the cut-over\'s time, e.g. --since="2026-11-14 22:00" (or with its zone, "… +02:00").');

            return self::INVALID;
        }
        $until = $this->option('until') ? $this->moment('until') : null;
        if ($this->option('until') && $until === null) {
            $this->error('--until is not a time.');

            return self::INVALID;
        }
        if ($since->isFuture()) {
            $this->error('--since is in the future.');

            return self::INVALID;
        }
        if ($until !== null && $until->lt($since)) {
            $this->error('--until is before --since.');

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
                // Cut on screen only; the CSV keeps every cell whole.
                $this->table($section['columns'], array_map(fn (array $row) => array_map(fn (string $cell) => Str::limit($cell, 120), $row), $section['rows']));
            }
        }
        if ($dir !== null) {
            $this->newLine();
            $this->info("CSV in {$dir} (customers' data: delete it when done).");
        }

        return self::SUCCESS;
    }

    private function moment(string $option): ?Carbon
    {
        if (!$this->option($option)) {
            return null;
        }
        try {
            return Carbon::parse((string) $this->option($option));
        } catch (Throwable) {
            return null;
        }
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
            // A number stays a number; any other cell starting with one of these is text (ADV-690-9: «-1+cmd|…»).
            fputcsv($handle, array_map(fn (string $cell) => !preg_match('/^-?\d+(\.\d+)?$/', $cell) && preg_match('/^[=+\-@\t\r]/', $cell) ? "'" . $cell : $cell, $row), escape: '');
        }
        fclose($handle);
        @chmod($path, 0600);
    }
}
