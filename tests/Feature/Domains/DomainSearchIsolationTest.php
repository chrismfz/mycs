<?php

namespace Tests\Feature\Domains;

use App\Livewire\DomainShop\Search;
use App\Models\Currency;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use App\Services\Domains\DomainSearch;
use App\Services\Domains\Registrars\GrEppRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use MyCS\GrEpp\Http\HttpResponse;
use MyCS\GrEpp\Tests\FakeTransport;
use Tests\TestCase;

/**
 * The shop's search, each registrar connection on its own (2026-10-19, live: one Openprovider timeout made every row
 * «could not check»): Openprovider failing never hides what the .gr registry answered, a connection that did not answer
 * is not waited on again for a while, and a batch the registrar refused is asked again name by name.
 */
class DomainSearchIsolationTest extends TestCase
{
    use RefreshDatabase;

    private FakeTransport $transport;

    /** @var array<string, DomainTld> */
    private array $tlds = [];

    /** Openprovider's /domains/check requests so far. */
    private int $checks = 0;

    private bool $down = true;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Currency::query()->firstOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'suffix' => 'EUR', 'format' => '1.000,00']);
        config(['settings.default_currency' => 'EUR']);
        session(['currency' => 'EUR']);
        $op = DomainRegistrarConnection::query()->create(['registrar' => 'openprovider', 'label' => 'OP', 'is_active' => true, 'mode' => 'production']);
        $op->storeCredentials(['username' => 'u', 'password' => 'Op-Secret-Pa55'], ['password']);
        $gr = DomainRegistrarConnection::query()->create(['registrar' => 'grepp', 'label' => 'GR', 'is_active' => true, 'mode' => 'production']);
        $gr->storeCredentials(['username' => 'myip1', 'password' => 'Secret-pw1', 'contact_prefix' => 'c60'], ['password']);
        foreach ([['gr', 1, $gr, 2], ['com', 2, $op, 1], ['net', 3, $op, 1]] as [$tld, $sort, $connection, $years]) {
            $this->tlds[$tld] = DomainTld::query()->create(['tld' => $tld, 'is_active' => true, 'sort' => $sort, 'registrar_connection_id' => $connection->id]);
            foreach (['register', 'renew'] as $operation) {
                DomainTldPrice::query()->create(['domain_tld_id' => $this->tlds[$tld]->id, 'operation' => $operation, 'years' => $years, 'currency_code' => 'EUR', 'price' => '20.00']);
            }
        }
        $this->transport = new FakeTransport;
        GrEppRegistrar::$transport = fn () => $this->transport;
        GrEppRegistrar::reset();
    }

    protected function tearDown(): void
    {
        GrEppRegistrar::$transport = null;
        GrEppRegistrar::reset();
        parent::tearDown();
    }

    /** The .gr registry answers one domain:check (the live answer: example1.gr taken, free-example.gr free). */
    private function registryAnswers(): void
    {
        GrEppRegistrar::reset();
        $this->transport = new FakeTransport;
        $this->transport->fixture('02-hello-response.xml', ['JSESSIONID=A'])->fixture('04-login-response.xml');
        $body = (string) file_get_contents(base_path('packages/gr-epp/tests/fixtures/live/domain-check-response.xml'));
        $this->transport->queue(new HttpResponse(200, (string) preg_replace('#<clTRID>[^<]*</clTRID>#', '<clTRID>ECHO</clTRID>', $body)));
    }

    /**
     * Openprovider: logs in, then $check answers /domains/check (its names given).
     *
     * @param  callable(list<string>): mixed  $check
     */
    private function openprovider(callable $check): void
    {
        Http::fake(function (Request $request) use ($check) {
            if (str_contains($request->url(), '/auth/login')) {
                return Http::response(['code' => 0, 'data' => ['token' => 'tok']]);
            }
            if (str_contains($request->url(), '/domains/check')) {
                $this->checks++;

                return $check(array_map(fn ($d) => $d['name'] . '.' . $d['extension'], $request->data()['domains']));
            }

            return Http::response([], 404);
        });
    }

    private static function answer(array $names, string $taken = ''): mixed
    {
        return Http::response(['code' => 0, 'data' => ['results' => array_map(fn ($name) => ['domain' => $name, 'status' => $name === $taken ? 'active' : 'free'], $names)]]);
    }

    /** @return array<string, string> */
    private function statuses(): array
    {
        return app(DomainSearch::class)->statuses(['example1.gr' => $this->tlds['gr'], 'free-example.gr' => $this->tlds['gr'],
            'example1.com' => $this->tlds['com'], 'example1.net' => $this->tlds['net']]);
    }

    public function test_an_openprovider_timeout_leaves_the_gr_registry_answers_and_says_could_not_check_for_its_own_names_only(): void
    {
        $this->openprovider(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 10002 milliseconds'));
        $this->registryAnswers();

        $this->assertSame(['example1.gr' => DomainSearch::TAKEN, 'free-example.gr' => DomainSearch::FREE,
            'example1.com' => DomainSearch::UNKNOWN, 'example1.net' => DomainSearch::UNKNOWN], $this->statuses());

        // The page: .gr taken (with «transfer it»), .com «could not check».
        Cache::flush();
        $this->registryAnswers();
        Livewire::test(Search::class)->set('query', 'example1')->call('search')
            ->assertSee('example1.gr')->assertSee(__('domains.shop.taken'))->assertSee(__('domains.shop.unknown'));
    }

    public function test_a_connection_that_did_not_answer_is_not_waited_on_again_for_a_while_and_is_asked_again_after(): void
    {
        $this->openprovider(function (array $names) {
            if ($this->down) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return self::answer($names, 'example1.com');
        });
        $this->registryAnswers();
        $this->statuses();
        $this->assertSame(1, $this->checks);

        // Within the pause: Openprovider is not asked (no search waits on it), the registry still is.
        $this->down = false;
        Cache::forget('domain-available:' . sha1('example1.gr'));
        $this->registryAnswers();
        $statuses = $this->statuses();
        $this->assertSame(1, $this->checks, 'not asked again within the pause');
        $this->assertSame(DomainSearch::UNKNOWN, $statuses['example1.com']);
        $this->assertSame(DomainSearch::TAKEN, $statuses['example1.gr']);

        // Past it: asked again, and answers («could not check» was never cached).
        $this->travel(DomainSearch::PAUSE + 1)->seconds();
        $statuses = $this->statuses();
        $this->assertSame(2, $this->checks);
        $this->assertSame([DomainSearch::TAKEN, DomainSearch::FREE], [$statuses['example1.com'], $statuses['example1.net']]);
    }

    public function test_a_batch_the_registrar_refused_is_asked_again_name_by_name(): void
    {
        // Openprovider refuses any request with example1.net in it (a 4xx: it answered), answers the others.
        $this->openprovider(fn (array $names) => in_array('example1.net', $names, true)
            ? Http::response(['code' => 1, 'desc' => 'Invalid domain'], 400)
            : self::answer($names, 'example1.com'));
        $this->registryAnswers();

        $statuses = $this->statuses();

        $this->assertSame(3, $this->checks, 'the batch, then each of its two names');
        $this->assertSame(DomainSearch::TAKEN, $statuses['example1.com'], 'answered on its own');
        $this->assertSame(DomainSearch::UNKNOWN, $statuses['example1.net'], 'refused on its own too: could not check');
        $this->assertSame(DomainSearch::TAKEN, $statuses['example1.gr']);
        $this->assertFalse(Cache::has('domain-search-registrar-paused:' . $this->tlds['com']->registrar_connection_id), 'a refusal is an answer: no pause');
    }

    public function test_a_timeout_while_asking_name_by_name_stops_asking(): void
    {
        // The batch is refused, then the first name alone times out: the second is not asked (it stopped answering).
        $this->openprovider(function () {
            if ($this->checks === 1) {
                return Http::response(['code' => 1, 'desc' => 'Invalid domain'], 400);
            }
            throw new ConnectionException('cURL error 28: Operation timed out');
        });
        $this->registryAnswers();

        $statuses = $this->statuses();

        $this->assertSame(2, $this->checks);
        $this->assertSame([DomainSearch::UNKNOWN, DomainSearch::UNKNOWN], [$statuses['example1.com'], $statuses['example1.net']]);
        $this->assertSame(DomainSearch::FREE, $statuses['free-example.gr']);
    }
}
