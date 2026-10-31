<?php

namespace App\Console\Commands;

use App\Services\Import\WhmcsReplayCompare as Compare;
use Closure;
use Illuminate\Console\Command;
use PDO;
use PDOException;

use function Laravel\Prompts\password;

/**
 * What `bin/simulate` said the cron would do, against what WHMCS did over the same stretch (ROADMAP 9β, 3; docs/importer.md
 * «Simulation»): the ledger of the simulation, a copy of the WHMCS database from before the stretch, and one from after.
 * Read only. Exits 1 while anything differs (what staff did by hand in WHMCS is in «only WHMCS»: the reader tells it), 2 when
 * the comparison could not be made.
 */
class WhmcsReplayCompare extends Command
{
    protected $signature = 'mycs:replay-compare {before : the WHMCS database copied before the stretch} {after : the WHMCS database copied after it}
        {--ledger= : the CSV `bin/simulate --out=` wrote}
        {--source-user= : the WHMCS databases\' user}
        {--source-host= : their host}
        {--source-port=3306 : their port}
        {--source-socket= : their unix socket, instead of host and port}
        {--examples=10 : how many items of each difference to list}';

    protected $description = 'Compare a simulation\'s ledger with what WHMCS really did between two copies of its database';

    /** @var (Closure(string, string, string): PDO)|null how a source is opened (tests: a fixture database) */
    public static ?Closure $connect = null;

    public function handle(): int
    {
        $username = (string) $this->option('source-user');
        $host = (string) $this->option('source-host');
        $socket = (string) $this->option('source-socket');
        $ledger = (string) $this->option('ledger');
        if ($username === '' || ($host === '' && $socket === '') || !is_readable($ledger)) {
            $this->error('Needed: --ledger=<the CSV of bin/simulate>, --source-user and --source-host or --source-socket.');

            return 2;
        }
        $password = (string) (getenv('WHMCS_SOURCE_PASSWORD') ?: password("Enter the password for user '$username':", required: true));
        if ((string) $this->argument('before') === (string) $this->argument('after')) {
            $this->error('«before» and «after» are the same database: there is nothing to compare.');

            return 2;
        }
        $open = function (string $dbname) use ($username, $password, $host, $socket): PDO {
            $dsn = $socket !== '' ? "mysql:unix_socket=$socket;dbname=$dbname;charset=utf8mb4" : "mysql:host=$host;port=" . (int) $this->option('source-port') . ";dbname=$dbname;charset=utf8mb4";
            $pdo = self::$connect !== null ? (self::$connect)($dsn, $username, $password) : new PDO($dsn, $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->query('SELECT 1 FROM tblinvoices LIMIT 1');

            return $pdo;
        };
        try {
            $rows = array_map(fn (string $line) => str_getcsv(rtrim($line, "\r"), escape: ''), file($ledger, FILE_IGNORE_NEW_LINES) ?: []);
            $head = array_shift($rows) ?: [];
            if ($head !== ['day', 'event', 'ref', 'detail']) {
                $this->error('The ledger is not the CSV of bin/simulate --out= (day, event, ref, detail).');

                return 2;
            }
            $lines = array_map(fn (array $row) => array_combine($head, array_slice(array_pad($row, count($head), ''), 0, count($head))), $rows);
            $compare = new Compare($open((string) $this->argument('before')), $open((string) $this->argument('after')), $lines);
            $result = $compare->run();
        } catch (PDOException $e) {
            $this->error('Not a WHMCS database that can be read: SQLSTATE ' . $e->getCode() . '.');

            return 2;
        }
        $days = count(array_unique(array_filter(array_column($lines, 'day'))));
        if ($days > 1) {
            $this->line("The ledger spans {$days} days: the services', invoices' and domains' changes are compared net, as the two copies can show only that.");
        }
        foreach ($compare->notes() as $note) {
            $this->warn($note);
        }
        $this->table(['', 'WHMCS', 'Simulation', 'Both', 'Only WHMCS', 'Only simulation'], array_map(
            fn (string $name, array $g) => [$name, $g['whmcs'], $g['mycs'], $g['both'], count($g['only_whmcs']) ?: '', count($g['only_mycs']) ?: ''],
            array_keys($result), $result));
        $differ = false;
        foreach ($result as $name => $g) {
            foreach (['only_whmcs' => 'Only WHMCS did', 'only_mycs' => 'Only the simulation did'] as $side => $title) {
                if ($g[$side] === []) {
                    continue;
                }
                $differ = true;
                $this->line("<options=bold>{$name}: {$title}</> (" . count($g[$side]) . ')');
                foreach (array_slice($g[$side], 0, max(1, (int) $this->option('examples'))) as $item) {
                    $this->line("  {$item}");
                }
            }
        }
        if (array_sum(array_map(fn (array $g) => $g['whmcs'] + $g['mycs'], $result)) === 0) {
            $this->warn('Nothing happened on either side: if the stretch was not quiet, the copies are the same moment or the ledger is not its.');
        }
        if (!$differ) {
            $this->info('The simulation did what WHMCS did: the same invoices, payments, service changes and mails.');

            return self::SUCCESS;
        }
        $this->warn('Differences: each to be explained (done by hand in WHMCS, or a rule that differs) or fixed.');

        return self::FAILURE;
    }
}
