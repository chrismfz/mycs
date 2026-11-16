<?php

namespace Tests\Feature;

use App\Admin\Resources\ClientResource\Pages\ViewChat;
use App\Enums\StaffPresenceStatus;
use App\Livewire\Admin\ChatQueue;
use App\Livewire\Admin\CustomerChat;
use App\Livewire\Admin\StaffChatNotifier;
use App\Livewire\Client\ChatBubble;
use App\Models\Client;
use App\Models\LiveChat;
use App\Models\LiveChatMessage;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\TicketDepartment;
use App\Models\User;
use App\Support\CurrentClient;
use App\Support\LiveChats;
use App\Support\StaffPresence;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Live chat, step 3β part 1: transfer to a colleague or a department, and internal notes (docs/livechat.md §7, §7α). */
class LiveChatTransferNotesTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = ['admin.chats.view', 'admin.chats.reply', 'admin.staff_chat.view', 'admin.staff_online.view', 'admin.clients.viewAny', 'admin.clients.view'];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::query()->updateOrCreate(['key' => 'livechat_enabled', 'settingable_type' => null], ['value' => '1', 'type' => 'boolean']);
        config(['settings.livechat_enabled' => true]);
    }

    private function department(string $name = 'Support', array $attributes = []): TicketDepartment
    {
        return TicketDepartment::query()->create(['name' => $name] + $attributes);
    }

    private function operator(TicketDepartment ...$departments): Staff
    {
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'O' . uniqid(), 'permissions' => self::OPERATOR])->id]);
        foreach ($departments as $department) {
            $department->chatStaff()->attach($staff->id);
        }

        return $staff;
    }

    private function online(Staff $staff): Staff
    {
        StaffPresence::setStatus($staff, StaffPresenceStatus::Available);
        StaffPresence::heartbeat($staff);

        return $staff;
    }

    /** @return array{User, Client} */
    private function customer(): array
    {
        $user = User::factory()->create();
        $client = $user->client();
        $this->asCustomer($user, $client);

        return [$user, $client];
    }

    private function asCustomer(User $user, Client $client): void
    {
        $this->actingAs($user, 'web');
        app(CurrentClient::class)->switchTo($client->id);
    }

    private function asStaff(Staff $staff): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($staff, 'staff');
    }

    /** A customer's chat in $department, taken by $operator. @return array{LiveChat, User, Client} */
    private function activeChat(TicketDepartment $department, Staff $operator, string $first = 'hi'): array
    {
        [$user, $client] = $this->customer();
        $this->assertNull(LiveChats::start($user, $department->id, $first));
        $chat = LiveChat::query()->latest('id')->firstOrFail();
        $this->assertTrue(LiveChats::claim($operator, $chat));

        return [$chat->fresh(), $user, $client];
    }

    public function test_a_transfer_to_a_colleague_of_the_department_keeps_it_active_and_rings_them(): void
    {
        $support = $this->department();
        $from = $this->online($this->operator($support));
        $to = $this->online($this->operator($support));
        [$chat, $user, $client] = $this->activeChat($support, $from);
        $before = LiveChats::alerts($to)['message_latest'];

        $this->asStaff($from);
        Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->assertSee($to->name)
            ->set('transferTo', 'staff:' . $to->id)->call('transfer')->assertHasNoErrors()->assertDispatched('customer-chat-open');

        $chat->refresh();
        $this->assertSame([LiveChat::ACTIVE, $to->id, $support->id], [$chat->status, $chat->assigned_to, $chat->department_id]);
        $note = LiveChatMessage::query()->where('author_type', LiveChatMessage::SYSTEM)->sole();
        $this->assertSame(__('Transferred from :from to :to.', ['from' => $from->name, 'to' => $to->name]), $note->body);

        $alerts = LiveChats::alerts($to);
        $this->assertGreaterThan($before, $alerts['message_latest']);
        $this->assertSame(__('A chat was transferred to you'), $alerts['message_title']);
        $this->assertSame(0, LiveChats::alerts($from)['message_latest'], 'no longer theirs');

        $this->assertNotNull(LiveChats::postAsStaff($from, $chat, 'still me?'));
        $this->assertNull(LiveChats::postAsStaff($to, $chat, 'I have it now'));

        $this->asCustomer($user, $client);
        Livewire::test(ChatBubble::class)->call('toggle')->assertSee('I have it now')->assertDontSee('Transferred');
    }

    public function test_a_transfer_to_another_department_puts_it_back_in_that_queue_and_rings_there(): void
    {
        $support = $this->department('Support');
        $sales = $this->department('Sales');
        $from = $this->online($this->operator($support));
        $seller = $this->online($this->operator($sales));
        // A newer chat already rung in Sales: the transferred one is older by id, and must ring all the same.
        [$older] = $this->activeChat($support, $from, 'old one');
        [$newer] = $this->customer();
        LiveChats::start($newer, $sales->id, 'new one');
        $rung = LiveChats::alerts($seller)['chat_latest'];

        $this->assertNull(LiveChats::transfer($from, $older, toDepartmentId: $sales->id));

        $older->refresh();
        $this->assertSame([LiveChat::WAITING, null, $sales->id], [$older->status, $older->assigned_to, $older->department_id]);
        $alerts = LiveChats::alerts($seller);
        $this->assertGreaterThan($rung, $alerts['chat_latest']);
        $this->assertSame(__('New chat: :department', ['department' => 'Sales']), $alerts['chat_title']);
        $this->assertSame(2, $alerts['waiting']);

        $this->asStaff($seller);
        Livewire::test(ChatQueue::class)->call('claim', $older->id)->assertHasNoErrors();
        $this->assertSame($seller->id, $older->fresh()->assigned_to);
        $this->assertFalse(LiveChats::canSee($from, $older->fresh()), 'out of the old department\'s sight (F3)');
    }

    public function test_a_transfer_to_someone_outside_the_department_or_offline_or_not_an_operator_is_refused(): void
    {
        $support = $this->department('Support');
        $sales = $this->department('Sales');
        $from = $this->online($this->operator($support));
        $outsider = $this->online($this->operator($sales));
        $offline = $this->operator($support);
        $reader = $this->online(Staff::factory()->create(['role_id' => Role::create(['name' => 'R' . uniqid(), 'permissions' => ['admin.chats.view']])->id]));
        $support->chatStaff()->attach($reader->id);
        [$chat] = $this->activeChat($support, $from);

        foreach ([$outsider, $offline, $reader, $from] as $target) {
            $this->assertNotNull(LiveChats::transfer($from, $chat, toStaffId: $target->id), "refused: {$target->email}");
        }
        $this->assertNotNull(LiveChats::transfer($from, $chat, toStaffId: 999999));

        $this->asStaff($from);
        Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->assertDontSee($outsider->name)->assertDontSee($offline->name)
            ->set('transferTo', 'staff:' . $outsider->id)->call('transfer')->assertHasErrors('transfer')
            ->set('transferTo', 'nonsense')->call('transfer')->assertHasErrors('transfer');

        $chat->refresh();
        $this->assertSame([LiveChat::ACTIVE, $from->id, $support->id], [$chat->status, $chat->assigned_to, $chat->department_id]);
        $this->assertSame(0, LiveChatMessage::query()->where('author_type', LiveChatMessage::SYSTEM)->count());
    }

    public function test_a_transfer_to_the_same_a_hidden_or_an_empty_department_is_refused(): void
    {
        $support = $this->department('Support');
        $hidden = $this->department('Internal', ['hidden' => true]);
        $empty = $this->department('Nobody');
        $from = $this->online($this->operator($support, $hidden));
        [$chat] = $this->activeChat($support, $from);

        foreach ([$support, $hidden, $empty] as $department) {
            $this->assertNotNull(LiveChats::transfer($from, $chat, toDepartmentId: $department->id), $department->name);
        }
        $this->assertNotNull(LiveChats::transfer($from, $chat), 'no target');
        $this->assertNotNull(LiveChats::transfer($from, $chat, toStaffId: 1, toDepartmentId: 1), 'two targets');
        $this->assertSame($support->id, $chat->fresh()->department_id);
    }

    public function test_only_the_operator_who_holds_an_active_chat_transfers_it(): void
    {
        $support = $this->department('Support');
        $sales = $this->department('Sales');
        $this->online($this->operator($sales));
        $holder = $this->online($this->operator($support));
        $colleague = $this->online($this->operator($support));
        [$user] = $this->customer();
        LiveChats::start($user, $support->id, 'waiting');
        $waiting = LiveChat::query()->sole();

        $this->assertNotNull(LiveChats::transfer($holder, $waiting, toDepartmentId: $sales->id), 'a waiting chat is taken, not transferred');
        LiveChats::claim($holder, $waiting);
        $this->assertNotNull(LiveChats::transfer($colleague, $waiting, toDepartmentId: $sales->id), 'not theirs');
        $this->assertNotNull(LiveChats::transfer($colleague, $waiting, toStaffId: $colleague->id), 'not theirs to take');
        $this->assertSame($holder->id, $waiting->fresh()->assigned_to);
    }

    /**
     * Runs $other once, right after the next request reads the chat: what a second request landing between this one's
     * read and its update does (F16).
     */
    private function meanwhile(callable $other): void
    {
        $done = false;
        LiveChat::retrieved(function () use (&$done, $other) {
            if (!$done) {
                $done = true;
                $other();
            }
        });
    }

    /** F16: two transfers of one chat at once: one wins, the other changes nothing and writes no note. */
    public function test_two_concurrent_transfers_one_wins(): void
    {
        $support = $this->department('Support');
        $sales = $this->department('Sales');
        $from = $this->online($this->operator($support));
        $colleague = $this->online($this->operator($support));
        $this->online($this->operator($sales));
        [$chat] = $this->activeChat($support, $from);

        $this->meanwhile(fn () => $this->assertNull(LiveChats::transfer($from, LiveChat::query()->findOrFail($chat->id), toStaffId: $colleague->id)));
        $this->assertSame(__('This chat changed in the meantime.'), LiveChats::transfer($from, $chat, toDepartmentId: $sales->id));

        $chat->refresh();
        $this->assertSame([LiveChat::ACTIVE, $colleague->id, $support->id], [$chat->status, $chat->assigned_to, $chat->department_id]);
        $this->assertSame(1, LiveChatMessage::query()->where('author_type', LiveChatMessage::SYSTEM)->count());
    }

    /** F16: a claim that read the chat waiting in one department does not land after a transfer moved it to another. */
    public function test_a_claim_read_before_a_department_transfer_does_not_land_after_it(): void
    {
        $support = $this->department('Support');
        $sales = $this->department('Sales');
        $first = $this->online($this->operator($support));
        $late = $this->online($this->operator($support));
        $this->online($this->operator($sales));
        [$user] = $this->customer();
        LiveChats::start($user, $support->id, 'hi');
        $chat = LiveChat::query()->sole();

        // $late reads it waiting in Support; meanwhile $first takes it and sends it to Sales, where it waits again.
        $this->meanwhile(function () use ($first, $chat, $sales) {
            $this->assertTrue(LiveChats::claim($first, LiveChat::query()->findOrFail($chat->id)));
            $this->assertNull(LiveChats::transfer($first, LiveChat::query()->findOrFail($chat->id), toDepartmentId: $sales->id));
        });
        $this->assertFalse(LiveChats::claim($late, $chat));

        $chat->refresh();
        $this->assertSame([LiveChat::WAITING, null, $sales->id], [$chat->status, $chat->assigned_to, $chat->department_id]);
    }

    /** F16: a close that read the chat before a transfer does not close it from under its new operator. */
    public function test_a_close_read_before_a_transfer_does_not_close_the_new_operators_chat(): void
    {
        $support = $this->department();
        $from = $this->online($this->operator($support));
        $to = $this->online($this->operator($support));
        [$chat] = $this->activeChat($support, $from);

        $this->meanwhile(fn () => $this->assertNull(LiveChats::transfer($from, LiveChat::query()->findOrFail($chat->id), toStaffId: $to->id)));
        $this->assertFalse(LiveChats::closeAsStaff($from, $chat));
        $this->assertSame([LiveChat::ACTIVE, $to->id], [$chat->fresh()->status, $chat->fresh()->assigned_to]);
    }

    public function test_a_note_is_seen_by_the_staff_and_never_by_the_customer(): void
    {
        $support = $this->department();
        $operator = $this->online($this->operator($support));
        $colleague = $this->operator($support);
        [$chat, $user, $client] = $this->activeChat($support, $operator, 'my site is down');

        $this->asStaff($operator);
        Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->set('note', 'VIP, check the backups first')->call('addNote')
            ->assertHasNoErrors()->assertSee('VIP, check the backups first')->assertSee(__('Internal note'));
        // A colleague of the department, who does not hold the chat, may leave a note too; they still may not reply.
        $this->assertNull(LiveChats::note($colleague, $chat, 'I spoke to them yesterday'));
        $this->assertNotNull(LiveChats::postAsStaff($colleague, $chat, 'hello'));
        LiveChats::postAsStaff($operator, $chat, 'Looking now');

        $note = LiveChatMessage::query()->where('author_type', LiveChatMessage::NOTE)->firstOrFail();
        $this->assertSame($operator->id, $note->staff_id);

        $this->asCustomer($user, $client);
        $bubble = Livewire::test(ChatBubble::class)->call('toggle')->assertSee('Looking now')->assertSee('my site is down');
        foreach (['VIP, check the backups', 'I spoke to them', __('Internal note')] as $hidden) {
            $bubble->assertDontSee($hidden);
        }
        $this->assertSame(['my site is down', 'Looking now'], LiveChats::customerMessages($chat)->pluck('body')->all());
        // On every later poll too, and with the staff's own notes filling the window.
        for ($i = 0; $i < LiveChats::WINDOW + 5; $i++) {
            $chat->messages()->create(['author_type' => LiveChatMessage::NOTE, 'staff_id' => $operator->id, 'body' => 'note ' . $i]);
        }
        $this->assertSame(['my site is down', 'Looking now'], LiveChats::customerMessages($chat)->pluck('body')->all());
        $bubble->call('$refresh')->assertDontSee('note 1')->assertSee('Looking now');

        $this->asStaff($operator);
        $this->get(ViewChat::getUrl(['record' => $client, 'chat' => $chat->id]))->assertOk()
            ->assertSee('VIP, check the backups first')->assertSee('I spoke to them yesterday')->assertSee(__('Internal note'));
    }

    public function test_the_customer_sees_only_client_and_staff_messages_whatever_else_is_stored(): void
    {
        $support = $this->department();
        $operator = $this->online($this->operator($support));
        [$chat] = $this->activeChat($support, $operator, 'mine');
        foreach ([LiveChatMessage::SYSTEM, LiveChatMessage::NOTE, 'future-kind'] as $type) {
            $chat->messages()->create(['author_type' => $type, 'staff_id' => $operator->id, 'body' => 'staff only ' . $type]);
        }

        $this->assertSame(['mine'], LiveChats::customerMessages($chat)->pluck('body')->all());
    }

    public function test_notes_follow_the_department_the_open_chat_and_the_rate_limit(): void
    {
        $support = $this->department('Support');
        $sales = $this->department('Sales');
        $operator = $this->online($this->operator($support));
        $outsider = $this->operator($sales);
        [$chat] = $this->activeChat($support, $operator);

        $this->assertNotNull(LiveChats::note($outsider, $chat, 'peek'), 'outside the department (F3)');
        $this->assertNotNull(LiveChats::note($operator, $chat, "  \n "), 'empty');
        for ($i = 0; $i < LiveChats::PER_MINUTE * 2; $i++) {
            $this->assertNull(LiveChats::note($operator, $chat, 'n' . $i));
        }
        $this->assertNotNull(LiveChats::note($operator, $chat, 'one too many'));
        $this->assertNotNull(LiveChats::postAsStaff($operator, $chat, 'shared limit'), 'notes and messages share the limit');

        LiveChats::closeAsStaff($operator, $chat);
        $this->travel(2)->minutes();
        $this->assertNotNull(LiveChats::note($operator, $chat, 'after close'));
        $this->assertSame(0, LiveChatMessage::query()->where('body', 'after close')->count());
    }

    public function test_a_note_does_not_ring_and_the_customers_message_still_does(): void
    {
        $support = $this->department();
        $operator = $this->online($this->operator($support));
        $colleague = $this->operator($support);
        [$chat, $user, $client] = $this->activeChat($support, $operator);
        $rung = LiveChats::alerts($operator)['message_latest'];

        LiveChats::note($colleague, $chat, 'fyi');
        $this->assertSame($rung, LiveChats::alerts($operator)['message_latest']);

        $this->asCustomer($user, $client);
        LiveChats::postAsCustomer($user, $chat, 'hello?');
        $this->assertSame(__('New message in a customer chat'), LiveChats::alerts($operator)['message_title']);
        $this->assertGreaterThan($rung, LiveChats::alerts($operator)['message_latest']);
    }

    public function test_notes_and_transfer_names_come_out_escaped(): void
    {
        $support = $this->department('<b>Sup</b>');
        $sales = $this->department('Sales <img src=x onerror=alert(3)>');
        $operator = $this->online($this->operator($support));
        $operator->forceFill(['first_name' => '<script>alert(4)</script>'])->save();
        $seller = $this->online($this->operator($sales));
        [$chat, , $client] = $this->activeChat($support, $operator);
        LiveChats::note($operator, $chat, '<img src=x onerror=alert(1)> x" onmouseover="alert(2)');

        $this->asStaff($operator);
        $console = Livewire::test(CustomerChat::class, ['chatId' => $chat->id])->html();
        $this->assertNull(LiveChats::transfer($operator->fresh(), $chat, toDepartmentId: $sales->id));
        // Now in Sales: read by its operators (F3).
        $this->asStaff($seller);
        $tab = $this->get(ViewChat::getUrl(['record' => $client, 'chat' => $chat->id]))->assertOk()->getContent();
        $notifier = Livewire::test(StaffChatNotifier::class)->html();

        foreach ([$console, (string) $tab, $notifier] as $html) {
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringNotContainsString('<script>alert(4)', $html);
        }
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $console);
        $this->assertStringContainsString('&lt;script&gt;alert(4)&lt;/script&gt;', (string) $tab);
    }
}
