<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource;
use App\Admin\Resources\ClientResource\Pages\ShowChats;
use App\Admin\Resources\ClientResource\Pages\ViewChat;
use App\Models\Client;
use App\Models\LiveChat;
use App\Models\LiveChatMessage;
use App\Models\Role;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of #707: the admin's read-only «Chats» tab (docs/livechat.md §6α). Every test asserts the safe behaviour. */
class LiveChatHistoryAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const READER = ['admin.clients.viewAny', 'admin.chats.view'];

    private function department(string $name = 'Support'): TicketDepartment
    {
        return TicketDepartment::query()->create(['name' => $name]);
    }

    /** @param  list<string>  $permissions */
    private function staff(array $permissions = self::READER, TicketDepartment ...$departments): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => $permissions])->id]);
        foreach ($departments as $department) {
            $department->chatStaff()->attach($staff->id);
        }

        return $staff;
    }

    private function asStaff(Staff $staff): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff, 'staff');
    }

    /** @return array{Client, User} */
    private function account(): array
    {
        $user = User::factory()->create(['first_name' => 'Test', 'last_name' => 'Login']);

        return [$user->client(), $user];
    }

    private function chat(Client $client, User $user, ?TicketDepartment $department, ?Staff $operator = null, string $status = LiveChat::CLOSED): LiveChat
    {
        return LiveChat::query()->create([
            'client_id' => $client->id, 'user_id' => $user->id, 'department_id' => $department?->id, 'status' => $status,
            'assigned_to' => $operator?->id, 'closed_at' => $status === LiveChat::CLOSED ? now() : null,
        ]);
    }

    private function say(LiveChat $chat, string $type, string $body, ?User $user = null, ?Staff $staff = null): void
    {
        $chat->messages()->create(['author_type' => $type, 'user_id' => $user?->id, 'staff_id' => $staff?->id, 'body' => $body]);
    }

    private function viewUrl(Client $client, LiveChat $chat): string
    {
        return ViewChat::getUrl(['record' => $client, 'chat' => $chat->id]);
    }

    // ---- the client permission, re-checked on every request (passing)

    /** F7: the list's Livewire requests re-check access, the client's too, not only the route's middleware at the first GET. */
    public function test_list_is_forbidden_on_the_next_request_once_the_client_permission_is_gone(): void
    {
        $support = $this->department();
        [$client, $user] = $this->account();
        $chat = $this->chat($client, $user, $support);
        $reader = $this->staff(self::READER, $support);

        $this->asStaff($reader);
        $list = Livewire::test(ShowChats::class, ['record' => $client->getKey()])->assertCanSeeTableRecords([$chat]);

        $reader->role->forceFill(['permissions' => ['admin.chats.view']])->save();
        $list->call('$refresh')->assertForbidden();
    }

    public function test_list_component_refuses_to_mount_without_the_client_permission(): void
    {
        $support = $this->department();
        [$client, $user] = $this->account();
        $this->chat($client, $user, $support);

        $this->asStaff($this->staff(['admin.chats.view'], $support));
        Livewire::test(ShowChats::class, ['record' => $client->getKey()])->assertForbidden();
    }

    /** The same revocation on the chat page: refused on the next request (passes: InteractsWithRecord re-checks canAccess). */
    public function test_chat_page_is_forbidden_on_the_next_request_once_the_client_permission_is_gone(): void
    {
        $support = $this->department();
        [$client, $user] = $this->account();
        $chat = $this->chat($client, $user, $support);
        $this->say($chat, LiveChatMessage::CLIENT, 'revoked secret', user: $user);
        $reader = $this->staff(self::READER, $support);

        $this->asStaff($reader);
        $page = Livewire::test(ViewChat::class, ['record' => $client->getKey(), 'chat' => $chat->id])->assertSee('revoked secret');

        $reader->role->forceFill(['permissions' => ['admin.chats.view']])->save();
        $page->call('$refresh')->assertForbidden();
    }

    // ---- ADV-707-2: per-page tampering

    /** paginated([25, 50, 100]): a forged `tableRecordsPerPage` must not load every chat of the account in one request. */
    public function test_adv_2_forged_records_per_page_is_held_to_the_offered_options(): void
    {
        $support = $this->department();
        [$client, $user] = $this->account();
        $rows = [];
        for ($i = 0; $i < 105; $i++) {
            $rows[] = ['client_id' => $client->id, 'user_id' => $user->id, 'department_id' => $support->id, 'status' => LiveChat::CLOSED,
                'created_at' => now(), 'updated_at' => now(), 'closed_at' => now()];
        }
        DB::table('live_chats')->insert($rows);

        $this->asStaff($this->staff(self::READER, $support));
        foreach (['all', 100000] as $forged) {
            $component = Livewire::test(ShowChats::class, ['record' => $client->getKey()])->set('tableRecordsPerPage', $forged);
            $this->assertLessThanOrEqual(100, $component->instance()->getTableRecords()->count(), 'perPage ' . $forged);
        }
    }

    // ---- passing regression tests

    public function test_locked_ids_cannot_be_swapped_in_the_payload(): void
    {
        $support = $this->department();
        [$client, $user] = $this->account();
        [$other, $otherUser] = $this->account();
        $chat = $this->chat($client, $user, $support);
        $otherChat = $this->chat($other, $otherUser, $support);

        $this->asStaff($this->staff(self::READER, $support));
        foreach ([
            fn () => Livewire::test(ViewChat::class, ['record' => $client->getKey(), 'chat' => $chat->id])->set('chatId', $otherChat->id),
            fn () => Livewire::test(ViewChat::class, ['record' => $client->getKey(), 'chat' => $chat->id])->set('record', $other->getKey()),
            fn () => Livewire::test(ShowChats::class, ['record' => $client->getKey()])->set('record', $other->getKey()),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail('a locked property was updated (' . $i . ')');
            } catch (CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** Sorting, searching and filtering forged in the payload never widen the department filter nor reach raw SQL. */
    public function test_forged_sort_search_and_filters_do_not_widen_the_list(): void
    {
        $support = $this->department('Support');
        $sales = $this->department('Sales');
        [$client, $user] = $this->account();
        $theirs = $this->chat($client, $user, $support);
        $hidden = $this->chat($client, $user, $sales);
        $nullDept = $this->chat($client, $user, null);

        $this->asStaff($this->staff(self::READER, $support));
        $list = Livewire::test(ShowChats::class, ['record' => $client->getKey()]);
        foreach (['department_id:asc', 'messages_count:desc', '(select 1):asc', 'department.name:desc'] as $sort) {
            $list->set('tableSort', $sort)->assertOk()->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$hidden, $nullDept]);
        }
        $list->set('tableSearch', 'Sales')->assertCanNotSeeTableRecords([$hidden, $nullDept]);
        $list->set('tableFilters', ['department_id' => ['value' => $sales->id]])->assertCanNotSeeTableRecords([$hidden, $nullDept]);
    }

    /** A hidden chat's record action cannot be mounted by its key, so its URL never comes back. */
    public function test_a_hidden_chats_action_cannot_be_mounted_by_key(): void
    {
        $support = $this->department('Support');
        $sales = $this->department('Sales');
        [$client, $user] = $this->account();
        $hidden = $this->chat($client, $user, $sales);

        $this->asStaff($this->staff(self::READER, $support));
        $list = Livewire::test(ShowChats::class, ['record' => $client->getKey()]);
        try {
            $list->mountTableAction('view', $hidden->getKey());
        } catch (\Throwable) {
            // refused outright: fine
        }
        $this->assertStringNotContainsString($this->viewUrl($client, $hidden), (string) $list->html());
    }

    public function test_odd_chat_ids_in_the_url_are_404_never_500(): void
    {
        $support = $this->department();
        [$client] = $this->account();

        $this->asStaff($this->staff(self::READER, $support));
        $base = ClientResource::getUrl('chats', ['record' => $client]);
        foreach (['-1', '0', '1e3', '0x1', '99999999999999999999', '%20', '1%00'] as $id) {
            $this->get($base . '/' . $id)->assertNotFound();
        }
    }

    /** An inactive member, or one who still owes the required 2FA, reads nothing; neither does a customer login. */
    public function test_inactive_2fa_pending_and_customer_logins_read_nothing(): void
    {
        $support = $this->department();
        [$client, $user] = $this->account();
        $chat = $this->chat($client, $user, $support);
        $this->say($chat, LiveChatMessage::CLIENT, 'only operators', user: $user);
        $reader = $this->staff(self::READER, $support);

        $this->asStaff($reader);
        $page = Livewire::test(ViewChat::class, ['record' => $client->getKey(), 'chat' => $chat->id])->assertSee('only operators');
        $list = Livewire::test(ShowChats::class, ['record' => $client->getKey()])->assertCanSeeTableRecords([$chat]);
        $reader->forceFill(['active' => false])->save();
        $page->call('$refresh')->assertNotFound();
        $list->call('$refresh')->assertForbidden();

        config(['settings.staff_2fa_required' => true]);
        $pending = Staff::factory()->withoutTwoFactor()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => self::READER])->id]);
        $support->chatStaff()->attach($pending->id);
        $this->asStaff($pending);
        $this->assertNotSame(200, $this->get($this->viewUrl($client, $chat))->getStatusCode());
        Livewire::test(ShowChats::class, ['record' => $client->getKey()])->assertForbidden();

        auth('staff')->logout();
        $this->actingAs($user, 'web');
        $this->assertNotSame(200, $this->get($this->viewUrl($client, $chat))->getStatusCode());
        $this->assertNotSame(200, $this->get(ShowChats::getUrl(['record' => $client]))->getStatusCode());
    }

    /** Department, operator, customer and staff names are escaped in the list and on the chat page. */
    public function test_names_are_escaped_in_list_and_page(): void
    {
        $support = $this->department('<script>dept()</script>');
        [$client, $user] = $this->account();
        $user->forceFill(['last_name' => '<img src=x onerror=user()>'])->save();
        $operator = Staff::factory()->create(['first_name' => '<svg onload=op()>', 'last_name' => 'X']);
        $chat = $this->chat($client, $user, $support, $operator);
        $this->say($chat, LiveChatMessage::STAFF, 'hi', staff: $operator);
        $this->say($chat, LiveChatMessage::CLIENT, 'https://example.test/"><script>url()</script>', user: $user);

        $this->asStaff($this->staff(self::READER, $support));
        foreach ([ShowChats::getUrl(['record' => $client]), $this->viewUrl($client, $chat)] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>dept()', $html);
            $this->assertStringNotContainsString('<img src=x onerror=user()>', $html);
            $this->assertStringNotContainsString('<svg onload=op()>', $html);
            $this->assertStringNotContainsString('<script>url()', $html);
        }
        $list = Livewire::test(ShowChats::class, ['record' => $client->getKey()]);
        $this->assertStringNotContainsString('<script>dept()', (string) $list->html());
        $this->assertStringNotContainsString('<svg onload=op()>', (string) $list->html());
    }

    /** No N+1: the list's and the page's queries do not grow with the number of chats or messages. */
    public function test_queries_do_not_grow_with_chats_or_messages(): void
    {
        $support = $this->department();
        [$client, $user] = $this->account();
        $reader = $this->staff(self::READER, $support);
        $this->asStaff($reader);

        $count = function (callable $fn): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $fn();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $listQueries = fn () => $count(fn () => Livewire::test(ShowChats::class, ['record' => $client->getKey()])->call('$refresh'));

        $first = $this->chat($client, $user, $support, Staff::factory()->create());
        $this->say($first, LiveChatMessage::CLIENT, 'one', user: $user);
        $this->say($first, LiveChatMessage::STAFF, 'two', staff: Staff::factory()->create());
        $listQueries();
        $few = $listQueries();
        for ($i = 0; $i < 10; $i++) {
            $chat = $this->chat($client, $user, $support, Staff::factory()->create());
            $this->say($chat, LiveChatMessage::STAFF, 'msg', staff: Staff::factory()->create());
        }
        $this->assertSame($few, $listQueries());

        $pageQueries = fn () => $count(fn () => Livewire::test(ViewChat::class, ['record' => $client->getKey(), 'chat' => $first->id])->call('$refresh'));
        $pageQueries();
        $few = $pageQueries();
        for ($i = 0; $i < 10; $i++) {
            $this->say($first, LiveChatMessage::STAFF, 'msg ' . $i, staff: Staff::factory()->create());
            $this->say($first, LiveChatMessage::CLIENT, 'reply ' . $i, user: $user);
        }
        $this->assertSame($few, $pageQueries());
    }

    // ---- ADV-707-3: an operator with no first or last name

    /** Staff::name falls back to the email, which the eager load leaves out: the operator must still be named. */
    public function test_adv_3_an_operator_without_a_name_is_named_by_email(): void
    {
        $support = $this->department();
        [$client, $user] = $this->account();
        $operator = Staff::factory()->create(['first_name' => '', 'last_name' => '', 'email' => 'nameless-op@example.test']);
        $chat = $this->chat($client, $user, $support, $operator);

        $this->asStaff($this->staff(self::READER, $support));
        $this->get($this->viewUrl($client, $chat))->assertOk()->assertSee('nameless-op@example.test');
        Livewire::test(ShowChats::class, ['record' => $client->getKey()])
            ->assertTableColumnStateSet('operator.name', 'nameless-op@example.test', $chat);
    }
}
