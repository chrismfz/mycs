<?php

namespace Tests\Feature;

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

/** A server's link into its panel opens only as a web page: never a script on this site. */
class PanelUrlTest extends TestCase
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
        $server = Server::query()->create(['name' => 'Orion', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
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
    public static function notPages(): array
    {
        return [
            'a script' => ['javascript:alert(document.cookie)'],
            'a script in capitals, spaced' => [' JaVaScRiPt:alert(1)'],
            'a data page' => ['data:text/html,<script>alert(1)</script>'],
            'no host' => ['https:///x'],
            'a login in the address' => ['https://evil.test@orion.test/'],
        ];
    }

    #[DataProvider('notPages')]
    public function test_a_panel_link_that_is_not_a_web_page_is_refused(string $answer): void
    {
        $this->answer = $answer;
        $page = $this->page();

        $page->call('goto', 'getWebmailUrl')->assertNoRedirect();
        $page->call('openShortcut', 'Email_Accounts')->assertNoRedirect();
    }

    public function test_a_panel_link_opens_as_before(): void
    {
        $this->page()->call('goto', 'getWebmailUrl')->assertRedirect('https://orion.test:2083/cpsess1/login/?session=x');
    }
}
