<?php

namespace Tests\Feature;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Others\DiscordNotifications\PostToDiscord;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * The Lows of audit flow 10 (security, code and live): what a server, a registrar or an exception says reaches the
 * staff's notifications as text, and the Discord webhook posts only to Discord.
 */
class AuditFlow10LowsTest extends TestCase
{
    public function test_a_discord_webhook_that_is_not_discords_is_never_posted_to(): void
    {
        Http::fake();

        foreach (['http://discord.com/api/webhooks/1/a', 'https://127.0.0.1/api/webhooks/1/a', 'https://discord.com.evil.test/api/webhooks/1/a',
            'https://169.254.169.254/latest/meta-data/'] as $url) {
            (new PostToDiscord($url, ['content' => 'hi']))->handle();
        }

        Http::assertNothingSent();
    }

    public function test_a_discord_webhook_is_posted_to_without_following_a_redirect(): void
    {
        Http::fake(['https://discord.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/'])]);

        try {
            (new PostToDiscord('https://discord.com/api/webhooks/1/token', ['content' => 'hi']))->handle();
        } catch (RequestException) {
            // a redirect is an answer that is not 2xx: the job fails, nothing follows it
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://discord.com/api/webhooks/1/token');
    }

    /** Filament renders a notification's title and body as HTML (only scripts are removed): outside text goes in escaped. */
    public function test_no_notification_shows_an_exceptions_message_unescaped(): void
    {
        $raw = [];
        foreach ((new Finder)->in([base_path('app'), base_path('extensions')])->name('*.php')->files() as $file) {
            foreach (explode("\n", $file->getContents()) as $n => $line) {
                if (preg_match('/->(title|body)\(\(?(string\) )?\$[a-z]+->(getMessage\(\)|last_error|result)\)/', $line) === 1) {
                    $raw[] = $file->getRelativePathname() . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame([], $raw);
    }
}
