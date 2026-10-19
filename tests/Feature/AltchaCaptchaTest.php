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

    /**
     * Code review of #222: the same solution written another way (JSON layout, hex case, the counter and time the check
     * ignores, an extra field) is the same challenge, already spent.
     */
    public function test_a_solution_written_another_way_is_still_spent(): void
    {
        $altcha = app(Altcha::class);
        $b64 = $this->solve($this->challenge());
        $this->assertTrue($altcha->verify($b64));

        $arr = json_decode(base64_decode($b64), true);
        $with = fn (array $solution) => base64_encode(json_encode(['challenge' => $arr['challenge'], 'solution' => $solution + $arr['solution']]));
        $variants = [
            'pretty JSON' => base64_encode(json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
            'uppercase derivedKey' => $with(['derivedKey' => strtoupper($arr['solution']['derivedKey'])]),
            'other counter' => $with(['counter' => 999999]),
            'other time' => $with(['time' => 42.5]),
            'extra field' => base64_encode(json_encode($arr + ['x' => 1])),
        ];

        $this->assertSame([], array_keys(array_filter(array_map(fn (string $v) => $altcha->verify($v), $variants))));
    }

    /** Code review of #222: a shorter lifetime set after a challenge was given does not make its solution reusable. */
    public function test_a_solution_stays_spent_for_its_challenges_whole_life(): void
    {
        config(['settings.altcha_minutes' => 60]);
        $payload = $this->solve($this->challenge());
        config(['settings.altcha_minutes' => 1]);
        $this->assertTrue(app(Altcha::class)->verify($payload));

        $this->travel(5)->minutes(); // past the new setting, within the challenge's own hour

        $this->assertFalse(app(Altcha::class)->verify($payload));
    }
}
