<?php

namespace App\Services\Ops;

use App\Support\Redact;

/**
 * The latest entries of the app's log (storage/logs/laravel*.log, docs/ops.md), newest first, at a level and above,
 * each with the head of its stack, through Redact (stored secrets, cPanel sessions, webhook tokens) before they leave.
 */
final class LogTail
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7];

    /** Bytes read from the end of each file: enough for hundreds of entries, never the whole file. */
    private const TAIL_BYTES = 2_000_000;

    /** @return list<array{at: string, level: string, message: string, trace: list<string>, file: string}> */
    public static function entries(int $limit = 20, string $minLevel = 'error', string $channel = 'laravel'): array
    {
        $min = self::LEVELS[strtolower($minLevel)] ?? self::LEVELS['error'];
        $files = glob(storage_path('logs/' . basename($channel) . '*.log')) ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $entries = [];
        foreach ($files as $file) {
            foreach (array_reverse(self::parse($file)) as $entry) {
                if ((self::LEVELS[$entry['level']] ?? 0) >= $min) {
                    $entries[] = $entry;
                    if (count($entries) >= $limit) {
                        return $entries;
                    }
                }
            }
        }

        return $entries;
    }

    /** @return list<array{at: string, level: string, message: string, trace: list<string>, file: string}> */
    private static function parse(string $file): array
    {
        $size = (int) filesize($file);
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return [];
        }
        fseek($handle, max(0, $size - self::TAIL_BYTES));
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        $entries = [];
        foreach (preg_split('/^(?=\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/m', $text) ?: [] as $chunk) {
            if (!preg_match('/^\[(?<at>[^\]]+)\]\s+\S+?\.(?<level>[A-Z]+):\s(?<rest>.*)$/s', $chunk, $match)) {
                continue;
            }
            $lines = preg_split('/\R/', Redact::text(trim($match['rest']))) ?: [];
            $trace = array_values(array_filter($lines, fn ($line) => preg_match('/^#\d+ /', $line) === 1));
            $entries[] = [
                'at' => $match['at'],
                'level' => strtolower($match['level']),
                'message' => mb_substr((string) ($lines[0] ?? ''), 0, 2000),
                'trace' => array_slice($trace, 0, 5),
                'file' => basename($file),
            ];
        }

        return $entries;
    }
}
