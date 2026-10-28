<?php

namespace App\Console\Commands;

use App\Services\Proxmox\WhmcsIpPoolImport;
use Illuminate\Console\Command;
use PDO;
use PDOException;

use function Laravel\Prompts\password;

/**
 * The VPS IP pools from WHMCS's Proxmox module (docs/proxmox.md, A5), over the services app:import-from-whmcs brought
 * (same ids). Re-runnable; read-only on WHMCS.
 */
class ImportWhmcsIpPools extends Command
{
    protected $signature = 'app:import-whmcs-ip-pools {dbname} {username=mycs} {host=localhost} {port=3306}';

    protected $description = 'Import the Proxmox module\'s IP pools (mod_pvewhmcs_ip_*) and which VPS holds which from a WHMCS database';

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

        $counts = (new WhmcsIpPoolImport($pdo))->run();
        $this->table(['Pools', 'Addresses', 'Given to their service', 'Service not imported yet'],
            [[$counts['pools'], $counts['addresses'], $counts['given'], $counts['without_service']]]);
        foreach ($counts['outside'] as $line) {
            $this->warn("Not in any pool: {$line}");
        }
        foreach ($counts['conflicts'] as $line) {
            $this->warn($line);
        }

        return self::SUCCESS;
    }
}
