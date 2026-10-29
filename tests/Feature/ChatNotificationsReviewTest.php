<?php

namespace Tests\Feature;

use App\Admin\Resources\ChatNotificationRuleResource\Pages\ListChatNotificationRules;
use App\Jobs\SendChatNotification;
use App\Models\ChatNotificationRule;
use App\Models\DebugLog;
use App\Models\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

/**
 * Independent review of #456 (chat notifications). Each test fails on the PR as it stands and shows one defect.
 */
class ChatNotificationsReviewTest extends TestCase
{
    use RefreshDatabase;

    /** A Mattermost incoming webhook: its secret is the path's key, not a query parameter. */
    private const HOOK = 'https://chat.example.test/hooks/k7xq3mattermostsecret9z';

    private const SECRET = 'k7xq3mattermostsecret9z';

    private function rule(array $events): ChatNotificationRule
    {
        return ChatNotificationRule::query()->create(['name' => 'Ops', 'webhook_url' => self::HOOK, 'events' => $events, 'enabled' => true]);
    }

    /** @return list<string> */
    private function told(): array
    {
        // The fallback and the card, as JSON: a customer's text may reach the chat through either.
        return Queue::pushed(SendChatNotification::class)->map(fn (SendChatNotification $job) => json_encode(['text' => $job->text, 'card' => $job->card],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->values()->all();
    }

    /**
     * R1. With «debug» on, RequestListener logs every HTTP call to DebugLog with Redact::url(). Redact masks query keys,
     * Discord's and Slack's webhook paths and the encrypted Settings, but not a Mattermost hook's path nor the rules'
     * URLs (they are not Settings): the secret lands in the debug log staff and the ops tools read.
     */
    public function test_r1_the_debug_http_log_never_holds_the_webhook_secret(): void
    {
        config(['settings.debug' => true]);
        Http::fake(['chat.example.test/*' => Http::response('ok')]);
        $rule = $this->rule(['invoice_paid']);

        (new SendChatNotification($rule->id, 'hello'))->handle();

        $logged = DebugLog::query()->where('type', 'http')->get()->map(fn (DebugLog $log) => json_encode($log->context))->implode("\n");
        $this->assertNotSame('', $logged, 'the call was logged');
        $this->assertStringNotContainsString(self::SECRET, $logged, 'the webhook secret is in the debug log');
    }

    /**
     * R2. A connection failure (DNS, refused, timeout) throws Laravel's ConnectionException with Guzzle's message, which
     * ends in «for <the full URL>». The job rethrows it: the secret goes to failed_jobs, laravel.log and DebugLog (not
     * masked: R1). The PR says «errors never include the URL»; only the non-2xx path keeps that.
     */
    public function test_r2_a_connection_failure_never_carries_the_url(): void
    {
        Http::fake(['chat.example.test/*' => Http::failedConnection()]);
        $rule = $this->rule(['invoice_paid']);

        try {
            (new SendChatNotification($rule->id, 'hello'))->handle();
            $this->fail('a failed connection is an error');
        } catch (Throwable $e) {
            $this->assertStringNotContainsString(self::SECRET, $e->getMessage(), 'the job\'s exception carries the webhook URL');
        }
    }

    /** R2b. The same message is shown as is in the «Send test» notification: the URL «never shown again» is shown. */
    public function test_r2b_the_send_test_action_never_shows_the_url(): void
    {
        Http::fake(['chat.example.test/*' => Http::failedConnection()]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $staff = Staff::factory()->create(['role_id' => Role::create(['name' => 'All', 'permissions' => ['*']])->id]);
        $rule = $this->rule(['invoice_paid']);

        $this->livewireAs($staff)->test(ListChatNotificationRules::class)->callTableAction('test', $rule);

        app(NotificationMailbox::class)->flush();
        $notifications = new Notifications;
        $notifications->mount();
        $notifications->pullNotificationsFromSession();
        $shown = json_encode($notifications->notifications->map(fn ($notification) => $notification->toArray())->values()->all());
        $this->assertStringContainsString('Not sent', $shown, 'the failure is shown');
        $this->assertStringNotContainsString(self::SECRET, $shown, 'the URL is shown to staff');
    }

    /**
     * R3. Mattermost and Slack expand @channel, @here and @all. A customer's (or any guest's, by mail or the contact form)
     * ticket subject goes into the text as is: anyone can ping the whole staff channel.
     */
    public function test_r3_a_customers_subject_cannot_ping_the_channel(): void
    {
        Queue::fake([SendChatNotification::class]);
        $this->rule(['ticket_opened']);
        $user = User::factory()->create();
        $ticket = Ticket::query()->create(['subject' => 'URGENT @channel @all @here', 'status' => Ticket::STATUS_OPEN, 'priority' => 'high', 'client_id' => $user->client()->id]);
        $ticket->messages()->create(['user_id' => $user->id, 'message' => 'Help']);

        $text = $this->told()[0] ?? '';
        $this->assertNotSame('', $text);
        foreach (['@channel', '@all', '@here'] as $mention) {
            $this->assertStringNotContainsString($mention, $text, "{$mention} reaches the chat as a mention");
        }
    }

    /**
     * R4. Only the link's own text is cleaned (link()); the rest is concatenated raw. A guest's name (the contact form,
     * or the From name of any mail) and a cancellation's reason are the customer's: a markdown link in them is a
     * clickable link in staff's channel, under our bot's name.
     */
    public function test_r4_customer_text_outside_the_link_is_not_markdown(): void
    {
        Queue::fake([SendChatNotification::class]);
        $this->rule(['ticket_opened', 'cancellation_requested']);
        $ticket = Ticket::query()->create(['subject' => 'Invoice question', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium',
            'guest_name' => '[Open the invoice](https://evil.example/login)', 'guest_email' => 'guest@example.test']);
        $ticket->messages()->create(['message' => 'Hi', 'via' => 'form']);

        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);
        $service->cancellation()->create(['reason' => 'see [your panel](https://evil.example/x) @channel', 'type' => 'end_of_period']);

        $told = $this->told();
        $this->assertCount(2, $told, json_encode($told));
        foreach ($told as $text) {
            $this->assertStringNotContainsString('](https://evil.example', $text, 'a customer\'s markdown link reaches the chat');
        }
        $this->assertStringNotContainsString('@channel', $told[1], 'a cancellation reason pings the channel');
    }

    /**
     * R5. The listener reads the status from the event's model, not from the event's own changes (the event's docblock:
     * «Listeners read these instead»). Two status saves of one instance in one transaction (both events fire after the
     * commit, on the same object) are both told as the last status: «Terminated» twice, the suspension never.
     */
    public function test_r5_each_status_change_is_told_as_itself(): void
    {
        Queue::fake([SendChatNotification::class]);
        $this->rule(['service_suspended', 'service_unsuspended', 'service_terminated']);
        $product = $this->createProduct();
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1]);

        DB::transaction(function () use ($service) {
            $service->forceFill(['status' => Service::STATUS_SUSPENDED])->save();
            $service->forceFill(['status' => Service::STATUS_TERMINATED])->save();
        });

        $told = $this->told();
        $this->assertCount(2, $told, json_encode($told));
        $this->assertStringContainsString('A service has been suspended.', $told[0], json_encode($told));
        $this->assertStringContainsString('A service has been terminated.', $told[1]);
    }
}
