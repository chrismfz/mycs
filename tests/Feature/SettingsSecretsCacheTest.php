<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Providers\SettingsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The settings cache never holds a secret in clear (audit sweep KR-7, EVT-11). It cached the values after the
 * model decrypted them: with CACHE_STORE=file (devbox, production) the SMTP and IMAP passwords sat in plain text
 * under storage/framework/cache, which goes into the storage/ backups.
 */
class SettingsSecretsCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_secrets_are_cached_encrypted_and_decrypted_into_config_only(): void
    {
        Setting::query()->create(['key' => 'mail_password', 'value' => 's3cret-smtp', 'type' => 'string', 'encrypted' => true]);
        Setting::query()->create(['key' => 'cronjob_order_suspend', 'value' => 9, 'type' => 'integer', 'encrypted' => false]);
        Cache::put('settings', collect(['mail_password' => 's3cret-smtp'])); // what the old code cached

        SettingsProvider::flushCache();

        foreach (['settings', 'settings.rows'] as $key) { // every key the provider has used
            $this->assertStringNotContainsString('s3cret-smtp', serialize(Cache::get($key)), "cache '$key' holds the secret in clear");
        }
        $this->assertStringContainsString('mail_password', serialize(Cache::get('settings.rows')));

        $this->assertSame('s3cret-smtp', config('settings.mail_password'));
        $this->assertSame(9, config('settings.cronjob_order_suspend'));
    }

    public function test_settings_load_from_the_cache_without_the_database(): void
    {
        Setting::query()->create(['key' => 'mail_password', 'value' => 's3cret-smtp', 'type' => 'string', 'encrypted' => true]);
        SettingsProvider::flushCache();
        Setting::query()->where('key', 'mail_password')->delete(); // only the cache has it now
        config(['settings' => []]);

        SettingsProvider::getSettings(true);

        $this->assertSame('s3cret-smtp', config('settings.mail_password'));
    }

    /** @return array<string, array{string, mixed, bool}> */
    public static function types(): array
    {
        return [
            'boolean' => ['boolean', true, false],
            'integer' => ['integer', 42, false],
            'float' => ['float', 1.5, false],
            'array' => ['array', ['el', 'en'], false],
            'string' => ['string', 'plain', false],
            'other type' => ['text', 'as is', false],
            'encrypted' => ['string', 'secret', true],
        ];
    }

    /** The cache path gives what the model gives, for every type. */
    #[DataProvider('types')]
    public function test_the_cache_and_the_model_read_the_same_value(string $type, mixed $value, bool $encrypted): void
    {
        Setting::query()->create(['key' => 'probe', 'value' => $value, 'type' => $type, 'encrypted' => $encrypted]);

        SettingsProvider::flushCache();

        $this->assertSame(Setting::query()->where('key', 'probe')->first()->value, config('settings.probe'));
        $this->assertEquals($value, config('settings.probe'));
    }
}
