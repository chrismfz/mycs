<?php

namespace MyCS\Extensions\Servers\AdvRedirect {
    use App\Models\Service;
    use MyCS\Extensions\Servers\CPanel\CPanel;

    /**
     * A server extension that hands its panel address back the Laravel way: `return redirect()->away($url)`.
     * During a Livewire call `redirect()` is Livewire's Redirector, whose to()/away() set the component's redirect
     * effect at once, before goto() ever sees the RedirectResponse it returns.
     */
    class AdvRedirect extends CPanel
    {
        public function getActions(Service $service, $settings, $properties): array
        {
            return [...parent::getActions($service, $settings, $properties), ['label' => 'Panel', 'type' => 'button', 'sso' => true, 'function' => 'openPanel']];
        }

        public function openPanel(Service $service, $settings, $properties)
        {
            return redirect()->away($this->getWebmailUrl($service, $settings, $properties));
        }
    }
}

namespace Tests\Feature {
    use App\Livewire\Services\Show;
    use App\Models\Server;
    use App\Models\Service;
    use App\Models\User;
    use App\Support\CurrentClient;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Mail;
    use Illuminate\Support\Facades\Queue;
    use Livewire\Livewire;
    use PHPUnit\Framework\Attributes\DataProvider;
    use Tests\TestCase;

    /**
     * ADV-1 of #323: goto() checks the RedirectResponse an extension returns, but Livewire's Redirector has already
     * stored the address as the component's redirect. A refused `javascript://%0a…` (it passes Laravel's URL check,
     * so the generator keeps it as is) still reaches `window.location.href` on our origin.
     */
    class Adv323RedirectHelperTest extends TestCase
    {
        use RefreshDatabase;

        private User $owner;

        private Service $service;

        private string $answer = 'https://orion.test:2083/cpsess1/login/?session=x';

        protected function setUp(): void
        {
            parent::setUp();
            Queue::fake();
            Mail::fake();
            Http::preventStrayRequests();
            config(['settings.default_currency' => 'USD']);
            $server = Server::query()->create(['name' => 'Orion', 'extension' => 'AdvRedirect', 'type' => 'server', 'enabled' => true]);
            foreach (['host' => 'https://whm.test:2087', 'username' => 'root', 'apikey' => 'key'] as $key => $value) {
                $server->settings()->create(['key' => $key, 'value' => $value]);
            }
            $this->owner = User::factory()->create();
            $product = $this->createProduct();
            $product->product->update(['server_id' => $server->id]);
            $product->product->settings()->create(['key' => 'package', 'value' => 'Business20']);
            $this->service = Service::factory()->create(['client_id' => $this->owner->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
                'server_id' => $server->id, 'status' => 'active', 'currency_code' => 'USD', 'price' => 10, 'expires_at' => now()->addMonth(), 'domain' => 'zonecloud.io']);
            $this->service->properties()->create(['key' => 'cpanel_username', 'name' => 'cPanel username', 'value' => 'zonecl']);
            Http::fake(fn () => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK'], 'data' => ['url' => $this->answer]]));
        }

        private function page()
        {
            $this->actingAs($this->owner)->withSession($this->loginUser($this->owner, [CurrentClient::SESSION_KEY => $this->service->client_id]));

            return Livewire::test(Show::class, ['service' => $this->service]);
        }

        /** @return array<string, array{string}> */
        public static function scripts(): array
        {
            return [
                'a script behind a comment' => ['javascript://%0aalert(document.domain)'],
                'a script, capitals' => ['JAVASCRIPT://x%0Aalert(document.cookie)'],
            ];
        }

        #[DataProvider('scripts')]
        public function test_a_script_returned_through_redirect_away_is_not_redirected_to(string $answer): void
        {
            $this->answer = $answer;
            $page = $this->page()->call('goto', 'openPanel');
            $this->assertStringNotContainsStringIgnoringCase('javascript:', (string) ($page->effects['redirect'] ?? ''));
            // Its form into a new tab (owner 2026-10-26): a controller, where redirect() is no Livewire effect.
            $this->post(route('services.panel-login', $this->service), ['function' => 'openPanel'])->assertRedirect(route('services.show', $this->service));
        }

        public function test_a_panel_returned_through_redirect_away_still_opens(): void
        {
            $this->page();
            $this->post(route('services.panel-login', $this->service), ['function' => 'openPanel'])->assertRedirect('https://orion.test:2083/cpsess1/login/?session=x');
        }
    }
}
