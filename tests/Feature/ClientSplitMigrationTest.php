<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The clients split migration (docs/accounts.md, PR 2a) on real data. Not in a transaction, as the staff split's test:
 * the migration turns foreign keys off, which SQLite ignores inside one. What it leaves is committed, so the next test
 * class migrates afresh.
 */
class ClientSplitMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_the_migration_makes_each_user_an_account_with_the_same_id(): void
    {
        $migration = require database_path('migrations/2026_10_28_000000_split_clients_from_users.php');
        $migration->down();

        $userId = DB::table('users')->insertGetId(['first_name' => 'Old', 'last_name' => 'Customer', 'email' => 'old@example.test',
            'password' => 'x', 'credit_auto_apply' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('credits')->insert(['user_id' => $userId, 'currency_code' => 'USD', 'amount' => 5, 'created_at' => now(), 'updated_at' => now()]);
        $roleId = DB::table('roles')->insertGetId(['name' => 'Support', 'permissions' => json_encode(['admin.users.viewAny', 'admin.tickets.viewAny'])]);

        $migration->up();

        $client = Client::query()->findOrFail($userId);
        $this->assertFalse($client->credit_auto_apply);
        $this->assertTrue($client->owner()->is(User::query()->findOrFail($userId)));
        $this->assertSame($userId, (int) DB::table('credits')->where('currency_code', 'USD')->value('client_id'));
        $this->assertContains('admin.clients.viewAny', json_decode((string) DB::table('roles')->where('id', $roleId)->value('permissions'), true));
    }

    /** Rolling back gives each account back to its owner: not when a user owns two (they would merge into one). */
    public function test_rolling_back_refuses_a_user_who_owns_two_accounts(): void
    {
        $migration = require database_path('migrations/2026_10_28_000000_split_clients_from_users.php');
        $user = User::factory()->create();
        Client::openFor($user);

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    /** Otherwise each account goes back to its owner, whatever its id. */
    public function test_rolling_back_maps_each_account_to_its_owner(): void
    {
        $migration = require database_path('migrations/2026_10_28_000000_split_clients_from_users.php');
        $user = User::factory()->create();
        $clientId = $user->client()->id;
        DB::table('credits')->insert(['client_id' => $clientId, 'currency_code' => 'USD', 'amount' => 3, 'created_at' => now(), 'updated_at' => now()]);

        $migration->down();

        $this->assertNotSame($clientId, $user->id);
        $this->assertSame($user->id, (int) DB::table('credits')->where('currency_code', 'USD')->value('user_id'));
    }
}
