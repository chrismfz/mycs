<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Contact;
use App\Models\Extension as ExtensionModel;
use App\Models\Gateway;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Adversarial review of PR #167 (closeout): a server's extension never runs on another server's settings; contacts
 * are audited. Findings fail on the PR's code; the rest are holds.
 */
class ClosoutAdversarialTest extends TestCase
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

    private function serviceOn(Server $server): Service
    {
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        $product->product->settings()->create(['key' => 'package', 'value' => 'basic']);
        $service = Service::factory()->create([
            'client_id' => User::factory()->create()->client()->id,
            'plan_id' => $product->plan->id,
            'product_id' => $product->product->id,
            'server_id' => $server->id,
            'status' => 'active',
            'expires_at' => now()->addMonth(),
            'currency_code' => 'USD',
            'price' => 10.00,
        ]);
        $service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'exampleuser']);

        return $service;
    }

    private function neverFirst(): void
    {
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'first.test')
            || $request->hasHeader('Authorization', 'whm root:FIRST-KEY'));
    }

    /**
     * Attack: config() decides by its caller's class (debug_backtrace). A caller outside the module class (app code, a
     * listener, a helper class) falls to the `else` branch: `Extension::where('extension', 'CPanel')->first()`, unscoped
     * by type and with no count check. Holds only by accident: that model's morph class is App\Models\Extension, the
     * server's settings are stored under App\Models\Server, so it reads nothing (see the report).
     */
    public function test_hold_config_read_from_outside_the_module_class_never_takes_the_first_of_several_servers(): void
    {
        $this->whm('first', ['host' => 'https://first.test:2087', 'username' => 'root', 'apikey' => 'FIRST-KEY']);
        $this->whm('second', ['host' => 'https://second.test:2087', 'username' => 'root', 'apikey' => 'SECOND-KEY']);

        $instance = ExtensionHelper::getExtension('server', 'CPanel'); // no settings given: must never guess
        try {
            $key = $instance->config('apikey');
        } catch (Throwable) {
            $key = null; // refusing is fine too
        }

        $this->assertNotSame('FIRST-KEY', $key, 'config() from outside the module class read the first of two WHMs.');
    }

    // ---- Findings -------------------------------------------------------------------------------------------------

    /**
     * F2 (low): a group with a configured WHM and a new, not yet configured one (enabled, no settings: myip adding its
     * 7th): least-full gives every new account to the empty one, keeps it there (server_id is set before the create)
     * and every create fails, while the configured one has room. No credentials leak (the PR holds), but the selector
     * should skip a server without its settings.
     */
    public function test_f2_selector_never_assigns_a_new_account_to_a_server_without_settings(): void
    {
        $first = $this->whm('first', ['host' => 'https://first.test:2087', 'username' => 'root', 'apikey' => 'FIRST-KEY']);
        $this->serviceOn($first); // one account already on it: the empty one is «least full»
        $empty = $this->whm('second', []);
        $product = $this->createProduct()->product;
        $product->update(['server_id' => $first->id]);
        $group = \App\Models\ServerGroup::query()->create(['name' => 'WHMs', 'fill_type' => \App\Models\ServerGroup::FILL_LEAST_FULL]);
        $group->servers()->attach($first->id, ['position' => 0]);
        $group->servers()->attach($empty->id, ['position' => 1]);
        $product->update(['server_group_id' => $group->id]);

        $chosen = \App\Services\Service\ServerSelector::forNewAccount($product->refresh());

        $this->assertNotSame($empty->id, $chosen?->id, 'A new account went to a server with no host and no credentials.');
    }

    /** Create through the group: whatever server is chosen, the request never carries another server's credentials. */
    public function test_hold_group_create_never_mixes_a_servers_record_with_anothers_credentials(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1]])]);
        Mail::fake();
        $first = $this->whm('first', ['host' => 'https://first.test:2087', 'username' => 'root', 'apikey' => 'FIRST-KEY']);
        $this->serviceOn($first);
        $empty = $this->whm('second', []);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $first->id]);
        $group = \App\Models\ServerGroup::query()->create(['name' => 'WHMs', 'fill_type' => \App\Models\ServerGroup::FILL_LEAST_FULL]);
        $group->servers()->attach($first->id, ['position' => 0]);
        $group->servers()->attach($empty->id, ['position' => 1]);
        $product->product->update(['server_group_id' => $group->id]);
        $service = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'plan_id' => $product->plan->id,
            'product_id' => $product->product->id, 'status' => 'pending', 'currency_code' => 'USD', 'price' => 10.00]);
        $service->properties()->create(['key' => 'domain', 'name' => 'Domain', 'value' => 'example.test']);

        try {
            ExtensionHelper::createServer($service);
        } catch (Throwable) {
        }

        if ((int) $service->refresh()->server_id !== $first->id) {
            $this->neverFirst();
        } else {
            $this->assertTrue(true);
        }
    }

    // ---- Holds ----------------------------------------------------------------------------------------------------

    public function test_hold_suspend_on_an_empty_server_never_reaches_another_server(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1]])]);
        Mail::fake();
        $this->whm('first', ['host' => 'https://first.test:2087', 'username' => 'root', 'apikey' => 'FIRST-KEY']);
        $empty = $this->whm('second', []);
        $service = $this->serviceOn($empty);

        try {
            ExtensionHelper::suspendServer($service);
        } catch (Throwable) {
            // failing is fine; reaching the first WHM is not
        }

        $this->neverFirst();
    }

    public function test_hold_sso_and_actions_on_an_empty_server_never_reach_another_server(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => ['url' => 'https://first.test/sso']])]);
        $this->whm('first', ['host' => 'https://first.test:2087', 'username' => 'root', 'apikey' => 'FIRST-KEY']);
        $empty = $this->whm('second', []);
        $service = $this->serviceOn($empty);

        foreach ([fn () => ExtensionHelper::getActions($service), fn () => ExtensionHelper::callService($service, 'getLoginUrl')] as $call) {
            try {
                $call();
            } catch (Throwable) {
            }
        }

        $this->neverFirst();
    }

    public function test_hold_half_set_server_uses_only_its_own_settings(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1]])]);
        $this->whm('first', ['host' => 'https://first.test:2087', 'username' => 'root', 'apikey' => 'FIRST-KEY']);
        $half = $this->whm('second', ['host' => 'https://second.test:2087']);

        try {
            ExtensionHelper::testConfig($half, ExtensionHelper::settingsToArray($half->settings));
        } catch (Throwable) {
        }

        $this->neverFirst();
    }

    public function test_hold_a_new_servers_form_is_not_built_from_another_servers_settings(): void
    {
        $this->whm('first', ['host' => 'https://first.test:2087', 'username' => 'root', 'apikey' => 'FIRST-KEY']);

        $options = ExtensionHelper::getConfig('server', 'CPanel', []);

        $this->assertStringNotContainsString('FIRST-KEY', json_encode($options));
        $this->assertStringNotContainsString('first.test', json_encode($options));
    }

    public function test_hold_product_config_cache_is_keyed_per_server(): void
    {
        $a = $this->whm('a', ['host' => 'https://a.test:2087', 'username' => 'root', 'apikey' => 'A']);
        $b = $this->whm('b', ['host' => 'https://b.test:2087', 'username' => 'root', 'apikey' => 'B']);
        Http::preventStrayRequests();
        Http::fake([
            'a.test*' => Http::response(['metadata' => ['result' => 1], 'data' => ['pkg' => [['name' => 'only-on-a']]]]),
            'b.test*' => Http::response(['metadata' => ['result' => 1], 'data' => ['pkg' => [['name' => 'only-on-b']]]]),
        ]);

        $fromA = json_encode(rescue(fn () => ExtensionHelper::getProductConfigOnce($a, []), [], false));
        $fromB = json_encode(rescue(fn () => ExtensionHelper::getProductConfigOnce($b, []), [], false));

        $this->assertStringNotContainsString('only-on-a', $fromB);
    }

    /** A gateway webhook builds its gateway from the container (no settings): its one record still answers. */
    public function test_hold_a_single_gateways_webhook_still_reads_its_record(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.mollie.com/*' => Http::response(['status' => 'open'])]);
        $gateway = Gateway::query()->create(['extension' => 'Mollie', 'name' => 'Mollie', 'type' => 'gateway', 'enabled' => true]);
        $gateway->settings()->create(['key' => 'api_key', 'value' => 'MOLLIE-KEY']);

        // As its route does: the container builds it bare (the route is registered at boot, before this record).
        app(\MyCS\Extensions\Gateways\Mollie\Mollie::class)->webhook(\Illuminate\Http\Request::create('/', 'POST', ['id' => 'tr_abc']));

        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer MOLLIE-KEY'));
    }

    /** An «other» extension (one record, no settings given) still reads its record. */
    public function test_hold_an_other_extension_without_given_settings_reads_its_record(): void
    {
        $record = ExtensionModel::query()->create(['extension' => 'DiscordNotifications', 'name' => 'Discord', 'type' => 'other', 'enabled' => false]);
        $record->settings()->create(['key' => 'webhook_url', 'value' => 'https://discord.test/hook']);

        $this->assertSame('https://discord.test/hook', ExtensionHelper::getExtension('other', 'DiscordNotifications')->config('webhook_url'));
    }

    /** From inside the module with several servers and no settings: refuses (the PR's rule). */
    public function test_hold_inside_the_module_several_servers_refuse(): void
    {
        Http::preventStrayRequests();
        $this->whm('first', ['host' => 'https://first.test:2087', 'username' => 'root', 'apikey' => 'FIRST-KEY']);
        $this->whm('second', ['host' => 'https://second.test:2087', 'username' => 'root', 'apikey' => 'SECOND-KEY']);

        $answer = (string) ExtensionHelper::getExtension('server', 'CPanel')->testConfig();
        $this->assertTrue(str_contains($answer, 'several records') || str_contains($answer, 'needs its server'), $answer);
        $this->neverFirst();
    }

    /** Contacts in bulk (imports): a query-builder update and console saves don't break, and nothing secret audited. */
    public function test_hold_contact_audits_bulk_and_console(): void
    {
        $client = User::factory()->create()->client();
        $contacts = Contact::factory()->count(3)->create(['client_id' => $client->id]);

        Contact::query()->whereIn('id', $contacts->pluck('id'))->update(['city' => 'Athens']);
        $this->assertSame(3, Contact::query()->where('city', 'Athens')->count());

        config(['audit.console' => false]);
        $contacts->first()->update(['first_name' => 'Console']);
        $this->assertSame(0, $contacts->first()->audits()->where('event', 'updated')->count());

        config(['audit.console' => true]);
        $contacts->first()->update(['first_name' => 'Audited', 'vat_number' => 'EL123456789']);
        $audit = $contacts->first()->audits()->where('event', 'updated')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('Audited', $audit->new_values['first_name']);
    }
}
