<?php

namespace MyCS\Extensions\Servers\AdvRedirectThrow {
    use App\Models\Service;
    use MyCS\Extensions\Servers\CPanel\CPanel;

    /** During a Livewire call redirect()->away() stores the component's redirect at once (ADV-1). */
    class AdvRedirectThrow extends CPanel
    {
        /** Asks Livewire for the panel's address, then fails a later check (an extension's own error path). */
        public function getLoginUrl(Service $service, $settings, $properties, ?string $app = null): string
        {
            redirect()->away(parent::getLoginUrl($service, $settings, $properties, $app));

            throw new \Exception('the panel refused the session');
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
    use Tests\TestCase;

    /**
     * ADV-2 of #323 (re-review of af9c83a4): openShortcut() checks the stored redirect only when getLoginUrl()
     * returns. When the extension stored one through redirect()->away() and then threw, the catch notifies and
     * returns, and the refused `javascript://%0a…` is still sent as the page's redirect.
     */
    class Adv323RedirectThenThrowTest extends TestCase
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
            $server = Server::query()->create(['name' => 'Orion', 'extension' => 'AdvRedirectThrow', 'type' => 'server', 'enabled' => true]);
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

        public function test_a_script_stored_before_the_shortcut_fails_is_not_redirected_to(): void
        {
            $this->answer = 'javascript://%0aalert(document.domain)';
            $this->page();
            // Its form into a new tab (owner 2026-10-26): the failure sends the tab back to the service.
            $this->post(route('services.panel-login', $this->service), ['function' => 'getLoginUrl', 'app' => 'Email_Accounts'])->assertRedirect(route('services.show', $this->service));
        }
    }
}
