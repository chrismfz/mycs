<?php

namespace Tests\Feature;

use App\Livewire\DomainShop\Whois;
use App\Services\Captcha\Altcha;
use App\Services\Domains\Lookup\DomainLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Adversarial review of #384 (captcha messages): the captcha's state across one Livewire request. */
class CaptchaAdversarial384Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['settings.default_currency' => 'USD']);
        // A solution counts once, as the real one: 'solved' is accepted the first time only.
        $this->app->instance(Altcha::class, new class extends Altcha
        {
            private array $spent = [];

            public function verify(string $payload): bool
            {
                if ($payload !== 'solved' || isset($this->spent[$payload])) {
                    return false;
                }

                return $this->spent[$payload] = true;
            }
        });
    }

    /**
     * Turnstile (hCaptcha, reCAPTCHA alike): a token is good once at the provider. The token comes with the lookup's
     * request; Captchable::updated() verifies it, Whois::lookup() sets is_valid back to false and verifies the same
     * token again, which the provider now refuses: whois with a third-party captcha never works.
     */
    public function test_whois_with_turnstile_verifies_the_token_once(): void
    {
        $this->storeSettings(['captcha' => 'turnstile', 'captcha_secret' => 'test-secret']);
        $this->app->instance(DomainLookup::class, new class extends DomainLookup
        {
            public function __construct() {}

            public function whois(string $fqdn): array
            {
                return ['status' => 'registered', 'record' => ['registrar' => 'Example Registrar'], 'text' => null];
            }
        });
        $seen = [];
        Http::fake(['challenges.cloudflare.com/*' => function ($request) use (&$seen) {
            $token = $request['response'];
            $first = !isset($seen[$token]);
            $seen[$token] = true;

            return Http::response($first ? ['success' => true] : ['success' => false, 'error-codes' => ['timeout-or-duplicate']]);
        }]);

        // As the browser sends it: the token deferred (`@this.set('captcha', token, false)`), with the lookup.
        $whois = Livewire::test(Whois::class)->update(
            calls: [['method' => 'lookup', 'params' => [], 'path' => '']],
            updates: ['query' => 'example.com', 'captcha' => 'token-1'],
        );

        $whois->assertHasNoErrors('captcha')->assertSee('Example Registrar');
        $this->assertCount(1, Http::recorded(), 'one token, verified at the provider once');
    }
}
