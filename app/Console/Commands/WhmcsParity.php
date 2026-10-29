<?php

namespace App\Console\Commands;

use App\Services\Import\WhmcsParity as Parity;
use Closure;
use Illuminate\Console\Command;
use PDO;
use PDOException;

use function Laravel\Prompts\password;

/**
 * Parity after an import (docs/importer.md, «Parity»): each number from WHMCS's copy and from this instance, side by side,
 * with what differs. Read only on both. Exits 1 when anything differs: a cut-over goes ahead only with no difference, or
 * each one explained and written down.
 */
class WhmcsParity extends Command
{
    protected $signature = 'mycs:parity {dbname : the WHMCS database (a copy)}
        {--source-user= : its user}
        {--source-host= : its host}
        {--source-port=3306 : its port}
        {--source-socket= : its unix socket, instead of host and port}
        {--only-differences : print only the lines that differ}';

    protected $description = 'Compare an imported WHMCS database (a copy) with this instance, number by number';

    /** @var (Closure(string, string, string): PDO)|null how the source is opened (tests: a fixture database) */
    public static ?Closure $connect = null;

    public function handle(): int
    {
        $dbname = (string) $this->argument('dbname');
        $username = (string) $this->option('source-user');
        $host = (string) $this->option('source-host');
        $socket = (string) $this->option('source-socket');
        if ($username === '' || ($host === '' && $socket === '')) {
            $this->error('The WHMCS database\'s user and host (or socket) are needed: --source-user and --source-host or --source-socket.');

            return self::FAILURE;
        }
        // From the shell for a run without a terminal (never the .env, never an argument), else asked.
        $password = (string) (getenv('WHMCS_SOURCE_PASSWORD') ?: password("Enter the password for user '$username':", required: true));
        $dsn = $socket !== '' ? "mysql:unix_socket=$socket;dbname=$dbname;charset=utf8mb4"
            : "mysql:host=$host;port={$this->option('source-port')};dbname=$dbname;charset=utf8mb4";
        try {
            $pdo = self::$connect !== null ? (self::$connect)($dsn, $username, $password) : new PDO($dsn, $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            foreach (['tblclients', 'tblinvoices', 'tblaccounts'] as $table) {
                $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            }
        } catch (PDOException $e) {
            $this->error('Not a WHMCS database that can be read: SQLSTATE ' . $e->getCode() . '.');

            return self::FAILURE;
        }

        try {
            $parity = new Parity($pdo);
            $rows = $parity->rows();
            $notes = $parity->notes();
        } catch (PDOException $e) {
            // The SQL state only: a query's message carries its values.
            $this->error('The comparison stopped (SQLSTATE ' . $e->getCode() . '): a WHMCS table it reads may be missing.');

            return self::FAILURE;
        }
        $differ = array_values(array_filter($rows, fn (array $row) => !$row['same']));
        $shown = $this->option('only-differences') ? $differ : $rows;
        $this->table(['', 'WHMCS', 'MyCS', 'Difference'], array_map(fn (array $row) => [
            $row['metric'],
            self::show($row['metric'], $row['whmcs']),
            self::show($row['metric'], $row['mycs']),
            $row['same'] ? '' : self::show($row['metric'], (int) $row['mycs'] - (int) $row['whmcs'], true),
        ], $shown));
        foreach ($notes as $note) {
            $this->warn($note);
        }
        if ($differ === []) {
            $this->info('No difference.');

            return self::SUCCESS;
        }
        $this->warn(count($differ) . ' line(s) differ: each to be fixed, or explained and written down before the cut-over.');

        return self::FAILURE;
    }

    /** An amount (cents) as money, a count as it is. */
    private static function show(string $metric, int|string $value, bool $signed = false): string
    {
        $money = str_contains($metric, 'amount') || str_contains($metric, 'Money') || str_contains($metric, 'fees')
            || str_contains($metric, 'Credit applied') || $metric === 'Credit balance';
        $text = $money ? number_format(((int) $value) / 100, 2, '.', '') : (string) $value;

        return $signed && (int) $value > 0 ? '+' . $text : $text;
    }
}
