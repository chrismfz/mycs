<?php

namespace App\Services\Ops;

/**
 * The latest entries of the app's log (storage/logs/laravel*.log, docs/ops.md), newest first, at a level and above,
 * each with the head of its stack, through Scrub (Redact's rules, URLs' secret keys, Authorization headers, the config's
 * secrets) before they leave.
 */
final class LogTail
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'notice' => 2, 'warning' => 3, 'error' => 4, 'critical' => 5, 'alert' => 6, 'emergency' => 7];

    public const MAX_ENTRIES = 200;

    /** Bytes read from the end of each file: enough for hundreds of entries, never the whole file. */
    private const TAIL_BYTES = 2_000_000;

    /** @return list<array{at: string, level: string, message: string, trace: list<string>, file: string}> */
    public static function entries(int $limit = 20, string $minLevel = 'error', string $channel = 'laravel'): array
    {
        $min = self::LEVELS[strtolower($minLevel)] ?? self::LEVELS['error'];
        $limit = max(1, min(self::MAX_ENTRIES, $limit));
        // A name, not a pattern (review 5): `*` or empty would read every log.
        if (preg_match('/^[a-z0-9_-]+$/', $channel) !== 1) {
            return [];
        }
        $files = glob(storage_path("logs/{$channel}.log")) ?: [];
        $files = [...$files, ...(glob(storage_path("logs/{$channel}-[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9].log")) ?: [])];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $entries = [];
        foreach ($files as $file) {
            foreach (array_reverse(self::parse($file, $min)) as $entry) {
                $entries[] = $entry;
                if (count($entries) >= $limit) {
                    return $entries;
                }
            }
        }

        return $entries;
    }

    /** @return list<array{at: string, level: string, message: string, trace: list<string>, file: string}> */
    private static function parse(string $file, int $min): array
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
            if (!preg_match('/^\[(?<at>[^\]]+)\]\s+\S+?\.(?<level>[A-Z]+):\s(?<rest>.*)$/s', $chunk, $match)
                || (self::LEVELS[strtolower($match['level'])] ?? 0) < $min) {
                continue; // filtered before it is scrubbed
            }
            $lines = preg_split('/\R/', (string) Scrub::text(trim($match['rest']))) ?: [];
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
