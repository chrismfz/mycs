<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Staff;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin API's filters, sorts and includes (spatie/laravel-query-builder): only those allowed, an include only with
 * the permission of what it shows.
 */
class ApiQueryBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $permissions): string
    {
        $token = 'api-test-' . bin2hex(random_bytes(16));
        ApiKey::create(['staff_id' => Staff::factory()->admin()->create()->id, 'name' => 't', 'type' => 'admin', 'enabled' => true,
            'token' => hash('sha256', $token), 'permissions' => $permissions]);

        return $token;
    }

    public function test_a_filter_a_sort_and_an_include(): void
    {
        $open = Ticket::factory()->count(2)->create(['status' => 'open']);
        Ticket::factory()->create(['status' => 'closed']);
        $token = $this->token(['admin.tickets.viewAny', 'admin.tickets.view', 'admin.clients.view']);

        $response = $this->withToken($token)->getJson(route('api.v1.admin.tickets.index') . '?filter[status]=open&sort=-id&include=client')->assertOk();

        $this->assertSame($open->pluck('id')->sortDesc()->map(fn (int $id) => (string) $id)->values()->all(), collect($response->json('data'))->pluck('id')->all());
        $this->assertNotEmpty($response->json('data.0.relationships.client') ?? $response->json('included'));
    }

    public function test_what_is_not_allowed_is_refused(): void
    {
        Ticket::factory()->create();
        $token = $this->token(['admin.tickets.viewAny', 'admin.tickets.view']);

        $this->withToken($token)->getJson(route('api.v1.admin.tickets.index') . '?filter[subject]=x')->assertStatus(400);
        $this->withToken($token)->getJson(route('api.v1.admin.tickets.index') . '?sort=subject')->assertStatus(400);
        // client needs admin.clients.view
        $this->withToken($token)->getJson(route('api.v1.admin.tickets.index') . '?include=client')->assertStatus(400);
    }
}
