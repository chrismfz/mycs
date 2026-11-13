<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ShowTickets;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Review of #845: the service under each ticket is named without a query per row. */
class ClientTicketsTabReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_naming_each_tickets_service_does_not_query_per_row(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $client = User::factory()->create()->client();
        $staff = Staff::factory()->withPermissions(['admin.clients.viewAny', 'admin.clients.view', 'admin.tickets.viewAny'])->create();
        $made = $this->createProduct();
        $add = function () use ($client, $made) {
            $service = Service::factory()->create(['client_id' => $client->id, 'plan_id' => $made->plan->id, 'product_id' => $made->product->id,
                'status' => 'active', 'domain' => null, 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
            Ticket::query()->create(['client_id' => $client->id, 'service_id' => $service->id, 'subject' => 'S', 'priority' => 'medium', 'status' => Ticket::STATUS_OPEN]);
        };
        $count = function () use ($staff, $client) {
            $queries = 0;
            DB::listen(function () use (&$queries) {
                $queries++;
            });
            $this->livewireAs($staff)->test(ShowTickets::class, ['record' => $client->id]);
            DB::flushQueryLog();

            return $queries;
        };

        $add();
        $one = $count();
        $add();
        $add();
        $add();
        DB::getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');
        $four = $count();

        $this->assertLessThanOrEqual($one, $four, "1 ticket: {$one} queries, 4 tickets: {$four}");
    }
}
