<?php

namespace App\Console\Commands;

use App\Services\Whmcs\CatalogImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use PDO;
use PDOException;
use Throwable;

use function Laravel\Prompts\password;

/**
 * A WHMCS installation's catalog, copied into MyCS (docs/importer.md «Catalog»): currencies, groups, products and
 * prices, config options, servers and groups (without passwords), the licensing products' settings, TLDs and their
 * prices. No customer data. Re-runnable, never destructive; `--dry-run` reports what it would do and writes nothing.
 * Read a copy of WHMCS, never production.
 */
class ImportWhmcsCatalog extends Command
{
    protected $signature = 'app:import-whmcs-catalog {dbname} {username=mycs} {host=localhost} {port=3306}
        {--dry-run : report the counts, write nothing}
        {--keep-default-currency : do not make WHMCS\'s default currency MyCS\'s}
        {--registrar-connection= : the MyCS registrar connection id for TLDs WHMCS registers automatically at its registrar}';

    protected $description = 'Import the catalog (no customers) from a WHMCS database copy';

    public function handle(): int
    {
        Config::set('audit.console', true); // its changes are audited
        $password = password("Enter the password for user '{$this->argument('username')}':", required: true);
        try {
            $pdo = new PDO("mysql:host={$this->argument('host')};port={$this->argument('port')};dbname={$this->argument('dbname')}", $this->argument('username'), $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        } catch (PDOException $e) {
            $this->error('Connection failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $connection = $this->option('registrar-connection');
        try {
            $result = (new CatalogImport($pdo, !$this->option('keep-default-currency'), $connection !== null ? (int) $connection : null))
                ->run((bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            $this->error('Nothing imported: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(array_keys($result['counts']), [array_values($result['counts'])]);
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
        $this->info($this->option('dry-run') ? 'Dry run: nothing written.' : 'Imported. Server passwords and API tokens: enter them under Servers.');

        return self::SUCCESS;
    }
}
