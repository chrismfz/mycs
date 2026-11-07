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
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

/** The account's chats in the admin, read only (docs/livechat.md §6α): the «Chats» tab beside Tickets and Emails. */
class LiveChatHistoryAdminTest extends TestCase
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
        $user = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Login']);

        return [$user->client(), $user];
    }

    private function chat(Client $client, User $user, TicketDepartment $department, ?Staff $operator = null, string $status = LiveChat::CLOSED): LiveChat
    {
        return LiveChat::query()->create([
            'client_id' => $client->id, 'user_id' => $user->id, 'department_id' => $department->id, 'status' => $status,
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

    public function test_an_operator_sees_the_accounts_chats_and_reads_one_with_the_staff_notes(): void
    {
        $support = $this->department('Support');
        $operator = Staff::factory()->create(['first_name' => 'Nikos', 'last_name' => 'Operatoridis']);
        $reader = $this->staff(self::READER, $support);
        [$client, $user] = $this->account();
        $closed = $this->chat($client, $user, $support, $operator);
        $this->say($closed, LiveChatMessage::CLIENT, 'Το site μου είναι κάτω', user: $user);
        $this->say($closed, LiveChatMessage::SYSTEM, 'Taken over by Nikos Operatoridis from Someone.', staff: $operator);
        $this->say($closed, LiveChatMessage::STAFF, 'Το κοιτάζω τώρα', staff: $operator);
        $open = $this->chat($client, $user, $support, status: LiveChat::WAITING);
        $this->say($open, LiveChatMessage::CLIENT, 'a second chat', user: $user);

        $this->asStaff($reader);
        $this->get(ShowChats::getUrl(['record' => $client]))->assertOk()->assertSee(__('Chats'));
        Livewire::test(ShowChats::class, ['record' => $client->getKey()])
            ->assertCanSeeTableRecords([$closed, $open])
            ->assertTableColumnStateSet('department.name', 'Support', $closed)
            ->assertTableColumnStateSet('operator.name', 'Nikos Operatoridis', $closed)
            ->assertTableColumnStateSet('user.name', 'Maria Login', $closed)
            ->assertTableColumnStateSet('messages_count', 3, $closed)
            ->assertTableColumnStateSet('messages_count', 1, $open)
            ->assertTableColumnStateSet('status', LiveChat::WAITING, $open);

        $this->get($this->viewUrl($client, $closed))->assertOk()
            ->assertSee('Το site μου είναι κάτω')->assertSee('Το κοιτάζω τώρα')
            ->assertSee('Taken over by Nikos Operatoridis from Someone.')
            ->assertSee('Nikos Operatoridis')->assertSee('Maria Login')->assertSee(__('Chat #:id', ['id' => $closed->id]))
            ->assertDontSee('a second chat');
    }

    public function test_a_member_without_the_chats_permission_gets_403_and_no_tab(): void
    {
        $support = $this->department();
        [$client, $user] = $this->account();
        $chat = $this->chat($client, $user, $support);
        $member = $this->staff(['admin.clients.viewAny'], $support);

        $this->asStaff($member);
        $this->get(ShowChats::getUrl(['record' => $client]))->assertForbidden();
        $this->get($this->viewUrl($client, $chat))->assertForbidden();
        $this->get(ClientResource::getUrl('view', ['record' => $client]))->assertOk()
            ->assertDontSee(ShowChats::getUrl(['record' => $client]));
    }

    public function test_an_operator_who_may_not_see_the_client_gets_403(): void
    {
        $support = $this->department();
        [$client, $user] = $this->account();
        $chat = $this->chat($client, $user, $support);

        $this->asStaff($this->staff(['admin.chats.view'], $support));
        $this->get(ShowChats::getUrl(['record' => $client]))->assertForbidden();
        $this->get($this->viewUrl($client, $chat))->assertForbidden();
    }

    public function test_a_member_outside_the_department_neither_sees_the_chat_nor_opens_it_by_id(): void
    {
        $support = $this->department('Support');
        $sales = $this->department('Sales');
        [$client, $user] = $this->account();
        $theirs = $this->chat($client, $user, $support);
        $this->say($theirs, LiveChatMessage::CLIENT, 'support secret', user: $user);
        $other = $this->chat($client, $user, $sales);
        $this->say($other, LiveChatMessage::CLIENT, 'sales secret', user: $user);
        $unlisted = LiveChat::query()->create(['client_id' => $client->id, 'user_id' => $user->id, 'department_id' => null, 'status' => LiveChat::CLOSED]);

        $this->asStaff($this->staff(self::READER, $support));
        Livewire::test(ShowChats::class, ['record' => $client->getKey()])
            ->assertCanSeeTableRecords([$theirs])->assertCanNotSeeTableRecords([$other, $unlisted])->assertDontSee('Sales');
        $this->get($this->viewUrl($client, $other))->assertNotFound()->assertDontSee('sales secret');
        $this->get($this->viewUrl($client, $unlisted))->assertNotFound();

        // Listed in no department at all: sees none.
        $this->asStaff($this->staff(self::READER));
        Livewire::test(ShowChats::class, ['record' => $client->getKey()])->assertCanNotSeeTableRecords([$theirs, $other, $unlisted]);
        $this->get($this->viewUrl($client, $theirs))->assertNotFound();
    }

    /** F7: unlisted after the page opened, the next request no longer shows it. */
    public function test_the_department_is_checked_on_every_request_not_only_at_mount(): void
    {
        $support = $this->department();
        [$client, $user] = $this->account();
        $chat = $this->chat($client, $user, $support);
        $this->say($chat, LiveChatMessage::CLIENT, 'still here?', user: $user);
        $reader = $this->staff(self::READER, $support);

        $this->asStaff($reader);
        $page = Livewire::test(ViewChat::class, ['record' => $client->getKey(), 'chat' => $chat->id])->assertSee('still here?');
        $list = Livewire::test(ShowChats::class, ['record' => $client->getKey()])->assertCanSeeTableRecords([$chat]);

        $support->chatStaff()->detach($reader->id);
        $page->call('$refresh')->assertNotFound();
        $list->call('$refresh')->assertCanNotSeeTableRecords([$chat]);

        $support->chatStaff()->attach($reader->id);
        $reader->role->forceFill(['permissions' => ['admin.clients.viewAny']])->save();
        $list->call('$refresh')->assertForbidden();
    }

    public function test_another_clients_chat_under_this_clients_url_is_404(): void
    {
        $support = $this->department();
        [$client] = $this->account();
        [$other, $otherUser] = $this->account();
        $chat = $this->chat($other, $otherUser, $support);
        $this->say($chat, LiveChatMessage::CLIENT, 'not this account', user: $otherUser);

        $this->asStaff($this->staff(self::READER, $support));
        $this->get($this->viewUrl($client, $chat))->assertNotFound()->assertDontSee('not this account');
        $this->get(ClientResource::getUrl('chats', ['record' => $client]) . '/abc')->assertNotFound();
        $this->get(ClientResource::getUrl('chats', ['record' => $client]) . '/999999')->assertNotFound();
        Livewire::test(ShowChats::class, ['record' => $client->getKey()])->assertCanNotSeeTableRecords([$chat]);
        $this->get($this->viewUrl($other, $chat))->assertOk()->assertSee('not this account');
    }

    public function test_what_anyone_wrote_comes_out_escaped_staff_notes_too(): void
    {
        $support = $this->department();
        [$client, $user] = $this->account();
        $user->forceFill(['first_name' => '<b>bold</b>'])->save();
        $chat = $this->chat($client, $user, $support);
        $this->say($chat, LiveChatMessage::CLIENT, '<img src=x onerror=alert(1)> x" onmouseover="alert(1)', user: $user);
        $this->say($chat, LiveChatMessage::SYSTEM, '<script>alert(3)</script>');
        $this->say($chat, LiveChatMessage::STAFF, '<script>alert(2)</script>', staff: $this->staff());

        $this->asStaff($this->staff(self::READER, $support));
        $html = $this->get($this->viewUrl($client, $chat))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<script>alert(2)', $html);
        $this->assertStringNotContainsString('<script>alert(3)', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(3)', $html);
    }

    /** §3 Α: ChatText::render is the one unescaped output of the history's view. */
    public function test_the_history_view_prints_raw_html_only_through_chattext(): void
    {
        $view = (string) file_get_contents(resource_path('views/admin/resources/client-resource/pages/view-chat.blade.php'));
        preg_match_all('/\{!!(.*?)!!\}/s', $view, $raw);
        $this->assertNotEmpty($raw[1]);
        foreach ($raw[1] as $expression) {
            $this->assertMatchesRegularExpression('/^\s*\\\\App\\\\Support\\\\ChatText::render\(\$message->body\)\s*$/', $expression);
        }
    }

    /** F12: reading the history writes nothing, no audit nor any seen or unread state. */
    public function test_opening_the_history_writes_nothing(): void
    {
        $support = $this->department();
        [$client, $user] = $this->account();
        $chat = $this->chat($client, $user, $support, status: LiveChat::ACTIVE);
        $this->say($chat, LiveChatMessage::CLIENT, 'hello', user: $user);
        $reader = $this->staff(self::READER, $support);
        $this->asStaff($reader);
        $before = [Audit::query()->count(), $chat->fresh()?->toArray(), LiveChatMessage::query()->get()->toArray()];

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            // Every admin request keeps the member's own session and «last seen» (StaffSessions): nothing else is written.
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1
                && preg_match('/^\s*(insert\s+into|update|delete\s+from)\s+["`]?(staff|staff_sessions|sessions|cache|cache_locks)["`]?\s/i', $query->sql) !== 1) {
                $writes[] = $query->sql;
            }
        });
        $this->get(ShowChats::getUrl(['record' => $client]))->assertOk();
        Livewire::test(ShowChats::class, ['record' => $client->getKey()])->assertCanSeeTableRecords([$chat]);
        $this->get($this->viewUrl($client, $chat))->assertOk()->assertSee('hello');
        Livewire::test(ViewChat::class, ['record' => $client->getKey(), 'chat' => $chat->id])->call('$refresh')->assertSee('hello');

        $this->assertSame([], $writes, implode("\n", $writes));
        $this->assertSame($before, [Audit::query()->count(), $chat->fresh()?->toArray(), LiveChatMessage::query()->get()->toArray()]);
    }

    public function test_the_tab_label_says_itself_in_english_and_greek(): void
    {
        $this->assertTrue(Lang::has('Chats', 'el', false));
        foreach (['Started by', 'Messages', 'Operator', 'No chats', 'Chat #:id', 'No messages'] as $key) {
            $this->assertTrue(Lang::has($key, 'el', false), $key);
        }
        app()->setLocale('en');
        $this->assertSame('Chats', ShowChats::getNavigationLabel());
        app()->setLocale('el');
        $this->assertSame(json_decode((string) file_get_contents(lang_path('el.json')), true)['Chats'], ShowChats::getNavigationLabel());
        $this->assertSame('Ξεκίνησε από', __('Started by'));
    }
}
