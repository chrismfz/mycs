<?php

namespace Tests\Feature;

use App\Admin\Resources\ErrorLogResource\Pages\ListErrorLogs;
use App\Models\ApiKey;
use App\Models\DebugLog;
use App\Models\Role;
use App\Models\User;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of PR #90 (AUTH-17 throttle, per_page clamp, debug-log delete). */
class ApiLimitsReviewTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $permissions): string
    {
        $token = 'PAYM' . bin2hex(random_bytes(8));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Api' . uniqid(), 'permissions' => ['*']])->id]);
        ApiKey::create(['name' => 'test', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token), 'user_id' => $owner->id, 'permissions' => $permissions]);

        return $token;
    }

    /**
     * R1: the limiter keys by the hash of whatever bearer is sent, valid or not. A client that sends a fresh random
     * bearer on every call gets a fresh bucket every time: unauthenticated floods from one address are never limited
     * (each one still costs an ApiKey lookup). Expected: one address is capped regardless of the bearer it sends.
     */
    public function test_random_invalid_bearers_from_one_address_are_still_limited(): void
    {
        $statuses = [];
        for ($i = 0; $i < 130; $i++) {
            $statuses[] = $this->withToken('junk' . bin2hex(random_bytes(8)))
                ->getJson(route('api.v1.admin.users.index'))->status();
        }

        $this->assertContains(429, $statuses, 'a flood of invalid bearers from one IP was never throttled');
    }

    /** Guard: odd per_page values are clamped, not errors. */
    public function test_odd_per_page_values_are_clamped(): void
    {
        User::factory()->count(3)->create();
        $token = $this->token(['admin.users.view']);

        foreach (['-5' => 1, '0' => 1, 'abc' => 1, '2' => 2] as $value => $expected) {
            $this->withToken($token)->getJson(route('api.v1.admin.users.index', ['per_page' => $value]))
                ->assertOk()->assertJsonCount($expected, 'data');
        }
    }

    /** Guard (untested in the PR): seeing debug logs is not deleting them, through the real Livewire bulk action. */
    public function test_a_debug_log_viewer_cannot_bulk_delete(): void
    {
        config(['settings.debug' => true]);
        $log = DebugLog::create(['type' => 'exception', 'context' => ['message' => 'x']]);
        $viewer = Staff::factory()->create(['role_id' => Role::create(['name' => 'V' . uniqid(), 'permissions' => ['admin.debug_logs.view']])->id]);
        $this->actingAs($viewer);

        try {
            Livewire::test(ListErrorLogs::class)->callTableBulkAction('delete', [$log]);
        } catch (\Throwable) {
            // denied by throwing is fine too
        }

        $this->assertModelExists($log);
    }

    /** Positive control for the guard above: the delete permission does delete. */
    public function test_a_debug_log_deleter_can_bulk_delete(): void
    {
        config(['settings.debug' => true]);
        $log = DebugLog::create(['type' => 'exception', 'context' => ['message' => 'x']]);
        $deleter = Staff::factory()->create(['role_id' => Role::create(['name' => 'D' . uniqid(), 'permissions' => ['admin.debug_logs.view', 'admin.debug_logs.delete']])->id]);
        $this->actingAs($deleter);

        Livewire::test(ListErrorLogs::class)->callTableBulkAction('delete', [$log]);

        $this->assertModelMissing($log);
    }
}
