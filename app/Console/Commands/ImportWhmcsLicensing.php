<?php

namespace App\Console\Commands;

use App\Services\Licensing\WhmcsLicensingImport;
use Illuminate\Console\Command;
use PDO;
use PDOException;

use function Laravel\Prompts\password;

/**
 * nixpal's licenses from WHMCS on their own (L3, docs/licensing.md), over products and services app:import-from-whmcs
 * brought (same ids). Re-runnable; read-only on WHMCS. The PSKs go encrypted into the products' settings, never to the
 * output.
 */
class ImportWhmcsLicensing extends Command
{
    protected $signature = 'app:import-whmcs-licensing {dbname} {username=mycs} {host=localhost} {port=3306} {--log-days=90 : Days of mod_licensinglog to bring (0: none)}';

    protected $description = 'Import the Licensing Addon licenses (mod_licensing) from a WHMCS database';

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

        $counts = (new WhmcsLicensingImport($pdo))->run(max(0, (int) $this->option('log-days')));
        $this->table(['Products', 'Licenses', 'Without an imported service', 'Custom field values', 'Log rows'],
            [[$counts['products'], $counts['licenses'], $counts['skipped'], $counts['fields'], $counts['logs']]]);

        return self::SUCCESS;
    }
}
