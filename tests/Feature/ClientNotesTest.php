<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Pages\ShowNotes;
use App\Helpers\TemplateRenderer;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An account's notes, staff only (WHMCS's client «Notes», ROADMAP «Επόμενα» 2): who wrote each and when; sticky ones on
 * the client's Summary. Read by who may see the client, written by who may update it; never in a template.
 */
class ClientNotesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @param  list<string>  $permissions */
    private function staff(array $permissions): Staff
    {
        return Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function client(): Client
    {
        return User::factory()->create()->client();
    }

    public function test_staff_who_may_update_a_client_add_change_and_remove_its_notes(): void
    {
        $staff = $this->staff(['admin.clients.viewAny', 'admin.clients.update']);
        $client = $this->client();

        $page = Livewire::actingAs($staff, 'staff')->test(ShowNotes::class, ['record' => $client->id]);
        $page->callAction(TestAction::make(CreateAction::class)->table(), ['body' => "Pays by transfer.\nCall before suspending.", 'sticky' => true])
            ->assertHasNoErrors();

        $note = $client->notes()->sole();
        $this->assertSame($staff->id, $note->staff_id, 'its author');
        $this->assertTrue($note->sticky);

        $page->callAction(TestAction::make(EditAction::class)->table($note), ['body' => 'Pays by transfer.', 'sticky' => false]);
        $this->assertSame('Pays by transfer.', $note->fresh()->body);
        $this->assertFalse($note->fresh()->sticky);

        $page->callAction(TestAction::make(DeleteAction::class)->table($note));
        $this->assertSame(0, $client->notes()->count());
    }

    public function test_staff_who_may_only_see_a_client_read_its_notes_and_cannot_write(): void
    {
        $client = $this->client();
        $note = ClientNote::query()->create(['client_id' => $client->id, 'body' => 'Reseller of example.gr', 'sticky' => false]);
        $other = ClientNote::query()->create(['client_id' => $this->client()->id, 'body' => 'Someone else entirely', 'sticky' => false]);
        $viewer = $this->staff(['admin.clients.viewAny']);

        Livewire::actingAs($viewer, 'staff')->test(ShowNotes::class, ['record' => $client->id])
            ->assertCanSeeTableRecords([$note])->assertCanNotSeeTableRecords([$other])
            ->assertActionHidden(TestAction::make(CreateAction::class)->table())
            ->assertActionHidden(TestAction::make(EditAction::class)->table($note))
            ->assertActionHidden(TestAction::make(DeleteAction::class)->table($note));
    }

    public function test_a_sticky_note_is_on_the_clients_summary_a_plain_one_is_not(): void
    {
        $staff = $this->staff(['*']);
        $client = $this->client();
        ClientNote::query()->create(['client_id' => $client->id, 'staff_id' => $staff->id, 'body' => 'VIP: never suspend without a call', 'sticky' => true]);
        ClientNote::query()->create(['client_id' => $client->id, 'body' => 'Asked about a discount in 2024', 'sticky' => false]);

        $this->actingAs($staff, 'staff')->get(ClientResource::getUrl('view', ['record' => $client]))->assertOk()
            ->assertSee('VIP: never suspend without a call')->assertDontSee('Asked about a discount in 2024')
            ->assertSee($staff->name);
    }

    public function test_a_template_never_reaches_the_notes(): void
    {
        $client = $this->client();
        ClientNote::query()->create(['client_id' => $client->id, 'body' => 'Internal only', 'sticky' => true]);

        $rendered = TemplateRenderer::render('[{{ client.notes }}]', ['client' => $client]);

        $this->assertStringNotContainsString('Internal only', $rendered);
    }
}
