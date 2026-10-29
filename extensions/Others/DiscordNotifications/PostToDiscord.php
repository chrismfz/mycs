<?php

namespace MyCS\Extensions\Others\DiscordNotifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/**
 * Posts one message to the staff's Discord channel. Encrypted on the queue (review F4 of Σ4): the webhook URL is a
 * credential (whoever has it posts into the channel) and the message names customers; neither lies in clear in `jobs`
 * or `failed_jobs`.
 */
class PostToDiscord implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Discord's webhook URLs; a saved URL that is not one is never posted to, and no redirect is followed. */
    public const WEBHOOK = '#^https://(ptb\.|canary\.)?discord(app)?\.com/api/webhooks/[^/?\#]+/[^/?\#]+$#';

    public int $tries = 3;

    /** @var list<int> seconds */
    public array $backoff = [60, 600];

    /** @param  array<string, mixed>  $message */
    public function __construct(private string $webhookUrl, private array $message) {}

    public function handle(): void
    {
        if (preg_match(self::WEBHOOK, $this->webhookUrl) !== 1) {
            $this->fail(new \RuntimeException('Discord: the webhook URL is not a Discord webhook; nothing was posted.'));

            return;
        }

        Http::withoutRedirecting()->post($this->webhookUrl, $this->message)->throw();
    }
}
