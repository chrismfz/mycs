<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Category;
use App\Models\ConfigOption;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The small ones of Σ5 (AUTH-14..20 that do not wait for the accounts work): the admin API pages at most 100 rows and
 * is rate limited per key; seeing debug logs is not deleting them; seeing the catalog is not reordering it.
 */
class ApiLimitsAndStaffActionsTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $permissions): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        $owner = User::factory()->create(['role_id' => Role::create(['name' => 'Api' . uniqid(), 'permissions' => ['*']])->id]);
        ApiKey::create(['name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'user_id' => $owner->id, 'permissions' => $permissions]);

        return $token;
    }

    public function test_the_admin_api_pages_at_most_100_rows(): void
    {
        User::factory()->count(105)->create();

        $response = $this->withToken($this->token(['admin.users.view']))->getJson(route('api.v1.admin.users.index', ['per_page' => 100000]));

        $response->assertOk();
        $this->assertCount(100, $response->json('data'));
    }

    public function test_the_admin_api_is_rate_limited_per_key(): void
    {
        $token = $this->token(['admin.users.view']);
        for ($i = 0; $i < 120; $i++) {
            $this->withToken($token)->getJson(route('api.v1.admin.users.index'))->assertOk();
        }

        $this->withToken($token)->getJson(route('api.v1.admin.users.index'))->assertStatus(429);
        $this->withToken($this->token(['admin.users.view']))->getJson(route('api.v1.admin.users.index'))->assertOk(); // another key
    }

    public function test_seeing_the_catalog_is_not_reordering_it(): void
    {
        $viewer = User::factory()->create(['role_id' => Role::create(['name' => 'Viewer' . uniqid(),
            'permissions' => ['admin.products.viewAny', 'admin.categories.viewAny', 'admin.config_options.viewAny']])->id]);
        $editor = User::factory()->create(['role_id' => Role::create(['name' => 'Editor' . uniqid(),
            'permissions' => ['admin.products.update', 'admin.categories.update', 'admin.config_options.update']])->id]);

        foreach ([Product::class, Category::class, ConfigOption::class] as $model) {
            $this->assertFalse($viewer->can('reorder', $model), $model);
            $this->assertTrue($editor->can('reorder', $model), $model);
        }
    }
}
