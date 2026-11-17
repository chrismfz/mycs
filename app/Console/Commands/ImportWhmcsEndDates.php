<?php

namespace App\Console\Commands;

use App\Services\Import\WhmcsEndDates;
use Illuminate\Console\Command;
use PDO;
use PDOException;

use function Laravel\Prompts\password;

/**
 * When the services that ended in WHMCS ended (docs/growth-churn.md), over an import made before the importer brought it
 * (nixpal, 2026-11-13). Fills only an ended service whose end is not known; re-runnable; read-only on WHMCS.
 */
class ImportWhmcsEndDates extends Command
{
    protected $signature = 'app:import-whmcs-end-dates {dbname} {username=mycs} {host=localhost} {port=3306}';

    protected $description = 'Fill when the services ended in WHMCS ended (tblhosting / tblhostingaddons termination_date) from a WHMCS database';

    public function handle(): int
    {
        $password = password("Enter the password for user '{$this->argument('username')}':", required: true);
        try {
            $pdo = new PDO("mysql:host={$this->argument('host')};port={$this->argument('port')};dbname={$this->argument('dbname')};charset=utf8mb4", $this->argument('username'), $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        } catch (PDOException $e) {
            $this->error('Connection failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $c = (new WhmcsEndDates($pdo))->run();
        $this->table(['Services', 'Addons', 'Addons from their parent', 'Ended, not known when'],
            [[$c['services'], $c['addons'], $c['from_parent'], $c['unknown']]]);

        return self::SUCCESS;
    }
}
