<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Admin\Resources\ExtensionResource\Pages\EditExtension;
use App\Admin\Resources\ServerResource\Pages\EditServer;
use App\Helpers\ExtensionHelper;
use App\Helpers\NotificationHelper;
use App\Models\DebugLog;
use App\Models\EmailLog;
use App\Models\Extension;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Staff;
use App\Models\User;
use App\Support\Redact;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Adversarial security review of fix/secrets-exposure (sweep §6). Every test here fails on 6929663: each one is a way a
 * secret still reaches the browser, a log, or stays where the PR says it no longer is.
 */
class SecretsExposureReviewTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Redact::forget();
        $user = User::factory()->create();
        $product = $this->createProduct();
        $this->service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'quantity' => 1, 'expires_at' => now()->addMonth(),
            'username' => 'examplegr', 'password' => 'placeholder-pw']);
    }

    private function staff(array $permissions = ['*']): Staff
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Staff::factory()->create(['role_id' => Role::create(['name' => 'Staff ' . uniqid(), 'permissions' => $permissions])->id]);
    }

    private function server(string $extension, array $settings): Server
    {
        $server = Server::query()->create(['name' => $extension . uniqid(), 'extension' => $extension, 'type' => 'server', 'enabled' => true]);
        ExtensionHelper::saveSettings($server, $settings);

        return $server->fresh();
    }

    private function welcomeLogFor(string $password): string
    {
        Mail::fake();
        config(['settings.mail_disable' => false]);
        $this->service->forceFill(['password' => $password])->save();
        NotificationHelper::serverCreatedNotification($this->service->client, $this->service->fresh());

        return (string) EmailLog::query()->latest('id')->value('body');
    }

    /**
     * R1. The welcome template puts {{ service_password }} in Markdown after HTML-escaping it. A password with both kinds
     * of quote comes out as neither its raw form nor e() of it (Markdown turns &#039; back into '), so Redact::values
     * misses it and the email log keeps it.
     */
    public function test_r1_a_password_with_quotes_stays_in_the_email_log(): void
    {
        $log = $this->welcomeLogFor('Qx"7\'kLmn9');

        $this->assertStringNotContainsString('7\'kLmn9', $log, 'the password is in the email log');
    }

    /**
     * R1b. Markdown rewrites a password with Markdown punctuation (Str::password's symbols include * _ \ [ ] `): the
     * log has the rewritten form, which is the password to anyone who reads it.
     */
    public function test_r1b_a_password_with_markdown_punctuation_stays_in_the_email_log(): void
    {
        $log = $this->welcomeLogFor('Kp\\*Zr8w\\_Tq');

        $this->assertStringNotContainsString('Kp*Zr8w_Tq', $log, 'the (Markdown-unescaped) password is in the email log');
    }

    /**
     * R2. DiscordNotifications' webhook URL is a bearer secret (anyone with it posts as the bot) but is not `encrypted`:
     * the Edit Extension page still sends it to the browser.
     */
    public function test_r2_the_discord_webhook_url_reaches_the_extension_form(): void
    {
        $extension = Extension::query()->create(['name' => 'DiscordNotifications', 'extension' => 'DiscordNotifications', 'type' => 'other', 'enabled' => false]);
        ExtensionHelper::saveSettings($extension, ['webhook_url' => 'https://discord.com/api/webhooks/1234/WebhookT0kenSecretValue', 'ping_type' => 'none', 'events' => []]);

        $page = $this->livewireAs($this->staff())->test(EditExtension::class, ['record' => $extension->getRouteKey()]);

        $this->assertStringNotContainsString('WebhookT0kenSecretValue', json_encode($page->snapshot), 'the webhook secret went to the browser');
    }

    /**
     * R3. The same webhook's token is in the URL's path: Redact::url masks only the query, and the value is not a stored
     * (encrypted) secret, so the debug log keeps it.
     */
    public function test_r3_the_discord_webhook_token_stays_in_the_debug_log(): void
    {
        config(['settings.debug' => true]);
        Http::fake(['*' => Http::response('', 204)]);

        Http::post('https://discord.com/api/webhooks/1234/WebhookT0kenSecretValue', ['content' => 'hi']);

        $this->assertStringNotContainsString('WebhookT0kenSecretValue', json_encode(DebugLog::query()->sole()->context));
    }

    /**
     * R4. Core secrets that are not `encrypted` (captcha secret, OAuth client secrets) are still filled into the Settings
     * page, also for staff who may only view the settings.
     */
    public function test_r4_core_secrets_not_marked_encrypted_reach_the_settings_page(): void
    {
        foreach (['captcha_secret' => 'captcha-secret-0001', 'oauth_github_client_secret' => 'gh-client-secret-0002'] as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key, 'settingable_type' => null], ['value' => $value, 'type' => 'string']);
        }

        $page = $this->livewireAs($this->staff(['admin.settings.view']))->test(Settings::class);

        $snapshot = json_encode($page->snapshot);
        $this->assertStringNotContainsString('captcha-secret-0001', $snapshot);
        $this->assertStringNotContainsString('gh-client-secret-0002', $snapshot);
    }

    /**
     * R6. The stored secret is used against whatever host the form has now: a staff member who may edit a server but
     * was never meant to read its root key changes the host to one they control and presses «Test connection».
     */
    public function test_r6_test_connection_sends_the_stored_secret_to_a_new_host(): void
    {
        $server = $this->server('DirectAdmin', ['host' => 'https://da.example.test:2222', 'username' => 'admin', 'password' => 'da-root-pw-777']);
        Http::fake(['*' => Http::response('list[]=a')]);

        $this->livewireAs($this->staff())->test(EditServer::class, ['record' => $server->getRouteKey()])
            ->set('data.settings.host', 'https://attacker.example.test')
            ->callAction(TestAction::make('Test Configuration')->schemaComponent('extension', 'form'));

        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://attacker.example.test')
            && str_contains((string) base64_decode(substr((string) $request->header('Authorization')[0] ?? '', 6)), 'da-root-pw-777'));
    }

    /**
     * R8. Redact keeps the stored secrets once per process; a secret changed by another process (the web request that
     * saves a server, while the queue worker that provisions runs on) is never learnt, so the worker's debug log keeps
     * the new key in clear.
     */
    public function test_r8_a_long_running_process_never_learns_a_changed_secret(): void
    {
        $server = $this->server('Virtualizor', ['ip' => 'panel.example.test', 'port' => '4085', 'key' => 'OldVirtKey-0001', 'password' => 'OldVirtPass-0002']);
        $this->assertSame(Redact::MASK, Redact::text('OldVirtPass-0002'), 'precondition: known');

        // Another process (no model event here) rotates the password.
        DB::table('settings')->where('settingable_id', $server->id)->where('key', 'password')
            ->update(['value' => Crypt::encryptString('NewVirtPass-0003')]);

        $this->travel(61)->seconds(); // the fix: read again after a minute (Redact::KNOWN_TTL)
        $this->assertSame(Redact::MASK, Redact::text('NewVirtPass-0003'), 'the new secret is logged in clear by this process');
    }

    /**
     * R10. The Settings page empties a secret from its state once saved (AUTH-12); the server form keeps what was typed
     * in the Livewire snapshot after the save.
     */
    public function test_r10_a_secret_typed_into_the_server_form_stays_in_the_page_after_save(): void
    {
        $server = $this->server('DirectAdmin', ['host' => 'https://da.example.test:2222', 'username' => 'admin', 'password' => 'da-admin-pw']);

        $page = $this->livewireAs($this->staff())->test(EditServer::class, ['record' => $server->getRouteKey()])
            ->set('data.settings.password', 'typed-new-pw-55')->call('save')->assertHasNoErrors();

        $this->assertSame('typed-new-pw-55', ExtensionHelper::settingsToArray($server->fresh()->settings)['password'], 'precondition: saved');
        $this->assertStringNotContainsString('typed-new-pw-55', json_encode($page->snapshot));
    }

    /**
     * R12. Redact::url rebuilds the query with parse_str/http_build_query: what the debug log shows is not the request
     * that was made (dots become underscores, a repeated parameter is lost), and a key rule this wide masks plain data
     * (key_length, public keys, keyboard).
     */
    public function test_r12_redact_changes_what_was_sent_and_masks_plain_data(): void
    {
        $this->assertSame('https://panel.example.test/api?a.b=1&id=1&id=2', Redact::url('https://panel.example.test/api?a.b=1&id=1&id=2'));
        $this->assertSame(['key_length' => 16, 'keyboard' => 'us'], Redact::array(['key_length' => 16, 'keyboard' => 'us']));
    }
}
