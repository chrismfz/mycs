<?php

namespace App\Console\Commands;

use App\Services\Whmcs\AdminImport;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * A WHMCS installation's admins as staff, into a live MyCS (docs/importer.md «Admins»). Reads a JSON file of
 * tbladmins rows with their role's name (`rolename`): the hashes travel encrypted to this host and the file is deleted
 * by whoever made it. Never prints a hash. `--dry-run` reports, writes nothing.
 */
class ImportWhmcsAdmins extends Command
{
    protected $signature = 'app:import-whmcs-admins {file : JSON list of tbladmins rows with rolename}
        {--dry-run : report the counts, write nothing}';

    protected $description = 'Import the admins (staff) of a WHMCS installation, matched by email';

    public function handle(): int
    {
        Config::set('audit.console', true); // its changes are audited
        $file = (string) $this->argument('file');
        $admins = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($admins) || !array_is_list($admins)) {
            $this->error('Not a JSON list of admins: ' . $file);

            return self::FAILURE;
        }

        try {
            $result = (new AdminImport)->run($admins, (bool) $this->option('dry-run'));
        } catch (QueryException $e) {
            // Its message carries the SQL with its bindings, a hash among them (review): the error code only.
            $this->error('Nothing imported: database error ' . $e->getCode() . '.');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Nothing imported: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->table(array_keys($result['counts']), [array_values($result['counts'])]);
        foreach ($result['notes'] as $note) {
            $this->warn($note);
        }
        $this->info($this->option('dry-run') ? 'Dry run: nothing written.' : 'Imported.');

        return self::SUCCESS;
    }
}
