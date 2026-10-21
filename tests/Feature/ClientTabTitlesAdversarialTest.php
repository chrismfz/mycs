<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\Pages\ShowNotes;
use App\Models\Client;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of PR #301 (every client tab titled «#id Name», the tab as subheading, breadcrumbs back to the
 * Summary): the name is escaped wherever it shows, a Livewire update costs no query per mention of the name, and the
 * edit page shows the new name once saved. Tests named test_holds_… pin behaviour that already holds.
 */
class ClientTabTitlesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['view', 'edit', 'services', 'domains', 'invoices', 'credits', 'credit-history', 'tickets', 'billing-agreements', 'notes'];

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]), 'staff');
    }

    private function client(string $first, string $last): Client
    {
        return Client::factory()->ownedBy(User::factory()->withoutClient()->create(['first_name' => $first, 'last_name' => $last]))->create();
    }

    public function test_holds_a_hostile_name_is_escaped_in_the_title_heading_and_breadcrumbs_of_every_tab(): void
    {
        $client = $this->client('<script>alert(1)</script>', '"O\'Brien" <img src=x onerror=alert(2)>');

        foreach (self::PAGES as $page) {
            $html = (string) $this->get(ClientResource::getUrl($page, ['record' => $client]))->assertOk()->getContent();

            $this->assertStringNotContainsString('<script>alert(1)</script>', $html, $page);
            $this->assertStringNotContainsString('<img src=x onerror', $html, $page);
            $this->assertStringContainsString(e('#' . $client->id . ' <script>alert(1)</script>'), $html, $page);
        }
    }

    public function test_holds_a_livewire_update_reads_the_name_once_not_per_mention(): void
    {
        $client = $this->client('Acme', 'Hosting');
        $component = Livewire::test(ShowNotes::class, ['record' => $client->getRouteKey()]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $component->call('$refresh')->assertSee('#' . $client->id . ' Acme Hosting');
        $contactQueries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], '"contacts"'))->count();

        $this->assertLessThanOrEqual(1, $contactQueries, 'the primary contact is read once per update');
    }

    public function test_holds_a_client_without_contact_or_owner_still_gets_a_title(): void
    {
        $client = Client::factory()->create();
        $client->contacts()->delete();
        $client->users()->detach();

        foreach (self::PAGES as $page) {
            $this->get(ClientResource::getUrl($page, ['record' => $client]))->assertOk()->assertSee('#' . $client->id);
        }
    }

    public function test_holds_the_edit_page_shows_the_new_name_once_saved(): void
    {
        $client = $this->client('Acme', 'Hosting');

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->fillForm(['primaryContact.first_name' => 'Zeta', 'primaryContact.last_name' => 'Cloud'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSee('#' . $client->id . ' Zeta Cloud');
    }
}
