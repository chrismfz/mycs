<?php

namespace Tests\Feature;

use App\Jobs\SendChatNotification;
use App\Models\ChatNotificationRule;
use App\Models\Staff;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Chat\ChatCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Review of the chat cards (2026-10-29). */
class ChatCardsReviewTest extends TestCase
{
    use RefreshDatabase;

    /** 1. A job queued before the deploy has no «card» in its payload: it must still post its text. */
    public function test_a_job_queued_before_cards_still_sends_its_text(): void
    {
        $rule = ChatNotificationRule::query()->create(['name' => 'Ops', 'webhook_url' => 'https://chat.example.test/hooks/x', 'events' => ['ticket_opened'], 'enabled' => true]);
        Http::fake(['chat.example.test/*' => Http::response('ok', 200)]);

        // The shape a job queued before the cards has: no «card» in its payload.
        $old = 'O:' . strlen(SendChatNotification::class) . ':"' . SendChatNotification::class . '":2:{s:6:"ruleId";i:' . $rule->id . ';s:4:"text";s:5:"hello";}';

        /** @var SendChatNotification $job */
        $job = unserialize($old);
        $job->handle();

        Http::assertSent(fn (Request $request) => ($request->data()['text'] ?? null) === 'hello');
    }

    /** 2. A ticket staff open (or open while impersonating) is told as a new ticket, not as a reply. */
    public function test_a_ticket_opened_by_staff_is_told_as_new(): void
    {
        Queue::fake([SendChatNotification::class]);
        ChatNotificationRule::query()->create(['name' => 'Ops', 'webhook_url' => 'https://chat.example.test/hooks/x', 'events' => ['ticket_staff_reply'], 'enabled' => true]);
        $ticket = Ticket::query()->create(['subject' => 'Your renewal', 'status' => Ticket::STATUS_OPEN, 'priority' => 'medium', 'client_id' => User::factory()->create()->client()->id]);
        $ticket->messages()->create(['staff_id' => Staff::factory()->create(['first_name' => 'Nikos', 'last_name' => 'Staff'])->id, 'message' => 'Hello']);

        $this->assertSame('A new ticket has been opened by Nikos Staff.', Queue::pushed(SendChatNotification::class)->first()?->card['text']);
    }

    /** 3. A long field is cut, never in the middle of an escape: no lone backslash before the ellipsis. */
    public function test_a_long_field_is_never_cut_inside_an_escape(): void
    {
        $value = str_repeat('a', 998) . '\\_more';
        $cut = (new ChatCard('T', null, 'x', ChatCard::GREY))->field('Items', $value)->toArray()['fields'][0]['value'];

        $this->assertSame(str_repeat('a', 998) . '…', $cut);
    }
}
