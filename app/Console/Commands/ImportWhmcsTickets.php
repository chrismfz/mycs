<?php

namespace App\Console\Commands;

use App\Services\Import\WhmcsTicketImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use PDO;
use PDOException;

use function Laravel\Prompts\password;

/**
 * WHMCS's tickets on their own (docs/tickets.md §5α, PR Δ), over the accounts app:import-from-whmcs brought (same ids).
 * Re-runnable; read-only on WHMCS; no mail, no staff notice. --attachments: copies of WHMCS's folders with ticket files,
 * as many as it has used (Storage Settings: myip has attachments/ and its storage folder, WG-21).
 */
class ImportWhmcsTickets extends Command
{
    protected $signature = 'app:import-whmcs-tickets {dbname} {username=mycs} {host=localhost} {port=3306}
        {--attachments=* : A copy of a WHMCS folder with ticket files, once per folder (its files are copied under the ticket file rules)}
        {--dry-run : The same numbers, nothing kept}';

    protected $description = 'Import the support tickets (departments, tickets, replies, notes, files) from a WHMCS database';

    public function handle(): int
    {
        $attachments = array_values(array_filter((array) $this->option('attachments'), fn ($folder) => is_string($folder) && $folder !== ''));
        foreach ($attachments as $folder) {
            if (!is_dir($folder)) {
                $this->error("No folder {$folder}.");

                return self::FAILURE;
            }
        }
        $password = password("Enter the password for user '{$this->argument('username')}':", required: true);
        try {
            $pdo = new PDO("mysql:host={$this->argument('host')};port={$this->argument('port')};dbname={$this->argument('dbname')};charset=utf8mb4", $this->argument('username'), $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        } catch (PDOException $e) {
            $this->error('Connection failed: ' . $e->getMessage());

            return self::FAILURE;
        }
        Config::set('audit.console', false);

        return self::report($this, (new WhmcsTicketImport($pdo, $attachments, (bool) $this->option('dry-run')))->run());
    }

    /** What the import did, and its parity; FAILURE when WHMCS and MyCS disagree. @param  array<string, mixed>  $counts */
    public static function report(Command $command, array $counts): int
    {
        $command->table(['Departments', 'Tickets', 'Unchanged', 'Guests', 'Clients not imported', 'Messages', 'Notes', 'Merged', 'Deleted here', 'Predefined replies', 'CC'],
            [[$counts['departments'], $counts['tickets'], $counts['unchanged'], $counts['guests'], $counts['no_account'], $counts['messages'], $counts['notes'],
                $counts['merged'], $counts['deleted_here'], $counts['canned_replies'] ?? 0, $counts['ccs'] ?? 0]]);
        $command->table(['Files', 'Missing', 'Refused', 'Removed in WHMCS', 'Texts cut', 'Number taken', 'Failed'],
            [[$counts['files'], $counts['files_missing'], $counts['files_refused'], $counts['files_removed'], $counts['truncated'], $counts['reference_taken'], $counts['failed']]]);
        // Where WHMCS keeps them now: the folder to copy, with any it used before (WG-21: a missing file is most likely there).
        if (is_string($counts['whmcs_storage'] ?? null)) {
            $command->line("WHMCS keeps ticket files in «{$counts['whmcs_storage']}» (Storage Settings).");
            if ($counts['files_missing'] > 0) {
                $command->warn("{$counts['files_missing']} file(s) missing: copy that folder (and attachments/, where it kept them before a Storage change) and give each with --attachments.");
            }
        }
        foreach ($counts['errors'] as $error) {
            $command->error($error);
        }
        foreach ($counts['other_status'] as $status => $n) {
            $command->warn("{$n} with WHMCS status «{$status}»: imported as open.");
        }
        $parity = $counts['parity'] ?? ['whmcs' => [], 'mycs' => []];
        $keys = array_unique(array_merge(array_keys($parity['whmcs']), array_keys($parity['mycs'])));
        sort($keys);
        $rows = array_map(fn ($key) => [$key, $parity['whmcs'][$key] ?? 0, $parity['mycs'][$key] ?? 0, ($parity['whmcs'][$key] ?? 0) === ($parity['mycs'][$key] ?? 0) ? 'ok' : 'DIFFERS'], $keys);
        $command->table(['Department/status', 'WHMCS', 'MyCS', ''], $rows);
        if ($parity['whmcs'] !== $parity['mycs'] || $counts['failed'] > 0) {
            $command->error('Parity: the tickets per department and status differ.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
