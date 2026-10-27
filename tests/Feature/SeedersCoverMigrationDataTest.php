<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The seeders hold every row the migrations write (templates, ticket departments, settings), so the migrations can be
 * squashed into a schema dump without a new install losing them (ROADMAP, 2026-10-27). A new migration that writes rows
 * puts them in a seeder too, or this fails.
 */
class SeedersCoverMigrationDataTest extends TestCase
{
    use RefreshDatabase;

    /** Table => the columns compared (ids and timestamps differ by nature). */
    private const TABLES = [
        'notification_templates' => ['key', 'subject', 'enabled', 'body', 'cc', 'bcc', 'mail_enabled', 'in_app_enabled', 'in_app_title',
            'in_app_body', 'in_app_url', 'edit_preference_message', 'translations'],
        'ticket_departments' => ['name', 'sort', 'hidden', 'clients_only', 'email'],
        'settings' => ['key', 'value', 'type', 'settingable_type', 'settingable_id'],
    ];

    /** @return array<string, list<array<string, mixed>>> */
    private function rows(): array
    {
        $rows = [];
        foreach (self::TABLES as $table => $columns) {
            $rows[$table] = DB::table($table)->orderBy($columns[0])->get($columns)->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    public function test_the_seeders_alone_write_what_the_migrations_and_seeders_write(): void
    {
        $migratedAndSeeded = $this->rows();
        $this->assertNotEmpty($migratedAndSeeded['notification_templates']);
        foreach (array_keys(self::TABLES) as $table) {
            DB::table($table)->delete();
        }

        $this->seed(DatabaseSeeder::class);

        $this->assertEquals($migratedAndSeeded, $this->rows());
    }

    public function test_a_new_install_numbers_its_first_invoice_and_credit_note_one(): void
    {
        $this->assertSame(['0', '0'], [
            (string) DB::table('settings')->where('key', 'invoice_number')->value('value'),
            (string) DB::table('settings')->where('key', 'credit_note_number')->value('value'),
        ], 'a counter holds the last number given: none yet');
        foreach (['invoice_number', 'credit_note_number'] as $key) {
            DB::table('settings')->where('key', $key)->delete();
        }

        $this->seed(DatabaseSeeder::class);

        $this->assertSame('0', (string) DB::table('settings')->where('key', 'invoice_number')->value('value'), 'the seeder alone');
        $this->assertSame('0', (string) DB::table('settings')->where('key', 'credit_note_number')->value('value'));
    }
}
