<?php

namespace MyCS\Cnic;

/**
 * A command to CentralNic Reseller's API: its form for the POST to api/call.cgi (`s_login`, `s_pw`, `s_command`). The
 * command is `KEY=value` lines; a list is KEY0, KEY1… (NAMESERVER0). A value never carries a line break (it would be
 * another key: the only injection there is), and an empty one is left out.
 */
final class Command
{
    public const LIVE = 'https://api.rrpproxy.net/api/call.cgi';

    public const OTE = 'https://api-ote.rrpproxy.net/api/call.cgi';

    /** A value to clear: sent as «KEY=» (an empty or null value is left out). */
    public const CLEAR = "\0clear";

    /**
     * @param  array<string, scalar|array<int, scalar|null>|null>  $command  COMMAND first, as the API expects it
     * @return array{s_login: string, s_pw: string, s_command: string}
     */
    public static function form(string $login, string $password, array $command): array
    {
        return ['s_login' => $login, 's_pw' => $password, 's_command' => self::lines($command)];
    }

    /** @param  array<string, scalar|array<int, scalar|null>|null>  $command */
    public static function lines(array $command): string
    {
        $lines = [];
        foreach ($command as $key => $value) {
            $key = strtoupper(self::clean((string) $key));
            if (is_array($value)) {
                foreach (array_values($value) as $i => $item) { // numbered from 0, whatever its keys
                    if ($item !== null && (string) $item !== '') {
                        $lines[] = $key . $i . '=' . self::clean((string) $item);
                    }
                }

                continue;
            }
            if ($value === self::CLEAR) {
                $lines[] = $key . '=';

                continue;
            }
            if ($value === null || (is_string($value) && $value === '')) {
                continue;
            }
            $lines[] = $key . '=' . self::clean(is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }

        return implode("\n", $lines);
    }

    private static function clean(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}
