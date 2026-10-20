<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Pages\ClientSummaryPage;
use App\Admin\Resources\ClientResource\Pages\ShowNotes;
use App\Models\ApiKey;
use App\Models\Audit;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Ops\Dossier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adversarial review of PR #243 (account notes, staff only): a note must reach no customer (client area, «Login as
 * client», API, MCP, templates) and no staff without the client permissions; writing needs admin.clients.update even
 * through crafted Livewire calls; another account's note is out of reach from this account's page; nothing is rendered
 * as HTML. Tests named test_holds_… pin behaviour that already holds.
 */
class ClientNotesAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'INTERNAL-ONLY-7f3a: bad payer, never extend';

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

    private function note(Client $client, array $attributes = []): ClientNote
    {
        return ClientNote::query()->create([...['client_id' => $client->id, 'body' => self::SECRET, 'sticky' => true], ...$attributes]);
    }

    // ---- staff without the client permissions -------------------------------------------------------------------

    /** Staff without admin.clients.viewAny (here: only invoices) cannot open the Notes page, as the Summary next to it. */
    public function test_holds_staff_without_clients_view_cannot_open_the_notes_page(): void
    {
        $client = User::factory()->create()->client();
        $this->note($client);
        $outsider = $this->staff(['admin.invoices.viewAny']);

        $this->actingAs($outsider, 'staff')->get(ClientResource::getUrl('view', ['record' => $client]))->assertForbidden();
        $response = $this->actingAs($outsider, 'staff')->get(ClientResource::getUrl('notes', ['record' => $client]));

        $this->assertContains($response->getStatusCode(), [403, 404], 'the Notes page is refused like the Summary');
        $response->assertDontSee(self::SECRET);
    }

    /** The same through Livewire: mounting the page component directly is refused too. */
    public function test_holds_staff_without_clients_view_cannot_mount_the_notes_component(): void
    {
        $client = User::factory()->create()->client();
        $this->note($client);

        $component = Livewire::actingAs($this->staff(['admin.invoices.viewAny']), 'staff')->test(ShowNotes::class, ['record' => $client->id]);

        $component->assertForbidden();
    }

    /**
     * F1 (low, defence in depth): there is no ClientNotePolicy, and Filament allows what no policy defines (see
     * AuditPolicy's comment), so the Create/Edit/Delete actions on the Notes page authorize anyone: the only guard is
     * `visible()` on each action. Another action, a bulk delete, a relation manager or an API resource on notes added
     * later would be open to a viewer. Expected: a policy (viewAny on admin.clients.viewAny; create/update/delete on
     * admin.clients.update), so that the Gate itself allows a writer and refuses a viewer.
     */
    public function test_a_policy_guards_writing_notes_not_only_the_actions_visibility(): void
    {
        $client = User::factory()->create()->client();
        $note = $this->note($client);
        $viewer = $this->staff(['admin.clients.viewAny']);
        $writer = $this->staff(['admin.clients.viewAny', 'admin.clients.update']);

        $this->assertNotNull(Gate::getPolicyFor(ClientNote::class), 'ClientNote has a policy');
        $this->assertTrue($writer->can('update', $note), 'a writer may update');
        $this->assertTrue($writer->can('create', ClientNote::class), 'a writer may create');
        $this->assertFalse($viewer->can('create', ClientNote::class), 'a viewer may not create');
        $this->assertFalse($viewer->can('update', $note), 'a viewer may not update');
        $this->assertFalse($viewer->can('delete', $note), 'a viewer may not delete');
    }

    // ---- crafted Livewire calls ---------------------------------------------------------------------------------

    public function test_holds_a_viewer_cannot_create_edit_or_delete_through_crafted_livewire_calls(): void
    {
        $client = User::factory()->create()->client();
        $note = $this->note($client, ['body' => 'original']);
        $page = Livewire::actingAs($this->staff(['admin.clients.viewAny']), 'staff')->test(ShowNotes::class, ['record' => $client->id]);

        $page->call('mountAction', 'create', [], ['table' => true])
            ->set('mountedActions.0.data.body', 'forged')->call('callMountedAction');
        $page->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $note->id])
            ->set('mountedActions.0.data.body', 'forged')->call('callMountedAction');
        $page->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $note->id])->call('callMountedAction');

        $this->assertSame(1, ClientNote::query()->count());
        $this->assertSame('original', $note->fresh()?->body);
    }

    public function test_holds_a_writer_cannot_edit_or_delete_another_clients_note_through_this_clients_page(): void
    {
        $client = User::factory()->create()->client();
        $other = User::factory()->create()->client();
        $foreign = $this->note($other, ['body' => 'theirs']);
        $page = Livewire::actingAs($this->staff(['admin.clients.viewAny', 'admin.clients.update']), 'staff')
            ->test(ShowNotes::class, ['record' => $client->id]);

        $page->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $foreign->id])
            ->set('mountedActions.0.data.body', 'changed from the wrong page')->call('callMountedAction');
        $page->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $foreign->id])->call('callMountedAction');

        $this->assertSame('theirs', $foreign->fresh()?->body);
        $this->assertSame($other->id, $foreign->fresh()?->client_id);
    }

    public function test_holds_crafted_form_data_cannot_move_a_note_to_another_client_or_forge_its_author(): void
    {
        $client = User::factory()->create()->client();
        $other = User::factory()->create()->client();
        $someoneElse = $this->staff(['*']);
        $writer = $this->staff(['admin.clients.viewAny', 'admin.clients.update']);
        $page = Livewire::actingAs($writer, 'staff')->test(ShowNotes::class, ['record' => $client->id]);

        $page->call('mountAction', 'create', [], ['table' => true])
            ->set('mountedActions.0.data.body', 'mine')
            ->set('mountedActions.0.data.client_id', $other->id)
            ->set('mountedActions.0.data.staff_id', $someoneElse->id)
            ->call('callMountedAction');

        $note = ClientNote::query()->sole();
        $this->assertSame($client->id, $note->client_id);
        $this->assertSame($writer->id, $note->staff_id);

        $page->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $note->id])
            ->set('mountedActions.0.data.client_id', $other->id)
            ->set('mountedActions.0.data.staff_id', $someoneElse->id)
            ->call('callMountedAction');
        $this->assertSame($client->id, $note->fresh()?->client_id);
        $this->assertSame($writer->id, $note->fresh()?->staff_id);
    }

    /** Another member of staff editing a note keeps its author; who changed it is in the audit (causer). */
    public function test_holds_an_edit_by_other_staff_is_audited_with_the_editor_as_causer(): void
    {
        $client = User::factory()->create()->client();
        $author = $this->staff(['*']);
        $note = $this->note($client, ['staff_id' => $author->id, 'body' => 'first']);
        $editor = $this->staff(['admin.clients.viewAny', 'admin.clients.update']);
        config(['audit.console' => true]); // tests run in the console, where audits are off

        Livewire::actingAs($editor, 'staff')->test(ShowNotes::class, ['record' => $client->id])
            ->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $note->id])
            ->set('mountedActions.0.data.body', 'second')->call('callMountedAction');

        $this->assertSame('second', $note->fresh()?->body);
        $audit = Audit::query()->where('auditable_type', $note->getMorphClass())->where('auditable_id', $note->id)->where('event', 'updated')->sole();
        $this->assertSame($editor->id, (int) $audit->user_id);
    }

    // ---- HTML / JS ----------------------------------------------------------------------------------------------

    public function test_holds_body_and_author_name_are_escaped_on_the_notes_page_and_the_summary(): void
    {
        $client = User::factory()->create()->client();
        $author = $this->staff(['*']);
        $author->forceFill(['first_name' => '<img src=x onerror=alert(2)>'])->save();
        $this->note($client, ['staff_id' => $author->id, 'body' => "<script>alert(1)</script>\n<b onmouseover=alert(3)>x</b>"]);
        $viewer = $this->staff(['admin.clients.viewAny']);

        foreach (['notes', 'view'] as $page) {
            $html = $this->actingAs($viewer, 'staff')->get(ClientResource::getUrl($page, ['record' => $client]))->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(1)</script>', $html, $page);
            $this->assertStringNotContainsString('<img src=x onerror=alert(2)>', $html, $page);
            $this->assertStringNotContainsString('<b onmouseover=alert(3)>', $html, $page);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, $page);
        }
    }

    // ---- the Summary --------------------------------------------------------------------------------------------

    public function test_holds_the_summary_renders_with_a_deleted_author_and_many_long_sticky_notes(): void
    {
        $client = User::factory()->create()->client();
        $author = $this->staff(['*']);
        for ($i = 0; $i < 12; $i++) {
            $this->note($client, ['staff_id' => $author->id, 'body' => str_repeat('ü', 10000)]);
        }
        $author->forceDelete();
        $this->assertNull(ClientNote::query()->first()?->staff_id, 'nullOnDelete');

        $viewer = $this->staff(['admin.clients.viewAny']);
        $this->actingAs($viewer, 'staff')->get(ClientResource::getUrl('view', ['record' => $client]))->assertOk();
        Livewire::actingAs($viewer, 'staff')->test(ClientSummaryPage::class, ['record' => $client->getRouteKey()])->assertOk();
        $this->actingAs($viewer, 'staff')->get(ClientResource::getUrl('notes', ['record' => $client]))->assertOk();
    }

    // ---- the customer -------------------------------------------------------------------------------------------

    public function test_holds_the_client_area_never_shows_a_note_to_the_customer_or_to_staff_logged_in_as_them(): void
    {
        $owner = User::factory()->create();
        $client = $owner->client();
        $this->note($client);
        $this->note($client, ['sticky' => false, 'body' => self::SECRET . ' (plain)']);

        $routes = ['dashboard', 'account', 'account.contacts', 'account.users', 'account.accounts', 'invoices', 'services', 'account.credits'];
        foreach ($routes as $route) {
            $this->actingAs($owner, 'web')->get(route($route))->assertDontSee('INTERNAL-ONLY-7f3a');
        }

        $staff = $this->staff(['*']);
        foreach ($routes as $route) {
            $this->actingAs($staff, 'staff')->withSession(['impersonating' => $owner->id])->get(route($route))->assertDontSee('INTERNAL-ONLY-7f3a');
        }
    }

    public function test_holds_the_admin_api_cannot_include_or_return_notes(): void
    {
        $client = User::factory()->create()->client();
        $this->note($client);
        $token = 'TEST' . bin2hex(random_bytes(8));
        $owner = $this->staff(['*']);
        ApiKey::create(['staff_id' => $owner->id, 'name' => 'adv', 'type' => 'admin', 'enabled' => true, 'token' => hash('sha256', $token),
            'user_id' => $owner->id, 'permissions' => ['admin.clients.view', 'admin.notes.view', 'admin.users.view', 'admin.services.view']]);

        foreach ([
            route('api.v1.admin.clients.show', ['client' => $client->id, 'include' => 'notes']),
            route('api.v1.admin.clients.index', ['include' => 'notes']),
            route('api.v1.admin.clients.show', ['client' => $client->id]),
            route('api.v1.admin.clients.index'),
        ] as $url) {
            $this->assertStringNotContainsString('INTERNAL-ONLY-7f3a', (string) $this->withToken($token)->getJson($url)->getContent(), $url);
        }
    }

    public function test_holds_the_mcp_client_dossier_and_json_of_a_client_carry_no_notes(): void
    {
        $client = User::factory()->create()->client();
        $this->note($client);

        $this->assertStringNotContainsString('INTERNAL-ONLY-7f3a', (string) json_encode(Dossier::client($client->fresh())));
        $this->assertStringNotContainsString('INTERNAL-ONLY-7f3a', (string) json_encode($client->fresh()));
    }
}
