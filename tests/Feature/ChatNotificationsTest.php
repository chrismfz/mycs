<?php

namespace Tests\Feature;

use App\Admin\Resources\ChatNotificationRuleResource;
use App\Admin\Resources\ChatNotificationRuleResource\Pages\CreateChatNotificationRule;
use App\Admin\Resources\ChatNotificationRuleResource\Pages\EditChatNotificationRule;
use App\Admin\Resources\ChatNotificationRuleResource\Pages\ListChatNotificationRules;
use App\Events\Order\Finalized;
use App\Jobs\SendChatNotification;
use App\Models\ChatNotificationRule;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/** Staff told of events in Mattermost or Slack (WHMCS «Notifications»; sweep of myip, 2026-10-25). */
class ChatNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const HOOK = 'https://chat.example.test/hooks/abc123secret';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([SendChatNotification::class]);
    }

    private function rule(array $events, bool $enabled = true): ChatNotificationRule
    {
        return ChatNotificationRule::query()->create(['name' => 'Ops', 'webhook_url' => self::HOOK, 'events' => $events, 'enabled' => $enabled]);
    }

    /** @return list<string> the texts queued */
    private function told(): array
    {
        return Queue::pushed(SendChatNotification::class)->map(fn (SendChatNotification $job) => $job->text)->values()->all();
    }

    public function test_a_customers_new_ticket_and_reply_are_told_and_staff_replies_and_notes_are_not(): void
    {
        $this->rule(['ticket_opened', 'ticket_reply']);
        $user = User::factory()->create();
        $ticket = Ticket::query()->create(['subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'high', 'client_id' => $user->client()->id]);
        $ticket->messages()->create(['user_id' => $user->id, 'message' => 'Help']);
        $staff = Staff::factory()->create();
        $ticket->messages()->create(['staff_id' => $staff->id, 'message' => 'On it']);
        $ticket->messages()->create(['staff_id' => $staff->id, 'message' => 'internal', 'is_internal' => true]);
        $ticket->messages()->create(['user_id' => $user->id, 'message' => 'Thanks']);

        $told = $this->told();
        $this->assertCount(2, $told);
        $this->assertStringContainsString("New ticket [#{$ticket->id} Site down]", $told[0]);
        $this->assertStringContainsString('Customer reply on', $told[1]);
    }

    public function test_a_service_suspended_unsuspended_and_terminated(): void
    {
        $this->rule(['service_suspended', 'service_unsuspended', 'service_terminated']);
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'domain' => 'site.example.test']);

        foreach ([Service::STATUS_SUSPENDED, Service::STATUS_ACTIVE, Service::STATUS_TERMINATED] as $status) {
            DB::transaction(fn () => $service->fresh()->forceFill(['status' => $status])->save());
        }

        $told = $this->told();
        $this->assertCount(3, $told, json_encode($told));
        $this->assertStringStartsWith('⏸️ Suspended', $told[0]);
        $this->assertStringContainsString('(site.example.test)', $told[0]);
        $this->assertStringStartsWith('▶️ Unsuspended', $told[1]);
        $this->assertStringStartsWith('⛔ Terminated', $told[2]);
    }

    public function test_an_invoice_paid_and_a_cancellation_requested(): void
    {
        $this->rule(['invoice_paid', 'cancellation_requested']);
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => 'unpaid', 'currency_code' => 'USD']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $invoice->fresh()->transactions()->create(['amount' => 10]);
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        $service->cancellation()->create(['reason' => 'Moving', 'type' => 'end_of_period']);

        $told = $this->told();
        $this->assertCount(2, $told, json_encode($told));
        $this->assertStringContainsString('paid', $told[0]);
        $this->assertStringContainsString('Cancellation requested (end_of_period)', $told[1]);
        $this->assertStringContainsString('«Moving»', $told[1]);
    }

    /** The event's own models are never touched: a relation loaded on them emptied the staff order mail's domains. */
    public function test_an_order_is_told_without_touching_the_events_order(): void
    {
        $this->rule(['order_placed']);
        $order = Order::query()->create(['client_id' => User::factory()->create()->client()->id, 'currency_code' => 'USD']);
        event(new Finalized($order));

        $this->assertSame([], $order->getRelations(), 'no relation loaded on the event\'s order');
        $this->assertStringContainsString('New order [#' . $order->id . ']', $this->told()[0] ?? '');
    }

    public function test_only_the_rules_that_ask_and_are_enabled(): void
    {
        $this->rule(['invoice_paid']);
        $this->rule(['ticket_opened'], enabled: false);
        $user = User::factory()->create();
        $ticket = Ticket::query()->create(['subject' => 'Q', 'status' => Ticket::STATUS_OPEN, 'priority' => 'low', 'client_id' => $user->client()->id]);
        $ticket->messages()->create(['user_id' => $user->id, 'message' => 'Hi']);

        $this->assertSame([], $this->told());
    }

    public function test_the_job_posts_to_the_webhook_https_only_and_never_says_its_url(): void
    {
        Http::fake(['chat.example.test/*' => Http::response('ok')]);
        $rule = $this->rule(['invoice_paid']);

        (new SendChatNotification($rule->id, 'hello'))->handle();
        Http::assertSent(fn (Request $request) => $request->url() === self::HOOK && $request['text'] === 'hello' && $request['username'] === 'MyCS');
        $this->assertStringNotContainsString('abc123secret', serialize(new SendChatNotification($rule->id, 'x')), 'the job carries the id, not the URL');

        Http::fake(['chat.example.test/*' => Http::response('no', 403)]);
        try {
            SendChatNotification::post(self::HOOK, 'x');
            $this->fail('a refusal is an error');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('abc123secret', $e->getMessage());
        }
        $this->expectExceptionMessage('https://');
        SendChatNotification::post('http://chat.example.test/hooks/x', 'x');
    }

    public function test_staff_set_up_a_rule_keep_its_secret_and_send_a_test(): void
    {
        Http::fake(['chat.example.test/*' => Http::response('ok')]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);

        $this->livewireAs($staff)->test(CreateChatNotificationRule::class)
            ->fillForm(['name' => 'Billing', 'webhook_url' => self::HOOK, 'events' => ['invoice_paid', 'order_placed'], 'enabled' => true])
            ->call('create')->assertHasNoFormErrors();
        $rule = ChatNotificationRule::query()->sole();
        $this->assertNotSame(self::HOOK, DB::table('chat_notification_rules')->value('webhook_url'), 'encrypted at rest');

        $this->livewireAs($staff)->test(EditChatNotificationRule::class, ['record' => $rule->getRouteKey()])
            ->assertFormSet(['webhook_url' => null])
            ->fillForm(['name' => 'Billing 2', 'webhook_url' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertSame(self::HOOK, $rule->fresh()->webhook_url, 'left empty: kept');

        $this->livewireAs($staff)->test(ListChatNotificationRules::class)->callTableAction('test', $rule)->assertNotified();
        Http::assertSent(fn (Request $request) => str_contains((string) $request['text'], 'Billing 2'));

        $this->livewireAs($staff)->test(CreateChatNotificationRule::class)
            ->fillForm(['name' => 'Plain', 'webhook_url' => 'http://chat.example.test/x', 'events' => ['invoice_paid']])
            ->call('create')->assertHasFormErrors(['webhook_url']);
    }

    public function test_staff_without_the_permission_see_nothing(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'Few', 'permissions' => ['admin.tickets.viewAny']])->id]);

        $this->actingAs($staff, 'staff')->get(ChatNotificationRuleResource::getUrl())->assertForbidden();
    }
}
