<?php

namespace App\Console\Commands;

use App\Services\Import\WhmcsKnowledgebaseImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use PDO;
use PDOException;

use function Laravel\Prompts\password;

/**
 * WHMCS's knowledgebase on its own (docs/knowledgebase.md): categories, articles, links, tags, translations and images,
 * same ids. Re-runnable; read-only on WHMCS. --base-url: what its relative image addresses are relative to (WHMCS's
 * SystemURL by default).
 */
class ImportWhmcsKnowledgebase extends Command
{
    protected $signature = 'app:import-whmcs-knowledgebase {dbname} {username=mycs} {host=localhost} {port=3306}
        {--base-url= : What relative image addresses are relative to (default: WHMCS\'s SystemURL)}
        {--no-images : Keep the images where they are}
        {--dry-run : The same numbers, nothing kept}';

    protected $description = 'Import the knowledgebase (categories, articles, tags, images) from a WHMCS database';

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
        Config::set('audit.console', false);
        $base = $this->option('base-url');

        return self::report($this, (new WhmcsKnowledgebaseImport($pdo, is_string($base) && $base !== '' ? $base : null, (bool) $this->option('dry-run'), !$this->option('no-images')))->run());
    }

    /** What the import did, and its parity; FAILURE when WHMCS and MyCS disagree. @param  array<string, mixed>  $counts */
    public static function report(Command $command, array $counts): int
    {
        $command->table(['Categories', 'Articles', 'Kept (edited here)', 'Deleted here', 'Id taken', 'Unpublished (private)', 'Translations', 'Links', 'Tags'],
            [[$counts['categories'], $counts['articles'], $counts['kept_edited'], $counts['deleted_here'], $counts['id_taken'], $counts['unpublished'],
                $counts['translations'], $counts['links'], $counts['tags']]]);
        $command->table(['Images', 'Images failed', 'Unknown languages', 'Hidden categories', 'Subcategories', 'Dynamic translations (not imported)'],
            [[$counts['images'], $counts['images_failed'], $counts['translations_unknown'], $counts['hidden_categories'], $counts['subcategories'], $counts['dynamic_translations']]]);
        foreach ($counts['errors'] as $error) {
            $command->error($error);
        }
        $parity = $counts['parity'];
        $keys = array_unique(array_merge(array_keys($parity['whmcs']), array_keys($parity['mycs'])));
        sort($keys);
        $command->table(['', 'WHMCS', 'MyCS', ''], array_map(fn ($key) => [$key, $parity['whmcs'][$key] ?? 0, $parity['mycs'][$key] ?? 0,
            ($parity['whmcs'][$key] ?? 0) === ($parity['mycs'][$key] ?? 0) ? 'ok' : 'DIFFERS'], $keys));
        if ($parity['whmcs'] !== $parity['mycs'] || $counts['errors'] !== [] || $counts['id_taken'] > 0) {
            $command->error('Parity: the knowledgebase differs from WHMCS\'s.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
