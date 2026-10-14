<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Invoice;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DATA-11: the database guarantees what the models assume (one coupon per code, one cancellation per service, one
 * snapshot per invoice, one global setting per key: the invoice counter among them). DATA-12: line descriptions as
 * long as WHMCS's.
 */
class UniqueKeysAndDescriptionsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_21_000000_unique_keys_and_long_line_descriptions.php');
    }

    private function refused(callable $insert): void
    {
        try {
            $insert();
            $this->fail('a duplicate was stored');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_global_setting_is_one_row_per_key(): void
    {
        DB::table('settings')->where('key', 'test_counter')->delete();
        DB::table('settings')->insert(['key' => 'test_counter', 'value' => '100', 'type' => 'string']);

        $this->refused(fn () => DB::table('settings')->insert(['key' => 'test_counter', 'value' => '7', 'type' => 'string']));
        // Scoped ones stay apart from the global one and from each other.
        DB::table('settings')->insert(['key' => 'test_counter', 'value' => '1', 'type' => 'string', 'settingable_type' => 'App\\Models\\Product', 'settingable_id' => 1]);
        DB::table('settings')->insert(['key' => 'test_counter', 'value' => '1', 'type' => 'string', 'settingable_type' => 'App\\Models\\Product', 'settingable_id' => 2]);
        $this->assertSame(3, DB::table('settings')->where('key', 'test_counter')->count());
    }

    public function test_a_coupon_code_is_unique(): void
    {
        DB::table('coupons')->insert(['code' => 'WELCOME', 'type' => 'percentage', 'value' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $this->refused(fn () => DB::table('coupons')->insert(['code' => 'WELCOME', 'type' => 'percentage', 'value' => 50, 'created_at' => now(), 'updated_at' => now()]));
    }

    public function test_the_migration_keeps_one_of_each_duplicate(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::table('settings')->whereIn('key', ['invoice_number', 'company_name'])->whereNull('settingable_type')->delete();
        foreach (['250', '7', '120'] as $value) { // a counter rewound by a duplicate row
            DB::table('settings')->insert(['key' => 'invoice_number', 'value' => $value, 'type' => 'string']);
        }
        DB::table('settings')->insert(['key' => 'company_name', 'value' => 'Old', 'type' => 'string']);
        DB::table('settings')->insert(['key' => 'company_name', 'value' => 'New', 'type' => 'string']);
        foreach ([10, 50] as $value) {
            DB::table('coupons')->insert(['code' => 'WELCOME', 'type' => 'percentage', 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration->up();

        $this->assertSame(['250'], DB::table('settings')->where('key', 'invoice_number')->whereNull('settingable_type')->pluck('value')->all(), 'the highest: a number given is never given again');
        $this->assertSame(['New'], DB::table('settings')->where('key', 'company_name')->pluck('value')->all(), 'the latest');
        $codes = DB::table('coupons')->orderBy('id')->pluck('code')->all();
        $this->assertSame('WELCOME', $codes[0], 'the oldest keeps its code');
        $this->assertStringStartsWith('WELCOME-', $codes[1]);
    }

    public function test_the_migration_rolls_back_and_forth_twice(): void
    {
        $migration = $this->migration();
        foreach ([1, 2] as $round) {
            $migration->down();
            $migration->up();
        }

        $this->assertTrue(Schema::hasColumn('settings', 'scope'));
    }

    public function test_a_long_multi_line_description_is_kept_whole(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $text = "Hosting Pro - example.test (14/10/2026 - 13/11/2026)\n" . str_repeat('Extra option: value; ', 40);

        $item = $invoice->items()->create(['description' => $text, 'price' => 10, 'quantity' => 1]);

        $this->assertGreaterThan(255, mb_strlen($text));
        $this->assertSame($text, $item->fresh()->description);
    }

    public function test_the_api_takes_a_description_longer_than_255(): void
    {
        DB::table('currencies')->insertOrIgnore(['code' => 'USD', 'name' => 'US Dollar', 'prefix' => '$', 'format' => '1,000.00']);
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['user_id' => $user->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'USD']);
        $token = 'PAYM' . bin2hex(random_bytes(8));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'permissions' => ['admin.invoice_items.create']]);

        $this->withToken($token)->postJson(route('api.v1.admin.invoice-items.store'), [
            'invoice_id' => $invoice->id, 'description' => str_repeat('x', 400), 'price' => 5, 'quantity' => 1,
        ])->assertSuccessful();
    }
}
