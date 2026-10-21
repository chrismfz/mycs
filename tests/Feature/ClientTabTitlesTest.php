<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Models\Client;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every tab of a client says whose it is (owner 2026-10-21: only the Summary did). */
class ClientTabTitlesTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_tab_has_the_client_as_its_title_and_the_way_back_to_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
        $client = Client::factory()->ownedBy(User::factory()->withoutClient()->create(['first_name' => 'Acme', 'last_name' => 'Hosting']))->create();
        $title = '#' . $client->id . ' Acme Hosting';
        $summary = ClientResource::getUrl('view', ['record' => $client]);

        foreach (['edit' => 'Edit', 'services' => 'Products/Services', 'domains' => 'Domains', 'invoices' => 'Invoices', 'credits' => 'Credits',
            'credit-history' => 'Credit history', 'tickets' => 'Tickets', 'billing-agreements' => 'Billing Agreements', 'notes' => 'Notes'] as $page => $tab) {
            $html = (string) $this->get(ClientResource::getUrl($page, ['record' => $client]))->assertOk()->getContent();

            $this->assertMatchesRegularExpression('#<h1[^>]*>\s*' . preg_quote($title, '#') . '\s*</h1>#', $html, $page);
            $this->assertMatchesRegularExpression('#<title>\s*' . preg_quote(e($title), '#') . '#', $html, "{$page}: the browser tab too");
            $this->assertStringContainsString('href="' . e($summary) . '"', $html, "{$page}: back to the Summary");
            $this->assertStringContainsString(e($tab), $html, $page);
        }

        $this->get($summary)->assertOk()->assertSee($title);
    }
}
