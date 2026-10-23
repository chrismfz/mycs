<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\User;
use App\Support\AdminBadge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The admin menu's counts, kept a few seconds (App\Support\AdminBadge), and the overdue rule's plain date comparison. */
class AdminBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_count_is_kept_for_the_configured_seconds(): void
    {
        config(['app.admin_badge_cache_seconds' => 30]);
        $n = 1;

        $this->assertSame(1, AdminBadge::count('x', fn () => $n));
        $n = 5;
        $this->assertSame(1, AdminBadge::count('x', fn () => $n), 'kept');
        $this->travel(31)->seconds();
        $this->assertSame(5, AdminBadge::count('x', fn () => $n), 'counted again once it expired');
    }

    public function test_zero_counts_every_time_and_none_is_null(): void
    {
        config(['app.admin_badge_cache_seconds' => 0]);
        $n = 2;
        $this->assertSame('2', AdminBadge::label('y', fn () => $n));
        $n = 0;
        $this->assertNull(AdminBadge::label('y', fn () => $n));
    }

    /** due_at < today, compared as dates: due today is not overdue, due yesterday is (DATE() dropped for the index). */
    public function test_overdue_is_strictly_before_today(): void
    {
        $this->travelTo(now()->setTime(23, 59));
        $client = User::factory()->create()->client();
        $make = fn (string $due) => Invoice::query()->create(['status' => Invoice::STATUS_UNPAID, 'client_id' => $client->id, 'currency_code' => 'USD', 'due_at' => $due]);
        $today = $make(today()->toDateString());
        $yesterday = $make(today()->subDay()->toDateString());

        $overdue = Invoice::query()->overdue()->pluck('id')->all();

        $this->assertContains($yesterday->id, $overdue);
        $this->assertNotContains($today->id, $overdue);
    }
}
