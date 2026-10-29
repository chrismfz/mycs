<?php

namespace Tests\Feature;

use App\Admin\Resources\ChatNotificationRuleResource;
use App\Admin\Resources\ChatNotificationRuleResource\Pages\CreateChatNotificationRule;
use App\Admin\Resources\ChatNotificationRuleResource\Pages\EditChatNotificationRule;
use App\Admin\Resources\ChatNotificationRuleResource\Pages\ListChatNotificationRules;
use App\Admin\Resources\ClientResource;
use App\Admin\Resources\InvoiceResource;
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
use App\Services\Chat\ChatCard;
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

    /** @return list<string> each message queued: its fallback and its card, as JSON */
    private function told(): array
    {
        return Queue::pushed(SendChatNotification::class)->map(fn (SendChatNotification $job) => json_encode(['text' => $job->text, 'card' => $job->card],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->values()->all();
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
        $this->assertCount(2, $told, 'staff\'s reply is told only to a rule that asks for it, a note never');
        $this->assertStringContainsString("#{$ticket->reference} - Site down", $told[0]);
        $this->assertStringContainsString('A new ticket has been opened.', $told[0]);
        $this->assertStringContainsString('"title":"Status","value":"Open"', $told[0]);
        $this->assertStringContainsString('A new reply has been posted by the customer.', $told[1]);
        $this->assertStringContainsString('"title":"Status","value":"Customer reply"', $told[1]);
    }

    public function test_a_staff_reply_is_told_to_a_rule_that_asks_with_who_wrote_it(): void
    {
        $this->rule(['ticket_staff_reply']);
        $user = User::factory()->create();
        $ticket = Ticket::query()->create(['subject' => 'Site down', 'status' => Ticket::STATUS_OPEN, 'priority' => 'high', 'client_id' => $user->client()->id]);
        $ticket->messages()->create(['user_id' => $user->id, 'message' => 'Help']);
        $staff = Staff::factory()->create(['first_name' => 'Maria', 'last_name' => 'Support']);
        $ticket->messages()->create(['staff_id' => $staff->id, 'message' => 'internal', 'is_internal' => true]);
        $ticket->messages()->create(['staff_id' => $staff->id, 'message' => 'On it']);

        $told = $this->told();
        $this->assertCount(1, $told, json_encode($told));
        $this->assertStringContainsString('A new reply has been posted by Maria Support.', $told[0]);
        $this->assertStringContainsString('"title":"Status","value":"Answered"', $told[0]);
        $this->assertStringContainsString('"title":"Priority","value":"High"', $told[0]);
        $this->assertStringContainsString('"color":"' . ChatCard::BLUE . '"', $told[0]);
    }

    public function test_a_guests_ticket_names_the_guest_without_a_link(): void
    {
        $this->rule(['ticket_opened']);
        $ticket = Ticket::query()->create(['subject' => 'Question', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'guest_name' => 'Anna Guest', 'guest_email' => 'anna@example.test']);
        $ticket->messages()->create(['message' => 'Hi', 'via' => 'form']);

        $card = Queue::pushed(SendChatNotification::class)->first()->card;
        $field = collect($card['fields'])->firstWhere('title', 'Guest');
        $this->assertNotNull($field, json_encode($card));
        $this->assertStringContainsString('Anna Guest', $field['value']);
        $this->assertStringNotContainsString('](', $field['value']);
    }

    /** As WHMCS's: the title links to the invoice; client (linked), due date, total, how it was paid, status. */
    public function test_an_invoice_paid_card_has_its_fields_and_links(): void
    {
        $this->rule(['invoice_paid']);
        $user = User::factory()->create();
        $client = $user->client();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'status' => 'unpaid', 'currency_code' => 'USD', 'due_at' => '2026-09-16']);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 10]);
        $invoice->fresh()->transactions()->create(['amount' => 10]);

        $job = Queue::pushed(SendChatNotification::class)->first();
        $this->assertNotNull($job);
        $card = $job->card;
        $invoice->refresh();
        $this->assertSame('Invoice #' . ($invoice->number ?: $invoice->id), $card['title']);
        $this->assertSame(InvoiceResource::getUrl('edit', ['record' => $invoice], panel: 'admin'), $card['title_link']);
        $this->assertSame(ChatCard::GREEN, $card['color']);
        $this->assertSame('An invoice has been paid.', $card['text']);
        $fields = collect($card['fields'])->pluck('value', 'title');
        $this->assertSame(['Client', 'Due Date', 'Total', 'Payment Method', 'Status'], $fields->keys()->all());
        $this->assertStringContainsString('(' . ClientResource::getUrl('view', ['record' => $client], panel: 'admin') . ')', $fields['Client']);
        $this->assertSame('16/09/2026', $fields['Due Date']);
        $this->assertSame('Paid', $fields['Status']);
        $this->assertStringStartsWith('Invoice #', $job->text, 'a plain fallback for phones');
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
        $this->assertStringContainsString('A service has been suspended.', $told[0]);
        $this->assertStringContainsString('"title":"Domain","value":"site.example.test"', $told[0]);
        $this->assertStringContainsString('A service has been unsuspended.', $told[1]);
        $this->assertStringContainsString('A service has been terminated.', $told[2]);
        $this->assertStringContainsString('"color":"' . ChatCard::RED . '"', $told[2]);
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
        $this->assertStringContainsString('An invoice has been paid.', $told[0]);
        $this->assertStringContainsString('A cancellation has been requested.', $told[1]);
        $this->assertStringContainsString('"title":"Type","value":"End of billing period"', $told[1]);
        $this->assertStringContainsString('"title":"Reason","value":"Moving"', $told[1]);
    }

    /** The event's own models are never touched: a relation loaded on them emptied the staff order mail's domains. */
    public function test_an_order_is_told_without_touching_the_events_order(): void
    {
        $this->rule(['order_placed']);
        $order = Order::query()->create(['client_id' => User::factory()->create()->client()->id, 'currency_code' => 'USD']);
        event(new Finalized($order));

        $this->assertSame([], $order->getRelations(), 'no relation loaded on the event\'s order');
        $this->assertStringContainsString('"title":"Order #' . $order->id . '"', $this->told()[0] ?? '');
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
        $card = (new ChatCard('Invoice #7', 'https://admin.example.test/invoices/7', 'An invoice has been paid.', ChatCard::GREEN))->field('Total', '€8.00')->toArray();
        (new SendChatNotification($rule->id, 'Invoice #7 · An invoice has been paid.', $card))->handle();
        Http::assertSent(fn (Request $request) => ($request->data()['attachments'] ?? null) === [$card] && !isset($request->data()['text']));
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
