<?php

namespace App\Console\Commands;

use App\Services\Import\WhmcsForecastParity as Forecast;
use Closure;
use Illuminate\Console\Command;
use PDO;
use PDOException;

use function Laravel\Prompts\password;

/**
 * What each live service and domain will do, in WHMCS and here (ROADMAP 9β, 1; docs/importer.md «Πρόβλεψη»): the cycle, the
 * renewal amount, the due date, the day it is invoiced, whether a saved method charges it, the day it is suspended and
 * terminated, a hold. Read only on both. Exits 1 when anything differs: a cut-over goes ahead with each difference
 * explained and written down, or fixed.
 */
class WhmcsForecastParity extends Command
{
    protected $signature = 'mycs:forecast-parity {dbname : the WHMCS database (a copy)}
        {--source-user= : its user}
        {--source-host= : its host}
        {--source-port=3306 : its port}
        {--source-socket= : its unix socket, instead of host and port}
        {--examples=5 : how many differing items to show for each field}
        {--csv= : write every difference (kind, id, field, WHMCS, here) to this file}';

    protected $description = 'Compare what WHMCS and this instance will do with each live service and domain (cycle, amount, dates, charging, suspension)';

    /** @var (Closure(string, string, string): PDO)|null how the source is opened (tests: a fixture database) */
    public static ?Closure $connect = null;

    public function handle(): int
    {
        $username = (string) $this->option('source-user');
        $host = (string) $this->option('source-host');
        $socket = (string) $this->option('source-socket');
        if ($username === '' || ($host === '' && $socket === '')) {
            $this->error('The WHMCS database\'s user and host (or socket) are needed: --source-user and --source-host or --source-socket.');

            return self::FAILURE;
        }
        $password = (string) (getenv('WHMCS_SOURCE_PASSWORD') ?: password("Enter the password for user '$username':", required: true));
        $dbname = (string) $this->argument('dbname');
        $dsn = $socket !== '' ? "mysql:unix_socket=$socket;dbname=$dbname;charset=utf8mb4"
            : "mysql:host=$host;port={$this->option('source-port')};dbname=$dbname;charset=utf8mb4";
        try {
            $pdo = self::$connect !== null ? (self::$connect)($dsn, $username, $password) : new PDO($dsn, $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->query('SELECT 1 FROM tblhosting LIMIT 1');
            $result = (new Forecast($pdo))->run();
        } catch (PDOException $e) {
            $this->error('Not a WHMCS database that can be read: SQLSTATE ' . $e->getCode() . '.');

            return self::FAILURE;
        }
        $rows = [];
        foreach ($result['summary'] as $key => $count) {
            $rows[] = [$key, $count['total'], $count['total'] - $count['differ'], $count['differ'] ?: ''];
        }
        $this->table(['', 'Compared', 'Same', 'Differ'], $rows);
        $byField = [];
        foreach ($result['differences'] as $difference) {
            $byField["{$difference['kind']} · {$difference['field']}"][] = $difference;
        }
        foreach ($byField as $key => $list) {
            $this->line("<options=bold>{$key}</>: " . count($list) . ' differ');
            $this->table(['#', 'WHMCS', 'Here'], array_map(fn (array $d) => [$d['id'], $d['whmcs'] ?: '(none)', $d['mycs'] ?: '(none)'], array_slice($list, 0, max(1, (int) $this->option('examples')))));
            // Where they fall: the same difference for all is a rule, not a service.
            $groups = [];
            foreach ($list as $d) {
                $groups["{$d['whmcs']} → {$d['mycs']}"] = ($groups["{$d['whmcs']} → {$d['mycs']}"] ?? 0) + 1;
            }
            if (count($groups) <= 6 && count($list) > 1) {
                arsort($groups);
                $this->line('  ' . implode(' · ', array_map(fn ($k, $n) => "{$n}× {$k}", array_keys($groups), $groups)));
            }
        }
        if (($path = (string) $this->option('csv')) !== '') {
            $handle = fopen($path, 'w');
            fputcsv($handle, ['kind', 'id', 'field', 'whmcs', 'mycs']);
            foreach ($result['differences'] as $d) {
                fputcsv($handle, [$d['kind'], $d['id'], $d['field'], $d['whmcs'], $d['mycs']]);
            }
            fclose($handle);
            $this->line("Every difference written to {$path}.");
        }
        if ($result['differences'] === []) {
            $this->info('Every compared item will do the same here as in WHMCS.');

            return self::SUCCESS;
        }
        $this->warn(count($result['differences']) . ' difference(s): each to be fixed, or explained and written down before the cut-over.');

        return self::FAILURE;
    }
}
