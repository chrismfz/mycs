<?php

namespace Tests\Feature;

use App\Helpers\NotificationHelper;
use App\Models\EmailLog;
use App\Models\Server;
use App\Support\Redact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Adversarial review of #355. */
class Adv355LogsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['settings.system_email_address' => 'staff@example.test', 'settings.mail_disable' => false, 'settings.logs_full' => false]);
    }

    /**
     * The system mail's callers escape the body before it is masked (RetriesModuleAction: e("... {$error}")): a stored
     * secret with &, <, >, " or ' is then in the body only HTML-escaped, and the stored secrets are matched raw only.
     */
    public function test_a_stored_secret_with_html_characters_is_masked_in_an_escaped_system_mail(): void
    {
        $server = Server::query()->create(['name' => 'Plesk One', 'extension' => 'Plesk', 'type' => 'server', 'enabled' => true]);
        $server->settings()->create(['key' => 'password', 'value' => 'Root&Pass"2026', 'encrypted' => true, 'type' => 'string']);
        Redact::forget();

        NotificationHelper::sendSystemEmailNotification('Module action failed', e('The server answered: login root / Root&Pass"2026 refused'));

        $body = EmailLog::query()->where('template_key', 'system')->sole()->body;
        $this->assertStringContainsString('The server answered', $body);
        $this->assertStringNotContainsString('Pass&quot;2026', $body, 'the stored password, HTML-escaped, is still in the mail');
    }
}
