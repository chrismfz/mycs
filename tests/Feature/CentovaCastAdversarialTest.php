<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use MyCS\Extensions\Servers\CentovaCast\CentovaCast;
use Tests\TestCase;

/**
 * Adversarial review of #329 (Centova Cast). Each ADV-n test fails on the code as reviewed; the REG tests are attacks
 * that turned out safe, kept as regression tests.
 */
class CentovaCastAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://stream.test:2199';

    private User $owner;

    private Service $service;

    private Server $server;

    /** @var array<string, array<string, string>> accounts at Centova, by username, in Centova's own order */
    private array $accounts = [];

    /** @var list<array{0: string, 1: string, 2: array<string, string>}> */
    private array $calls = [];

    /** server.enabled answered as Centova documents it: «one data row». */
    private bool $enabledAsRow = false;

    /** listaccounts with only the documented fields (id, username, password): no email. */
    private bool $listMinimal = false;

    /** A raw body for server.getaccount, instead of the account. */
    private ?string $getaccountBody = null;

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
        return implode('', array_map(fn ($k, $v) => "<{$k}>" . htmlspecialchars((string) $v, ENT_XML1) . "</{$k}>", array_keys($row), $row));
    }

    private function centova(Request $request)
    {
        $xml = simplexml_load_string($request->body());
        [$class, $method] = [(string) $xml->request['class'], (string) $xml->request['method']];
        $args = [];
        foreach ($xml->request->children() as $name => $value) {
            $args[$name] = (string) $value;
        }
        $this->calls[] = [$class, $method, $args];
        $user = $args['username'] ?? '';
        $ok = Http::response(self::xml('success', '', 'OK'));

        return match ("{$class}.{$method}") {
            'system.listaccounts' => Http::response(self::xml('success', implode('', array_map(
                fn ($a) => '<row>' . self::fields($this->listMinimal ? ['id' => '7', 'username' => $a['username'], 'password' => '$1$hash'] : $a) . '</row>',
                array_slice(array_values(array_filter($this->accounts, fn ($a) => str_contains($a['username'], $args['filter'] ?? ''))),
                    (int) ($args['start'] ?? 0), (int) ($args['limit'] ?? 100)),
            )))),
            'system.provision' => tap(Http::response(self::xml('success', '<account>' . self::fields(['username' => $user, 'port' => '9250', 'servertype' => 'IceCast']) . '</account>')),
                fn () => $this->accounts[$user] = ['username' => $user, 'email' => $args['email'], 'status' => 'enabled']),
            'system.setstatus' => tap($ok, fn () => $this->accounts[$user]['status'] = $args['status']),
            'system.terminate' => tap($ok, function () use ($user) {
                unset($this->accounts[$user]);
            }),
            'system.info' => Http::response(self::xml('success', implode('', array_map(fn ($a) => '<row>' . self::fields(['username' => $a['username'], 'state' => 'up', 'expected' => 'up']) . '</row>', $this->accounts)))),
            'server.enabled' => (function () use ($user) {
                $row = self::fields(['enabled' => ($this->accounts[$user]['status'] ?? '') === 'enabled' ? 1 : 0, 'username' => $user, 'reseller' => 0]);

                return Http::response(self::xml('success', $this->enabledAsRow ? "<row>{$row}</row>" : $row));
            })(),
            'server.getaccount' => Http::response($this->getaccountBody ?? self::xml('success', '<account>' . self::fields($this->accounts[$user] + ['maxclients' => 100]) . '</account>')),
            'server.reconfigure' => tap($ok, fn () => $this->accounts[$user] = array_merge($this->accounts[$user], array_diff_key($args, ['password' => 1]))),
            default => $ok,
        };
    }

    /** @return list<array<string, string>> */
    private function sent(string $method): array
    {
        return array_values(array_map(fn ($c) => $c[2], array_filter($this->calls, fn ($c) => "{$c[0]}.{$c[1]}" === $method)));
    }

    private function made(Service $service, string $username): void
    {
        $this->accounts[$username] = ['username' => $username, 'email' => 'station@example.test', 'status' => 'enabled'];
        $service->properties()->create(['key' => 'centova_username', 'name' => 'Centova account', 'value' => $username]);
        $service->forceFill(['username' => $username, 'password' => 'Old-Pass-123'])->save();
    }

    private function sibling(array $attributes = []): Service
    {
        $other = $this->service->replicate()->fill(['username' => null] + $attributes);
        $other->save();

        return $other;
    }

    /**
     * ADV-1 (high). Centova documents server.enabled as «one data row» (<data><row><enabled>…</row></data>). The code
     * reads `enabled` at the top of <data> and, not finding it, takes the account as enabled: an account staff disabled
     * by hand is marked as disabled-by-suspend, and unsuspend then enables it.
     */
    public function test_adv1_an_account_disabled_by_hand_stays_disabled_when_enabled_answers_as_a_row(): void
    {
        $this->enabledAsRow = true;
        $this->made($this->service, 'radio');
        $this->accounts['radio']['status'] = 'disabled'; // staff, by hand (abuse, a DMCA notice)

        ExtensionHelper::suspendServer($this->service->fresh());
        ExtensionHelper::unsuspendServer($this->service->fresh());

        $this->assertSame('disabled', $this->accounts['radio']['status'], 'unsuspend enabled an account staff disabled by hand');
        $this->assertNull($this->service->properties()->where('key', CentovaCast::DISABLED)->value('value'));
    }

    /**
     * ADV-2 (medium). A retry after a lost answer adopts the kept name whenever Centova has it with this client's email,
     * without asking whether another live service here already has that account (two stations of one client created at
     * once: one took the name, the other's answer was lost). Two services then bill for one station.
     */
    public function test_adv2_a_retry_never_adopts_an_account_another_live_service_has(): void
    {
        $this->made($this->service, 'radiofono');
        $other = $this->sibling();
        $other->properties()->create(['key' => CentovaCast::PENDING, 'name' => 'p', 'value' => 'radiofono']);

        rescue(fn () => ExtensionHelper::createServer($other->fresh()), null, false);

        $this->assertNotSame('radiofono', $other->fresh()->username, 'the second service adopted the first one\'s station');
    }

    /**
     * ADV-3 (low). A free name is free at Centova and on services.username, but not among the names other services are
     * creating right now (PENDING): two creates at once pick the same name, the precondition of ADV-2.
     */
    public function test_adv3_a_name_another_service_is_creating_is_not_free(): void
    {
        $other = $this->sibling();
        $other->properties()->create(['key' => CentovaCast::PENDING, 'name' => 'p', 'value' => 'radiofono']);

        ExtensionHelper::createServer($this->service->fresh());

        $this->assertNotSame('radiofono', $this->sent('system.provision')[0]['username']);
    }

    /**
     * ADV-4 (medium). owned() trims the service's own name but compares the others' names as stored: a live service with
     * « radio» (an import, a typed name) also acts on «radio» (username() trims it), yet the «radio» service never sees
     * it, so its terminate (delete) wipes the station the other one claims.
     */
    public function test_adv4_a_sibling_with_a_padded_name_still_blocks_actions(): void
    {
        $this->made($this->service, 'radio');
        $this->sibling(['username' => ' radio']);
        $this->service->product->settings()->create(['key' => 'terminate_mode', 'value' => CentovaCast::DELETE]);

        rescue(fn () => ExtensionHelper::terminateServer($this->service->fresh()), null, false);

        $this->assertSame([], $this->sent('system.terminate'), 'deleted a station another live service claims');
        $this->assertArrayHasKey('radio', $this->accounts);
    }

    /**
     * ADV-5 (medium). Adoption reads the email from listaccounts, but Centova documents only id, username and password
     * there (other fields «nonstandard, unsupported»). Without an email no lost answer is ever adopted: the retry gives the
     * name up and provisions a second station, the first one left orphaned (and billed nowhere).
     */
    public function test_adv5_a_lost_answer_is_adopted_when_listaccounts_has_no_email(): void
    {
        $this->listMinimal = true;
        $this->accounts['radiofono'] = ['username' => 'radiofono', 'email' => 'station@example.test', 'status' => 'enabled'];
        $this->service->properties()->create(['key' => CentovaCast::PENDING, 'name' => 'p', 'value' => 'radiofono']);

        rescue(fn () => ExtensionHelper::createServer($this->service->fresh()), null, false);

        $this->assertSame([], $this->sent('system.provision'), 'a second station provisioned, the first orphaned');
    }

    /**
     * ADV-6 (low). account() reads one page of 100 rows of a keyword filter. With 100 accounts matching the keyword
     * before it, the service's own account is not found: suspend fails, and freeUsername() would take a used name as
     * free (a create refused for ever).
     */
    public function test_adv6_the_account_is_found_past_the_first_hundred_matches(): void
    {
        foreach (range(1, 100) as $i) {
            $this->accounts["xfm{$i}"] = ['username' => "xfm{$i}", 'email' => "s{$i}@example.test", 'status' => 'enabled'];
        }
        $this->made($this->service, 'fm');

        rescue(fn () => ExtensionHelper::suspendServer($this->service->fresh()), null, false);

        $this->assertSame('disabled', $this->accounts['fm']['status']);
    }

    /**
     * ADV-7 (low). The password change sends the whole account back, every value trimmed by the parser: a source
     * password (or title) with a leading or trailing space is changed, and the customer's encoder can no longer connect.
     */
    public function test_adv7_a_password_change_sends_the_other_values_back_unchanged(): void
    {
        $this->made($this->service, 'radio');
        $this->accounts['radio']['sourcepassword'] = ' Src-Pass ';

        ExtensionHelper::callService($this->service->fresh(), 'changePassword', ['N3w-Strong-Pass', false]);

        [$sent] = $this->sent('server.reconfigure');
        $this->assertSame('N3w-Strong-Pass', $sent['adminpassword']);
        $this->assertTrue(!array_key_exists('sourcepassword', $sent) || $sent['sourcepassword'] === ' Src-Pass ', 'the source password was changed');
    }

    /** REG (safe): a customer's name cannot add or change arguments of the request. */
    public function test_reg_customer_values_cannot_inject_arguments(): void
    {
        $this->owner->client()->primaryContact->forceFill(['company_name' => 'x</title><adminpassword>pwned</adminpassword><template>reseller'])->save();

        ExtensionHelper::createServer($this->service->fresh());

        [$provision] = $this->sent('system.provision');
        $this->assertSame('icecast100', $provision['template']);
        $this->assertNotSame('pwned', $provision['adminpassword']);
        $this->assertStringContainsString('</title>', $provision['title']);
        $this->assertStringContainsString('<template>', $provision['organization']);
    }

    /** REG (safe): an external entity in an answer is not read (and so never sent back with the account). */
    public function test_reg_an_external_entity_in_an_answer_is_not_resolved(): void
    {
        $this->made($this->service, 'radio');
        $this->getaccountBody = '<?xml version="1.0"?><!DOCTYPE centovacast [<!ENTITY x SYSTEM "file:///etc/hostname">]>'
            . '<centovacast><response type="success"><message>OK</message><data><account><username>radio</username><title>&x;</title></account></data></response></centovacast>';

        rescue(fn () => ExtensionHelper::callService($this->service->fresh(), 'changePassword', ['N3w-Strong-Pass', false]), null, false);

        $hostname = trim((string) @file_get_contents('/etc/hostname'));
        foreach ($this->sent('server.reconfigure') as $sent) {
            $this->assertTrue($hostname === '' || !str_contains((string) ($sent['title'] ?? ''), $hostname));
        }
    }
}
