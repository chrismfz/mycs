<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The seeders hold every row the migrations write, in every table, so the migrations can be squashed into a schema dump
 * without a new install losing them (ROADMAP, 2026-10-27). A new migration that writes rows puts them in a seeder too, or
 * this fails.
 */
class SeedersCoverMigrationDataTest extends TestCase
{
    use RefreshDatabase;

    /** Every table's rows but the migrations' own, without ids and timestamps (they differ by nature), in a stable order. */
    private function rows(): array
    {
        $rows = [];
        // This database's tables only: on MariaDB getTables() lists every database the user sees (the parallel tests' too).
        foreach (Schema::getTables(Schema::getCurrentSchemaName()) as $table) {
            $name = $table['name'];
            if ($name === 'migrations' || str_starts_with($name, 'sqlite_')) {
                continue;
            }
            $columns = array_values(array_diff(Schema::getColumnListing($name), ['id', 'created_at', 'updated_at']));
            $list = DB::table($name)->get($columns)->map(fn ($row) => json_encode((array) $row))->all();
            sort($list);
            if ($list !== []) {
                $rows[$name] = $list;
            }
        }
        ksort($rows);

        return $rows;
    }

    public function test_the_seeders_alone_write_what_the_migrations_and_seeders_write(): void
    {
        $migratedAndSeeded = $this->rows();
        $this->assertArrayHasKey('notification_templates', $migratedAndSeeded);
        Schema::withoutForeignKeyConstraints(function () use ($migratedAndSeeded) {
            foreach (array_keys($migratedAndSeeded) as $table) {
                DB::table($table)->delete();
            }
        });

        $this->seed(DatabaseSeeder::class);

        $this->assertEquals($migratedAndSeeded, $this->rows());
    }

    public function test_a_new_install_starts_its_number_counters_at_zero(): void
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

    public function test_an_install_seeded_with_the_old_default_numbers_its_first_note_one(): void
    {
        DB::table('settings')->where('key', 'credit_note_number')->update(['value' => '1']);

        (require database_path('migrations/2027_01_15_000000_credit_note_counter_from_zero.php'))->up();

        $this->assertSame('0', (string) DB::table('settings')->where('key', 'credit_note_number')->value('value'));
    }
}
