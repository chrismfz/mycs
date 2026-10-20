<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Http\Controllers\ZlicController;
use App\Models\License;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * zlic.php's rate limit (ROADMAP 4, 2026-10-20): a key search from one address is answered «Remote Check Failed» (what
 * zlic.php said when it could not check: clients keep their local key) without the licenses; a client that works is
 * never held back by its own checks, nor by another address's search.
 */
class ZlicRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $server = Server::query()->create(['name' => 'Licensing', 'extension' => 'NixpalLicensing', 'type' => 'server', 'enabled' => true]);
        $product = $this->createProduct();
        $product->product->update(['server_id' => $server->id]);
        foreach (['key_prefix' => ['ZCAgent-', false], 'key_length' => ['16', false], 'secret_key' => ['TestSecret', true]] as $key => [$value, $encrypted]) {
            (new Setting)->forceFill(['key' => $key, 'value' => $value, 'encrypted' => $encrypted, 'type' => 'string',
                'settingable_id' => $product->product->id, 'settingable_type' => $product->product->getMorphClass()])->save();
        }
        $user = User::factory()->create();
        $service = Service::factory()->create(['client_id' => $user->client()->id, 'plan_id' => $product->plan->id, 'product_id' => $product->product->id,
            'status' => 'active', 'currency_code' => 'USD', 'price' => 0, 'quantity' => 1, 'expires_at' => now()->addMonth()]);
        ExtensionHelper::createServer($service);
        $this->key = License::query()->sole()->license_key;
    }

    /** @return array<string, mixed> */
    private function check(string $key, string $ip, string $psk = 'TestSecret'): array
    {
        return json_decode($this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/zlic.php', ['license' => $key, 'license_PSK' => $psk])->getContent(), true);
    }

    public function test_a_key_search_is_answered_remote_check_failed_without_the_database(): void
    {
        for ($i = 0; $i < ZlicController::FAILURES_PER_HOUR; $i++) {
            $this->assertSame('Invalid', $this->check('ZCAgent-' . str_pad(dechex($i), 16, '0', STR_PAD_LEFT), '203.0.113.9')['status']);
        }

        DB::enableQueryLog();
        $answer = $this->check($this->key, '203.0.113.9'); // even a real key, from that address
        $this->assertSame(['status' => 'Invalid', 'description' => 'Remote Check Failed'], $answer);
        $asked = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $query) => str_contains($query, 'licen'));
        $this->assertSame([], array_values($asked), 'the licenses were asked'); // the app's own settings load is not the check
        $this->assertNull(License::query()->sole()->last_seen_ip, 'the key was bound to the searching address');

        // Another address, the license's own client: answered as always.
        $this->assertSame('Active', $this->check($this->key, '198.51.100.7')['status']);
    }

    public function test_checks_that_pass_never_count_wrong_ones_from_elsewhere_neither(): void
    {
        $this->assertSame('Active', $this->check($this->key, '198.51.100.7')['status']); // binds it
        for ($i = 0; $i < ZlicController::FAILURES_PER_HOUR + 5; $i++) {
            $this->check($this->key, '198.51.100.7'); // passes: never counted
            $this->check('ZCAgent-0000000000000000', '203.0.113.9'); // another address's search
        }

        $this->assertSame('Active', $this->check($this->key, '198.51.100.7')['status']);
    }

    public function test_a_wrong_psk_counts_as_a_wrong_answer(): void
    {
        $this->check($this->key, '198.51.100.7'); // binds it
        for ($i = 0; $i < ZlicController::FAILURES_PER_HOUR; $i++) {
            $this->check($this->key, '198.51.100.7', 'Guess' . $i);
        }

        $this->assertSame('Remote Check Failed', $this->check($this->key, '198.51.100.7')['description'] ?? null);
    }
}
