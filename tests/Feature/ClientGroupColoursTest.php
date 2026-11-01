<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientGroupResource\Pages\ListClientGroups;
use App\Admin\Resources\ClientResource\Pages\EditClient;
use App\Admin\Resources\ClientResource\Pages\ListClients;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A group's clients marked with its colour, as WHMCS marks them (owner 2026-11-01): the client list (ID on its colour, a
 * group column, a group filter), the edit form's choices with what each gives, and the groups page.
 */
class ClientGroupColoursTest extends TestCase
{
    use RefreshDatabase;

    private Client $member;

    private Client $other;

    private ClientGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $this->actingAs(Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]));
        $this->group = ClientGroup::query()->create(['name' => '10% Discount', 'colour' => '#F25CED', 'discount_percent' => 10, 'separate_invoices' => true]);
        $this->member = User::factory()->create(['first_name' => 'Anna', 'last_name' => 'Member'])->client();
        $this->member->forceFill(['client_group_id' => $this->group->id])->save();
        $this->other = User::factory()->create(['first_name' => 'Otto', 'last_name' => 'Nogroup'])->client();
    }

    public function test_the_badge_is_on_its_colour_escaped_and_readable(): void
    {
        $this->assertStringContainsString('background-color: #F25CED; color: #111827', (string) $this->group->badge(), 'a light colour: dark text');
        $dark = ClientGroup::query()->create(['name' => '<b>Dark</b>', 'colour' => '#1e3a8a', 'discount_percent' => 0]);
        $this->assertStringContainsString('color: #ffffff', (string) $dark->badge());
        $this->assertStringContainsString('&lt;b&gt;Dark&lt;/b&gt;', (string) $dark->badge(), 'the name escaped');

        DB::table('client_groups')->where('id', $dark->id)->update(['colour' => 'red;x:1']);
        $this->assertStringNotContainsString('red;x:1', (string) $dark->fresh()->badge(), 'not a hex colour: never in the style');
        DB::table('client_groups')->where('id', $dark->id)->update(['colour' => "#fff\n"]);
        $this->assertNull($dark->fresh()->safeColour(), 'a newline after it: refused (\\z)');
        DB::table('client_groups')->where('id', $dark->id)->update(['colour' => '#fff']);
        $this->assertStringContainsString('background-color: #fff; color: #111827', (string) $dark->fresh()->badge(), 'the short form');
        $this->assertSame(['10% off services', 'Separate invoices'], $this->group->perks());
    }

    public function test_the_list_marks_a_groups_clients_and_filters_by_group(): void
    {
        Livewire::test(ListClients::class)
            ->assertSee('background-color: #F25CED', false)
            ->assertSee('10% Discount')
            ->filterTable('client_group_id', (string) $this->group->id)
            ->assertCanSeeTableRecords([$this->member])->assertCanNotSeeTableRecords([$this->other])
            ->filterTable('client_group_id', 'none')
            ->assertCanSeeTableRecords([$this->other])->assertCanNotSeeTableRecords([$this->member])
            ->assertSee(__('No group'));
    }

    /** Review of #574: a group's name staff or an import wrote never reaches the list as HTML. */
    public function test_a_groups_name_is_escaped_on_the_list(): void
    {
        $this->group->forceFill(['name' => '<img src=x onerror=alert(1)>'])->save();

        Livewire::test(ListClients::class)->assertDontSee('<img src=x', false)->assertSee('&lt;img src=x', false);
    }

    public function test_the_edit_form_says_what_the_chosen_group_gives(): void
    {
        $quiet = ClientGroup::query()->create(['name' => 'Quiet', 'colour' => '#cccccc', 'discount_percent' => 0, 'no_overdue_reminders' => true]);

        Livewire::test(EditClient::class, ['record' => $this->member->getRouteKey()])
            ->assertSee('10% off services · Separate invoices')
            ->set('data.client_group_id', (string) $quiet->id) // live: the text follows the choice
            ->assertSee('No overdue reminders. Its discount applies')->assertDontSee('10% off services');
    }

    public function test_the_groups_page_shows_each_on_its_colour_with_what_it_gives(): void
    {
        Livewire::test(ListClientGroups::class)
            ->assertSee('background-color: #F25CED', false)
            ->assertTableColumnExists('separate_invoices')
            ->assertTableColumnExists('no_overdue_reminders')
            ->assertTableColumnExists('dunning_exempt');
    }
}
