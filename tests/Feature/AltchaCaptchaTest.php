<?php

namespace Tests\Feature;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha as AltchaLibrary;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Livewire\Auth\Login;
use App\Models\User;
use App\Services\Captcha\Altcha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Our captcha, ALTCHA (docs/captcha.md): a challenge we sign, solved once, checked here, nothing sent elsewhere. */
class AltchaCaptchaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.captcha' => 'altcha', 'settings.default_currency' => 'USD']);
        // The real difficulties take seconds in PHP: a tiny one, the same code otherwise.
        $this->app->instance(Altcha::class, new class extends Altcha
        {
            protected function difficulty(): array
            {
                return ['cost' => 1, 'counter' => [1, 5]];
            }
        });
        Http::preventStrayRequests(); // nothing goes anywhere
    }

    /** @param array<string, mixed> $challenge */
    private function solve(array $challenge): string
    {
        $challenge = Challenge::fromArray($challenge);
        $solution = (new AltchaLibrary)->solveChallenge(new SolveChallengeOptions(challenge: $challenge, algorithm: new Pbkdf2));
        $this->assertNotNull($solution);

        return (new Payload($challenge, $solution))->toBase64();
    }

    private function challenge(): array
    {
        return $this->getJson('/captcha/altcha')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    public function test_the_challenge_is_given_only_while_altcha_is_the_captcha(): void
    {
        $this->assertArrayHasKey('parameters', $this->challenge());

        config(['settings.captcha' => 'turnstile']);
        $this->getJson('/captcha/altcha')->assertNotFound();
    }

    public function test_a_solution_counts_once(): void
    {
        $payload = $this->solve($this->challenge());

        $this->assertTrue(app(Altcha::class)->verify($payload));
        $this->assertFalse(app(Altcha::class)->verify($payload), 'replayed');
    }

    public function test_a_tampered_expired_or_foreign_solution_is_refused(): void
    {
        $altcha = app(Altcha::class);
        $challenge = $this->challenge();

        $this->assertFalse($altcha->verify(''));
        $this->assertFalse($altcha->verify('not base64 at all'));
        $this->assertFalse($altcha->verify(str_repeat('a', 9000)));

        // Signed with other keys (another site, or before APP_KEY changed): not ours.
        $foreign = (new AltchaLibrary('another-site', 'another-key'))->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2, cost: 1, counter: 3, expiresAt: time() + 60))->toArray();
        $this->assertFalse($altcha->verify($this->solve($foreign)));
        $this->assertTrue($altcha->verify($this->solve($challenge)), 'ours, for comparison');

        // Its time is part of what we signed: a solution past it is refused (the library checks the real clock, so the
        // challenge is made two minutes back, for one minute).
        config(['settings.altcha_minutes' => 1]);
        $this->travel(-2)->minutes();
        $late = $this->solve($this->challenge());
        $this->travelBack();
        $this->assertFalse($altcha->verify($late));
    }

    public function test_the_login_needs_a_solved_altcha(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-password')]);
        config(['settings.captcha' => 'altcha']); // creating a model reloads the settings: set it again after

        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'secret-password')
            ->call('submit')->assertHasErrors('captcha');

        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'secret-password')
            ->set('captcha', 'forged')->call('submit')->assertHasErrors(['captcha' => __('auth.captcha_invalid')]);

        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'secret-password')
            ->set('captcha', $this->solve($this->challenge()))->call('submit')->assertHasNoErrors('captcha');
    }

    public function test_the_login_page_shows_our_widget_and_no_third_party_script(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('<altcha-widget', $html);
        $this->assertStringContainsString('challenge="' . route('captcha.altcha') . '"', $html);
        $this->assertStringNotContainsString('challenges.cloudflare.com', $html);
        $this->assertStringNotContainsString('altcha.org', $html);
    }

    /** A field updated after solving (a live field, its own request) does not spend the solution before the submit. */
    public function test_a_field_updated_after_solving_does_not_spend_the_solution(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-password')]);
        config(['settings.captcha' => 'altcha']);

        Livewire::test(Login::class)->set('captcha', $this->solve($this->challenge()))
            ->set('email', $user->email)->set('password', 'secret-password')
            ->call('submit')->assertHasNoErrors('captcha');
    }
}
