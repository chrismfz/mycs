<?php

namespace Tests\Feature;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Extension secrets are stored encrypted, on create and on edit (audit-security M6). */
class ExtensionCredentialsTest extends TestCase
{
    use RefreshDatabase;

    private function server(): Server
    {
        return Server::create(['name' => 'cp1', 'extension' => 'CPanel', 'type' => 'server', 'enabled' => true]);
    }

    private function raw(Server $server, string $key): object
    {
        return DB::table('settings')->where('settingable_id', $server->id)->where('key', $key)->first();
    }

    public function test_secrets_are_encrypted_when_a_server_is_created(): void
    {
        $server = $this->server();

        ExtensionHelper::saveSettings($server, ['host' => 'https://cp.example.test:2087', 'username' => 'root', 'apikey' => 'SECRET-KEY', 'nothing' => null], skipNull: true);

        $apikey = $this->raw($server, 'apikey');
        $this->assertTrue((bool) $apikey->encrypted);
        $this->assertNotSame('SECRET-KEY', $apikey->value);
        $this->assertSame('SECRET-KEY', Crypt::decryptString($apikey->value));
        $this->assertFalse((bool) $this->raw($server, 'username')->encrypted);
        $this->assertSame('root', $this->raw($server, 'username')->value);
        $this->assertSame('SECRET-KEY', $server->fresh()->settings->firstWhere('key', 'apikey')->value);
    }

    public function test_secrets_stay_encrypted_when_a_server_is_edited(): void
    {
        $server = $this->server();
        ExtensionHelper::saveSettings($server, ['host' => 'https://cp.example.test:2087', 'username' => 'root', 'apikey' => 'OLD'], skipNull: true);

        ExtensionHelper::saveSettings($server->fresh(), ['host' => 'https://cp.example.test:2087', 'username' => 'root', 'apikey' => 'NEW']);

        $this->assertSame('NEW', Crypt::decryptString($this->raw($server, 'apikey')->value));
    }

    public function test_migration_encrypts_plain_text_credentials(): void
    {
        $server = $this->server();
        foreach (['host' => 'https://cp.example.test:2087', 'username' => 'root', 'apikey' => 'PLAIN'] as $key => $value) {
            DB::table('settings')->insert(['key' => $key, 'value' => $value, 'type' => 'string', 'encrypted' => false, 'settingable_id' => $server->id, 'settingable_type' => Server::class]);
        }

        (require database_path('migrations/2026_10_11_100000_encrypt_extension_credentials.php'))->up();

        $apikey = $this->raw($server, 'apikey');
        $this->assertTrue((bool) $apikey->encrypted);
        $this->assertSame('PLAIN', Crypt::decryptString($apikey->value));
        $this->assertSame('root', $this->raw($server, 'username')->value);
        $this->assertSame('PLAIN', Setting::where('settingable_id', $server->id)->where('key', 'apikey')->first()->value);
    }
}
