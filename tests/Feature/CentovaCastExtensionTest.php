<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Livewire\Services\Show;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use App\Support\CurrentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use MyCS\Extensions\Servers\CentovaCast\CentovaApi;
use MyCS\Extensions\Servers\CentovaCast\CentovaCast;
use Tests\TestCase;

/**
 * Centova Cast (owner 2026-10-22: myip's radio stations on stream.myip.gr), as Centova's own WHMCS module: an account
 * from the product's template, suspend disables it (and unsuspend undoes only that), terminate disables unless the
 * product says delete, the panel, its password, plan limits and usage, over its XML API.
 */
class CentovaCastExtensionTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://stream.test:2199';

    private User $owner;

    private Service $service;

    private Server $server;

    /** @var array<string, array<string, string>> accounts at Centova, by username */
    private array $accounts = [];

    /** @var list<array{0: string, 1: string, 2: array<string, string>}> class, method, arguments */
    private array $calls = [];

    /** @var array<string, string> method => Centova's refusal */
    private array $refuse = [];

    private bool $down = false;

    /** Centova makes the account, then answers an error (code review 3 of #329). */
    private bool $madeThenRefused = false;

    /** listaccounts rows with no email (only id, username and password are promised: code review 4). */
    private bool $bareRows = false;

    private bool $enabledUnreadable = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['settings.default_currency' => 'USD']);
        $this->server = Server::query()->create(['name' => 'stream', 'extension' => 'CentovaCast', 'type' => 'server', 'enabled' => true]);
        foreach (['host' => self::API, 'username' => 'admin', 'password' => 'admin-secret'] as $key => $value) {
            $this->server->settings()->create(['key' => $key, 'value' => $value]);
        }
        $this->owner = User::factory()->create(['last_name' => 'Ραδιόφωνο', 'email' => 'station@example.test']);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $this->server->id]);
        $product->product->settings()->create(['key' => 'template', 'value' => 'icecast100']);
        $this->service = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'server_id' => $this->server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addYear()]);
        Http::fake(fn (Request $request) => $this->centova($request));
    }

    private static function xml(string $type, string $data = '', string $message = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><centovacast version="3.2.12"><response type="' . $type . '"><message>' . $message . '</message>'
            . ($data !== '' ? "<data>{$data}</data>" : '') . '</response></centovacast>';
    }

    private static function fields(array $row): string
    {
        // `organization` raw, as Centova writes it (HTML entities and all).
        return implode('', array_map(fn ($k, $v) => "<{$k}>" . ($k === 'organization' ? (string) $v : htmlspecialchars((string) $v, ENT_XML1)) . "</{$k}>", array_keys($row), $row));
    }

    private function centova(Request $request)
    {
        if ($this->down) {
            throw new ConnectionException('cURL error 28 for https://stream.test:2199/api.php admin-secret');
        }
        $this->assertSame(self::API . '/api.php', $request->url());
        $xml = simplexml_load_string($request->body());
        [$class, $method] = [(string) $xml->request['class'], (string) $xml->request['method']];
        $args = [];
        foreach ($xml->request->children() as $name => $value) {
            $args[$name] = (string) $value;
        }
        $this->calls[] = [$class, $method, $args];
        $this->assertSame($class === 'system' ? 'admin-secret' : 'admin|admin-secret', $args['password'], 'the admin\'s password, as Centova\'s module sends it');
        if ($this->madeThenRefused && $method === 'provision') {
            $this->accounts[$args['username']] = ['username' => $args['username'], 'email' => $args['email'], 'status' => 'enabled'];

            return Http::response(self::xml('error', '', 'Could not start the server'));
        }
        if (isset($this->refuse[$method])) {
            return Http::response(self::xml('error', '', $this->refuse[$method]));
        }
        $user = $args['username'] ?? '';
        $ok = Http::response(self::xml('success', '', 'OK'));

        return match ("{$class}.{$method}") {
            'system.listaccounts' => Http::response(self::xml('success', implode('', array_map(fn ($a) => '<row>' . self::fields($a) . '</row>',
                array_map(fn ($a) => $this->bareRows ? ['username' => $a['username']] : $a, array_filter($this->accounts, fn ($a) => str_contains($a['username'], $args['filter'] ?? ''))))))),
            'system.provision' => tap(Http::response(self::xml('success', '<account>' . self::fields(['username' => $user, 'ipaddress' => '10.0.0.5', 'port' => '9250', 'servertype' => 'IceCast', 'sourcetype' => 'liquidsoap']) . '</account>')),
                fn () => $this->accounts[$user] = ['username' => $user, 'email' => $args['email'], 'status' => 'enabled', 'adminpassword' => $args['adminpassword']]),
            'system.setstatus' => tap($ok, fn () => $this->accounts[$user]['status'] = $args['status']),
            'system.terminate' => tap($ok, function () use ($user) {
                unset($this->accounts[$user]);
            }),
            'system.info' => Http::response(self::xml('success', implode('', array_map(fn ($a) => '<row>' . self::fields(['username' => $a['username'], 'state' => 'up', 'expected' => 'up']) . '</row>', $this->accounts)))),
            'system.usage' => Http::response(self::xml('success', '<row>' . self::fields(['username' => 'radio', 'diskquota' => 1000, 'transferlimit' => -1, 'diskusage' => 120, 'transferusage' => 4500]) . '</row>')),
            'server.enabled' => $this->enabledUnreadable ? Http::response(self::xml('success', '<row><username>radio</username></row>')) : Http::response(self::xml('success', // as Centova 3.3 answers it live: each value in a <field>
                '<enabled><field>' . (($this->accounts[$user]['status'] ?? '') === 'enabled' ? 1 : 0) . "</field> </enabled> <username><field>{$user}</field> </username> <reseller><field>0</field> </reseller>")),
            'server.getaccount' => Http::response(self::xml('success', '<account>' . self::fields($this->accounts[$user] + ['maxclients' => 100, 'title' => 'Old & Gold']) . '</account>')),
            'server.reconfigure' => tap($ok, fn () => $this->accounts[$user] = array_merge($this->accounts[$user], array_diff_key($args, ['password' => 1]))),
            default => $ok,
        };
    }

    /** @return list<array<string, string>> the arguments of each call of this method */
    private function sent(string $method): array
    {
        return array_values(array_map(fn ($c) => $c[2], array_filter($this->calls, fn ($c) => "{$c[0]}.{$c[1]}" === $method)));
    }

    private function made(string $username = 'radio'): void
    {
        $this->accounts[$username] = ['username' => $username, 'email' => 'station@example.test', 'status' => 'enabled', 'adminpassword' => 'Old-Pass-123'];
        $this->service->properties()->create(['key' => 'centova_username', 'name' => 'Centova account', 'value' => $username]);
        $this->service->properties()->create(['key' => 'centova_port', 'name' => 'Centova port', 'value' => '9250']);
        $this->service->forceFill(['username' => $username, 'password' => 'Old-Pass-123'])->save();
    }

    public function test_create_provisions_from_the_template_and_keeps_the_account(): void
    {
        ExtensionHelper::createServer($this->service->fresh());

        [$provision] = $this->sent('system.provision');
        $this->assertSame(['icecast100', 'radiofono', 'station@example.test', 'auto', '1'],
            [$provision['template'], $provision['username'], $provision['email'], $provision['port'], $provision['autostart']]);
        $this->assertArrayNotHasKey('maxclients', $provision, 'a blank limit is the template\'s');
        $service = $this->service->fresh();
        $this->assertSame(['radiofono', $provision['adminpassword']], [$service->username, $service->password], 'the password kept, encrypted');
        $this->assertNotSame($provision['adminpassword'], $provision['sourcepassword']);
        $properties = $service->properties()->pluck('value', 'key')->all();
        $this->assertSame(['radiofono', '9250', 'IceCast'], [$properties['centova_username'], $properties['centova_port'], $properties['centova_servertype']]);
        $this->assertArrayNotHasKey(CentovaCast::PENDING, $properties);
    }

    public function test_a_taken_name_gets_two_digits_and_a_retry_adopts_only_its_own(): void
    {
        $this->accounts['radiofono'] = ['username' => 'radiofono', 'email' => 'someone@else.test', 'status' => 'enabled'];
        ExtensionHelper::createServer($this->service->fresh());
        $this->assertSame('radiofono01', $this->sent('system.provision')[0]['username']);

        // A lost answer: the name kept, the account made with this account's email: adopted, nothing provisioned again.
        $this->service->properties()->delete();
        $this->service->forceFill(['username' => null])->save();
        $this->service->properties()->create(['key' => CentovaCast::PENDING, 'name' => 'p', 'value' => 'radiofono01']);
        ExtensionHelper::createServer($this->service->fresh());
        $this->assertCount(1, $this->sent('system.provision'));
        $this->assertSame('radiofono01', $this->service->fresh()->username);
    }

    public function test_a_kept_name_now_someone_elses_is_given_up(): void
    {
        $this->accounts['radiofono'] = ['username' => 'radiofono', 'email' => 'someone@else.test', 'status' => 'enabled'];
        $this->service->properties()->create(['key' => CentovaCast::PENDING, 'name' => 'p', 'value' => 'radiofono']);

        ExtensionHelper::createServer($this->service->fresh());

        $this->assertSame('radiofono01', $this->sent('system.provision')[0]['username']);
        $this->assertSame('someone@else.test', $this->accounts['radiofono']['email'], 'never adopted');
    }

    public function test_a_name_another_service_keeps_for_its_create_is_never_taken_nor_adopted(): void
    {
        // Service A's provision never reached Centova: it keeps «radiofono». B (same client) must not take it, and A's
        // retry must not adopt B's account (code review 2 of #329).
        $a = $this->service->replicate();
        $a->save();
        $a->properties()->create(['key' => CentovaCast::PENDING, 'name' => 'p', 'value' => 'radiofono']);

        ExtensionHelper::createServer($this->service->fresh());
        $this->assertSame('radiofono01', $this->service->fresh()->username);

        $this->service->forceFill(['username' => 'radiofono'])->save(); // even when B has the name itself
        $this->accounts['radiofono'] = ['username' => 'radiofono', 'email' => 'station@example.test', 'status' => 'enabled'];
        ExtensionHelper::createServer($a->fresh());
        $this->assertNotSame('radiofono', $a->fresh()->username);
    }

    public function test_an_error_after_the_account_was_made_keeps_it_and_makes_no_second(): void
    {
        $this->madeThenRefused = true;
        $this->bareRows = true; // and the email read from getaccount

        ExtensionHelper::createServer($this->service->fresh());

        $this->assertCount(1, $this->sent('system.provision'));
        $this->assertSame('radiofono', $this->service->fresh()->username);
        $this->assertCount(1, $this->sent('server.getaccount'));
    }

    public function test_suspend_never_guesses_whether_the_account_is_enabled(): void
    {
        $this->made();
        $this->enabledUnreadable = true;

        $this->assertFalse(rescue(fn () => ExtensionHelper::suspendServer($this->service->fresh()), false, false));
        $this->assertNull($this->service->properties()->where('key', CentovaCast::DISABLED)->value('value'));
    }

    public function test_a_refused_provision_takes_the_kept_name_off(): void
    {
        $this->refuse['provision'] = 'Template icecast100 does not exist';

        $this->assertFalse(rescue(fn () => ExtensionHelper::createServer($this->service->fresh()), false, false));

        $this->assertNull($this->service->properties()->where('key', CentovaCast::PENDING)->value('value'));
        $this->assertNull($this->service->fresh()->username);
    }

    public function test_suspend_disables_and_unsuspend_restores_only_what_it_did(): void
    {
        $this->made();

        ExtensionHelper::suspendServer($this->service->fresh());
        $this->assertSame('disabled', $this->accounts['radio']['status']);
        $this->assertSame('up', $this->service->properties()->where('key', CentovaCast::DISABLED)->value('value'));

        ExtensionHelper::unsuspendServer($this->service->fresh());
        $this->assertSame('enabled', $this->accounts['radio']['status']);
        $this->assertCount(1, $this->sent('server.start'), 'its stream was up: started again');
        $this->assertNull($this->service->properties()->where('key', CentovaCast::DISABLED)->value('value'));
    }

    public function test_an_account_disabled_by_hand_is_never_enabled_by_unsuspend(): void
    {
        $this->made();
        $this->accounts['radio']['status'] = 'disabled';

        ExtensionHelper::suspendServer($this->service->fresh());
        ExtensionHelper::unsuspendServer($this->service->fresh());

        $this->assertSame('disabled', $this->accounts['radio']['status']);
        $this->assertSame([], $this->sent('system.setstatus'));
    }

    public function test_terminate_disables_by_default_and_deletes_only_when_the_product_says_so(): void
    {
        $this->made();

        ExtensionHelper::terminateServer($this->service->fresh());
        $this->assertSame('disabled', $this->accounts['radio']['status'], 'its media kept');
        $this->assertSame([], $this->sent('system.terminate'));

        $this->accounts['radio']['status'] = 'enabled';
        $this->service->forceFill(['status' => 'active'])->save();
        $this->service->product->settings()->create(['key' => 'terminate_mode', 'value' => CentovaCast::DELETE]);
        ExtensionHelper::terminateServer($this->service->fresh());
        $this->assertSame([['username' => 'radio']], array_map(fn ($a) => array_diff_key($a, ['password' => 1]), $this->sent('system.terminate')));
        $this->assertNull($this->service->fresh()->username);
    }

    public function test_never_an_account_another_service_claims_or_centova_lacks(): void
    {
        $this->made();
        $other = $this->service->replicate();
        $other->save();

        $this->assertFalse(rescue(fn () => ExtensionHelper::terminateServer($this->service->fresh()), false, false));
        $other->forceFill(['username' => 'other'])->save();
        unset($this->accounts['radio']);
        $this->assertFalse(rescue(fn () => ExtensionHelper::suspendServer($this->service->fresh()), false, false));

        $this->assertSame([], $this->sent('system.setstatus'));
    }

    public function test_a_plan_change_sends_its_limits_and_a_template_only_one_is_told(): void
    {
        $this->made();
        $this->assertFalse(rescue(fn () => ExtensionHelper::upgradeServer($this->service->fresh()), false, false));
        $this->assertSame([], $this->sent('server.reconfigure'));

        foreach (['maxclients' => '200', 'maxbitrate' => '128', 'mountlimit' => '0'] as $key => $value) {
            $this->service->product->settings()->create(['key' => $key, 'value' => $value]);
        }
        ExtensionHelper::upgradeServer($this->service->fresh());
        [$sent] = $this->sent('server.reconfigure');
        $this->assertSame(['200', '128', '1'], [$sent['maxclients'], $sent['maxbitrate'], $sent['mountlimit']]);
    }

    public function test_a_new_password_is_sent_with_the_account_and_a_refusal_keeps_the_old(): void
    {
        $this->made();
        RateLimiter::clear('service-password:' . $this->service->id);
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));
        $page = Livewire::test(Show::class, ['service' => $this->service]);
        $this->assertFalse($page->get('passwordHasMysql'));

        $page->set('newPassword', 'N3w-Strong-Pass')->set('newPasswordConfirmation', 'N3w-Strong-Pass')->call('changeServicePassword');
        [$sent] = $this->sent('server.reconfigure');
        $this->assertSame(['username', 'password', 'adminpassword'], array_keys($sent), 'the password alone, the account never sent back');
        $this->assertSame('N3w-Strong-Pass', $sent['adminpassword']);
        $this->assertSame('N3w-Strong-Pass', $this->service->fresh()->password);

        $this->refuse['reconfigure'] = 'Password too short';
        $page->set('newPassword', 'An0ther-Strong-Pass')->set('newPasswordConfirmation', 'An0ther-Strong-Pass')->call('changeServicePassword')
            ->assertDispatched('notify', fn ($name, $params) => str_contains((string) ($params[0]['message'] ?? ''), 'Password too short'));
        $this->assertSame('N3w-Strong-Pass', $this->service->fresh()->password);
    }

    public function test_the_page_shows_the_stream_and_the_panel_link(): void
    {
        $this->made();
        $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));

        $page = Livewire::test(Show::class, ['service' => $this->service]);
        $page->assertSee('stream.test:9250')->assertSee('radio');
        $page->call('goto', 'getLoginUrl')->assertRedirect(self::API . '/login/index.php');
    }

    public function test_html_entities_in_an_answer_are_read_as_their_characters(): void
    {
        // As stream answered live (2026-10-22): a Greek organization in HTML's named entities, not known to XML.
        $this->made();
        $this->accounts['radio']['organization'] = '&Iota;&Mu;&Phi; &amp; &Theta;&nbsp;x &bogus; 5 &lt; 6 Rock & Roll &x; &#913;';

        ExtensionHelper::suspendServer($this->service->fresh());

        $this->assertSame('disabled', $this->accounts['radio']['status'], 'the account found in the listaccounts answer');
        $read = (new \ReflectionMethod(CentovaApi::class, 'parse'))->invoke(null,
            '<centovacast><response type="success"><message>OK</message><data><row><organization>&Iota;&Mu;&Phi; &amp; &Theta;&nbsp;x &bogus; 5 &lt; 6 Rock & Roll &x; &#913;</organization></row></data></response></centovacast>');
        $this->assertSame("ΙΜΦ & Θ\u{00A0}x &bogus; 5 < 6 Rock & Roll &x; Α", $read[2]['row']['organization']);
    }

    public function test_usage_from_one_call(): void
    {
        $this->made();

        $this->artisan('services:usage')->assertExitCode(0);

        $usage = $this->service->usage()->sole();
        $this->assertSame([120, 1000, 4500, null], [$usage->disk_used_mb, $usage->disk_limit_mb, $usage->bandwidth_used_mb, $usage->bandwidth_limit_mb]);
        $this->assertCount(1, $this->sent('system.usage'));
    }

    public function test_the_connection_test_and_a_failure_never_tells_the_password(): void
    {
        $extension = new CentovaCast(['host' => self::API, 'username' => 'admin', 'password' => 'admin-secret']);
        $this->assertTrue($extension->testConfig());

        $this->refuse['version'] = 'Invalid password';
        $this->assertSame('Centova Cast: Invalid password', $extension->testConfig());

        $this->down = true;
        $this->assertStringNotContainsString('admin-secret', (string) $extension->testConfig());
    }
}
