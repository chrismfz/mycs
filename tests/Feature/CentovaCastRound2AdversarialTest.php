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
 * Second-round adversarial review of #329 (Centova Cast), against the fixes of a03938af. Each R2-n test fails on that
 * commit.
 */
class CentovaCastRound2AdversarialTest extends TestCase
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

    /** @var (\Closure(array<string, string>): mixed)|null answers system.provision instead of the default, when set */
    private ?\Closure $provision = null;

    /** A Centova that ignores listaccounts' `start` (always the first page). */
    private bool $ignoreStart = false;

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
                    $this->ignoreStart ? 0 : (int) ($args['start'] ?? 0), (int) ($args['limit'] ?? 100)),
            )))),
            'system.provision' => $this->provision !== null ? ($this->provision)($args) : tap(Http::response(self::xml('success', '<account>' . self::fields(['username' => $user, 'port' => '9250', 'servertype' => 'IceCast']) . '</account>')),
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
        $other = $this->service->replicate()->fill($attributes + ['username' => null]);
        $other->save();

        return $other;
    }

    /**
     * R2-1 (medium). The refused-yet-made path (code review 3's fix) adopts whatever account Centova has under the name
     * with this client's email, without claimedElsewhere(): two stations of one client created at once both find
     * «radiofono» free (freeUsername's check and the PENDING write are not atomic), one gets it, the other is refused
     * «username in use» and adopts the first one's station. Two services then bill, suspend and terminate one station.
     */
    public function test_r2_1_a_refused_create_never_adopts_the_account_another_service_just_made(): void
    {
        $other = $this->sibling();
        $this->provision = function (array $args) use ($other) {
            // The other service's create, a moment earlier, made the same name.
            $this->made($other, $args['username']);

            return Http::response(self::xml('error', '', 'Username already in use'));
        };

        rescue(fn () => ExtensionHelper::createServer($this->service->fresh()), null, false);

        $this->assertSame('radiofono', $other->fresh()->username);
        $this->assertNotSame('radiofono', $this->service->fresh()->username, 'the second service adopted the station the first one has');
    }

    /**
     * R2-2 (low). The refused-yet-made adoption keeps the password of the refused request, though the account may have
     * been made by the earlier attempt whose answer was lost (a gateway timeout while Centova went on): its admin
     * password is the first attempt's. The customer is shown a password the panel does not take.
     */
    public function test_r2_2_an_adopted_account_keeps_the_password_it_was_made_with(): void
    {
        $first = null;
        $this->provision = function (array $args) use (&$first) {
            if ($first === null) {
                $first = $args; // Centova still making it when the gateway gives up

                return Http::response('Gateway Timeout', 504);
            }
            $this->accounts[$first['username']] = ['username' => $first['username'], 'email' => $first['email'], 'status' => 'enabled', 'adminpassword' => $first['adminpassword']];

            return Http::response(self::xml('error', '', 'Username already in use'));
        };

        rescue(fn () => ExtensionHelper::createServer($this->service->fresh()), null, false);
        rescue(fn () => ExtensionHelper::createServer($this->service->fresh()), null, false);

        $this->assertSame('radiofono', $this->service->fresh()->username);
        $this->assertSame($this->accounts['radiofono']['adminpassword'], $this->service->fresh()->password, 'the service shows a password the account was not made with');
    }

    /**
     * R2-3 (medium). ADV-4's fix compares names with SQL TRIM(), which strips spaces only, while username() trims with
     * PHP's trim() (tabs, CR, LF too). A live service imported as «radio\r» (a CRLF export) acts on «radio», yet the
     * «radio» service does not see it: its terminate (delete) wipes the station the other one bills.
     */
    public function test_r2_3_a_sibling_with_a_tab_or_newline_in_its_name_still_blocks_actions(): void
    {
        $this->made($this->service, 'radio');
        $this->sibling(['username' => "radio\r\n"]);
        $this->service->product->settings()->create(['key' => 'terminate_mode', 'value' => CentovaCast::DELETE]);

        rescue(fn () => ExtensionHelper::terminateServer($this->service->fresh()), null, false);

        $this->assertSame([], $this->sent('system.terminate'), 'deleted a station another live service claims');
        $this->assertArrayHasKey('radio', $this->accounts);
    }

    /**
     * R2-4 (medium). claimants() looks only at services with the same services.server_id, but a service whose server_id
     * is null acts on its product's server (Service::provisioningServer(); imported services). Such a live service with
     * «radio» is never seen by the «radio» service that has the server set: its terminate (delete) wipes that station.
     */
    public function test_r2_4_a_sibling_on_the_products_server_without_server_id_still_blocks_actions(): void
    {
        $this->made($this->service, 'radio');
        $sibling = $this->sibling(['username' => 'radio', 'server_id' => null]);
        $this->assertSame($this->server->id, $sibling->fresh()->provisioningServer()?->id, 'the sibling acts on this Centova');
        $this->service->product->settings()->create(['key' => 'terminate_mode', 'value' => CentovaCast::DELETE]);

        rescue(fn () => ExtensionHelper::terminateServer($this->service->fresh()), null, false);

        $this->assertSame([], $this->sent('system.terminate'), 'deleted a station another live service claims');
        $this->assertArrayHasKey('radio', $this->accounts);
    }

    /**
     * R2-5 (low). ADV-6's paging stops only on a short page or after 10,000 rows. A Centova that ignores `start` (gives
     * the first page again) costs 100 identical listaccounts calls per lookup, each with the full timeout; freeUsername()
     * does up to 31 lookups (3,100 calls) for one create. A page seen already should end the loop.
     */
    public function test_r2_5_a_centova_that_ignores_start_is_not_asked_a_hundred_times(): void
    {
        $this->ignoreStart = true;
        foreach (range(1, 100) as $i) {
            $this->accounts["xfm{$i}"] = ['username' => "xfm{$i}", 'email' => "s{$i}@example.test", 'status' => 'enabled'];
        }
        $this->made($this->service, 'fm');

        rescue(fn () => ExtensionHelper::suspendServer($this->service->fresh()), null, false);

        $this->assertLessThanOrEqual(3, count($this->sent('system.listaccounts')), 'the same page asked for again and again');
    }
}
