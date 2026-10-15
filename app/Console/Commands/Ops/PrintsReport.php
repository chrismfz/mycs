<?php

namespace App\Console\Commands\Ops;

/** The ops commands' output (docs/ops.md): readable by default, JSON with --json (for scripts and agents). */
trait PrintsReport
{
    /** @param array<array-key, mixed> $report */
    protected function report(array $report): void
    {
        if ($this->option('json')) {
            foreach (explode("\n", (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) as $line) {
                $this->line($line);
            }

            return;
        }
        $this->print($report, 0);
    }

    /** @param array<array-key, mixed> $data */
    private function print(array $data, int $depth): void
    {
        $pad = str_repeat('  ', $depth);
        foreach ($data as $key => $value) {
            $label = is_int($key) ? '-' : "{$key}:";
            if (is_array($value)) {
                if ($value === []) {
                    $this->line("{$pad}{$label} —");

                    continue;
                }
                if (array_is_list($value) && !is_array($value[0] ?? null)) {
                    $this->line("{$pad}{$label} " . implode(', ', array_map(fn ($item) => (string) $item, $value)));

                    continue;
                }
                $this->line("{$pad}{$label}");
                $this->print($value, $depth + 1);

                continue;
            }
            $this->line("{$pad}{$label} " . ($value === null ? '—' : (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value)));
        }
    }
}
