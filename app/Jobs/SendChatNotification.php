<?php

namespace App\Jobs;

use App\Models\ChatNotificationRule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * One message to one rule's incoming webhook ({"text": …}: Mattermost and Slack alike). The job carries the rule's id,
 * not its URL (the secret is read when sent, never queued or logged). https only, no redirects.
 */
class SendChatNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 20;

    public function __construct(public int $ruleId, public string $text) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $rule = ChatNotificationRule::query()->find($this->ruleId);
        if ($rule === null || !$rule->enabled) {
            return;
        }
        self::post((string) $rule->webhook_url, $this->text);
    }

    /** Posts the text; throws on anything but a 2xx, without the URL in the message. */
    public static function post(string $url, string $text): void
    {
        if (!str_starts_with(strtolower(trim($url)), 'https://')) {
            throw new RuntimeException('The chat webhook must be an https:// URL.');
        }
        try {
            $response = Http::withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(10)
                ->asJson()->post(trim($url), ['text' => mb_substr($text, 0, 4000), 'username' => 'MyCS']);
        } catch (Throwable) {
            // Its message names the URL (the secret): a fixed one instead, nothing chained (review of #456).
            throw new RuntimeException('The chat could not be reached (connection failed or timed out).');
        }
        if (!$response->successful()) {
            throw new RuntimeException('The chat answered HTTP ' . $response->status() . '.');
        }
    }
}
