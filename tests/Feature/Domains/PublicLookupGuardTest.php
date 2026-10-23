<?php

namespace Tests\Feature\Domains;

use App\Livewire\DomainShop\Search;
use App\Livewire\DomainShop\Whois;
use App\Services\Captcha\Altcha;
use App\Services\Domains\Lookup\DomainLookup;
use App\Services\Domains\Lookup\PublicLookups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The public lookups against bots (owner 2026-10-23): whois asks for the captcha and a link never looks up on its own;
 * search, transfer and whois share one limit from everyone (PublicLookups), past which nothing reaches a registry.
 */
class PublicLookupGuardTest extends TestCase
{
    use RefreshDatabase;

    private object $lookup;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        RateLimiter::clear('domain-lookups:public');
        $this->app->instance(Altcha::class, new class extends Altcha
        {
            public function verify(string $payload): bool
            {
                return $payload === 'solved';
            }
        });
        $this->lookup = new class extends DomainLookup
        {
            public int $asked = 0;

            public function __construct() {}

            public function whois(string $fqdn): array
            {
                $this->asked++;

                return ['status' => 'registered', 'record' => ['registrar' => 'Example Registrar'], 'text' => null];
            }
        };
        $this->app->instance(DomainLookup::class, $this->lookup);
    }

    public function test_whois_needs_the_captcha_and_a_link_only_fills_the_name(): void
    {
        Livewire::withQueryParams(['domain' => 'example.com'])->test(Whois::class)->assertSet('query', 'example.com')->assertSet('answer', null);
        Livewire::test(Whois::class)->set('query', 'example.com')->call('lookup')->assertHasErrors('captcha')->assertSet('answer', null);
        Livewire::test(Whois::class)->set('query', 'example.com')->set('captcha', 'forged')->call('lookup')->assertHasErrors('captcha');
        $this->assertSame(0, $this->lookup->asked);

        Livewire::test(Whois::class)->set('query', 'example.com')->set('captcha', 'solved')->call('lookup')->assertSee('Example Registrar');
        $this->assertSame(1, $this->lookup->asked);
    }

    public function test_past_everyones_limit_nothing_is_asked(): void
    {
        RateLimiter::increment('domain-lookups:public', 600, PublicLookups::GLOBAL - 3);
        // A search checks up to 7 names: 3 left are not enough for one.
        Livewire::test(Search::class)->set('query', 'example')->call('search')->assertSet('error', __('domains.shop.busy'));
        RateLimiter::increment('domain-lookups:public', 600, 3);

        Livewire::test(Whois::class)->set('query', 'example.com')->set('captcha', 'solved')->call('lookup')
            ->assertSet('error', __('domains.shop.busy'))->assertSet('answer', null);
        Livewire::test(Search::class)->set('query', 'example')->call('search')->assertSet('error', __('domains.shop.busy'));
        $this->assertSame(0, $this->lookup->asked);
    }
}
