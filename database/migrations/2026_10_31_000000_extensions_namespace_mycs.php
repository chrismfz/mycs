<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The extensions' namespace is MyCS\Extensions (2026-10-14, was the fork base's). Class names stored in the database
 * (polymorphic types, custom fields' models) follow, so the extensions' audits, properties and the rest still find
 * their class. Queued jobs carry class names in their serialized payload, which a REPLACE would break (the length
 * prefix): drain the queue on the old code (`php artisan queue:work --stop-when-empty`) before deploying this.
 */
return new class extends Migration
{
    /** Table => columns holding a class name. */
    private const COLUMNS = [
        'audits' => ['auditable_type', 'user_type'],
        'audit_logs' => ['model_type'],
        'properties' => ['model_type'],
        'custom_properties' => ['model'],
        'settings' => ['settingable_type'],
        'service_configs' => ['configurable_type'],
        'plans' => ['priceable_type'],
        'money_events' => ['actor_type'],
        'invoice_items' => ['reference_type'],
    ];

    public function up(): void
    {
        $this->rename('Paymenter\\Extensions\\', 'MyCS\\Extensions\\');
    }

    public function down(): void
    {
        $this->rename('MyCS\\Extensions\\', 'Paymenter\\Extensions\\');
    }

    private function rename(string $from, string $to): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    continue;
                }
                // REPLACE on every row (a no-op where the name is not there): LIKE treats a backslash differently in
                // MariaDB and SQLite.
                DB::table($table)->whereNotNull($column)
                    ->update([$column => DB::raw('REPLACE(' . DB::getQueryGrammar()->wrap($column) . ', ' . DB::getPdo()->quote($from) . ', ' . DB::getPdo()->quote($to) . ')')]);
            }
        }
    }
};
