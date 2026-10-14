<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The staff split migration (docs/accounts.md, PR 1) on real data. Not in a transaction: the migration turns foreign
 * keys off, which SQLite ignores inside one, and with them on the table rebuilds delete by cascade. What it leaves is
 * committed, so the next test class migrates afresh.
 */
class StaffSplitMigrationTest extends TestCase
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

    public function test_the_migration_moves_admins_to_staff_with_what_pointed_at_them(): void
    {
        $migration = require database_path('migrations/2026_10_26_000000_split_staff_from_users.php');
        $migration->down();

        $role = Role::query()->create(['name' => 'Old admin', 'permissions' => ['*']]);
        $customer = User::factory()->create();
        $adminId = DB::table('users')->insertGetId([
            'first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'ada@example.test', 'password' => Hash::make('pw'),
            'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ticketId = DB::table('tickets')->insertGetId(['subject' => 'Help', 'user_id' => $customer->id, 'assigned_to' => $adminId,
            'status' => 'open', 'priority' => 'medium', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ticket_messages')->insert([
            ['ticket_id' => $ticketId, 'user_id' => $customer->id, 'message' => 'Q', 'created_at' => now(), 'updated_at' => now()],
            ['ticket_id' => $ticketId, 'user_id' => $adminId, 'message' => 'A', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('api_keys')->insert(['name' => 'k', 'token' => 'h', 'user_id' => $adminId, 'type' => 'admin', 'enabled' => true,
            'created_at' => now(), 'updated_at' => now()]);

        $migration->up();

        $staff = Staff::query()->findOrFail($adminId);
        $this->assertSame('ada@example.test', $staff->email);
        $this->assertSame($role->id, $staff->role_id);
        $this->assertNull(User::query()->find($adminId), 'the admin had no customer data: no user row left');
        $this->assertSame($adminId, (int) DB::table('tickets')->where('id', $ticketId)->value('assigned_to'));
        $this->assertSame($adminId, (int) DB::table('ticket_messages')->where('message', 'A')->value('staff_id'));
        $this->assertNull(DB::table('ticket_messages')->where('message', 'A')->value('user_id'));
        $this->assertSame($customer->id, (int) DB::table('ticket_messages')->where('message', 'Q')->value('user_id'));
        $this->assertSame($adminId, (int) DB::table('api_keys')->where('name', 'k')->value('staff_id'));
    }

    /** A former admin who was also a customer keeps both accounts; their own tickets stay the customer's (adversarial review). */
    public function test_the_migration_gives_an_admin_who_is_also_a_customer_two_accounts(): void
    {
        $migration = require database_path('migrations/2026_10_26_000000_split_staff_from_users.php');
        $migration->down();
        $role = Role::query()->create(['name' => 'Old admin', 'permissions' => ['*']]);
        $other = User::factory()->create();
        $id = DB::table('users')->insertGetId(['first_name' => 'Bo', 'last_name' => 'Both', 'email' => 'bo@example.test', 'password' => Hash::make('pw'), 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
        $own = DB::table('tickets')->insertGetId(['subject' => 'My own', 'user_id' => $id, 'status' => 'open', 'priority' => 'low', 'created_at' => now(), 'updated_at' => now()]);
        $theirs = DB::table('tickets')->insertGetId(['subject' => 'Theirs', 'user_id' => $other->id, 'status' => 'open', 'priority' => 'low', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ticket_messages')->insert([
            ['ticket_id' => $own, 'user_id' => $id, 'message' => 'as customer', 'created_at' => now(), 'updated_at' => now()],
            ['ticket_id' => $theirs, 'user_id' => $id, 'message' => 'as staff', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration->up();

        $this->assertNotNull(Staff::query()->find($id));
        $this->assertNotNull(User::query()->find($id));
        $this->assertSame($id, (int) DB::table('ticket_messages')->where('message', 'as customer')->value('user_id'));
        $this->assertNull(DB::table('ticket_messages')->where('message', 'as customer')->value('staff_id'));
        $this->assertSame($id, (int) DB::table('ticket_messages')->where('message', 'as staff')->value('staff_id'));
        $this->assertNull(DB::table('ticket_messages')->where('message', 'as staff')->value('user_id'));
    }

    /** Rolling back never makes an unrelated customer an admin (code review). */
    public function test_rolling_back_refuses_when_a_staff_id_is_another_customers(): void
    {
        $migration = require database_path('migrations/2026_10_26_000000_split_staff_from_users.php');
        $customer = User::factory()->create();
        $staff = Staff::factory()->admin()->create();
        DB::table('staff')->where('id', $staff->id)->update(['id' => $customer->id]);

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
