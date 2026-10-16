<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Contact;
use App\Models\Gateway;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Closeout before D5: a server's extension never runs on another server's settings (myip has 6–7 WHMs: an empty or
 * half-set server took the first server's credentials, accounts on the wrong WHM); and contacts are audited.
 */
class ExtensionSettingsIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function whm(string $name, array $settings): Server
    {
        $server = Server::query()->create(['name' => $name, 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
        foreach ($settings as $key => $value) {
            $server->settings()->create(['key' => $key, 'value' => $value]);
        }

        return $server;
    }

    public function test_a_server_without_settings_never_uses_another_servers(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1]])]);
        $this->whm('first', ['host' => 'https://first.test:2087', 'username' => 'root', 'apikey' => 'FIRST-KEY']);
        $empty = $this->whm('second', []);

        ExtensionHelper::testConfig($empty, []);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'first.test') || $request->hasHeader('Authorization', 'whm root:FIRST-KEY'));
    }

    public function test_a_server_extension_without_its_servers_settings_reads_none(): void
    {
        Http::preventStrayRequests();
        $this->whm('only', ['host' => 'https://only.test:2087', 'username' => 'root', 'apikey' => 'K']);

        $this->assertStringContainsString("needs its server's settings", (string) ExtensionHelper::getExtension('server', 'CPanel')->testConfig());
        $this->assertStringContainsString('no host set', (string) ExtensionHelper::testConfig(Server::query()->sole(), []));
        Http::assertNothingSent();
    }

    /** Adversarial: the gateways booted at start-up (Stripe/PayPal listeners) have their own settings, not none. */
    public function test_booted_gateways_carry_their_settings(): void
    {
        $gateway = Gateway::query()->create(['name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'stripe_secret_key', 'value' => 'sk_test_x', 'encrypted' => true]);

        $booted = ExtensionHelper::bootable()->firstWhere('extension', 'Stripe');

        $this->assertInstanceOf(Gateway::class, $booted);
        $this->assertSame('sk_test_x', ExtensionHelper::settingsToArray($booted->settings)['stripe_secret_key'] ?? null);
    }

    public function test_a_contact_change_is_audited(): void
    {
        config(['audit.console' => true]);
        $contact = Contact::factory()->create(['client_id' => User::factory()->create()->client()->id, 'first_name' => 'Old']);

        $contact->update(['first_name' => 'New']);

        $this->assertSame('New', $contact->audits()->where('event', 'updated')->latest('id')->value('new_values')['first_name']);
    }
}
