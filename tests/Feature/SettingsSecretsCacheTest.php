<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Providers\SettingsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

        $this->assertNull(Cache::get('settings'), 'the old plain-text cache entry is removed');
        $cached = serialize(Cache::get('settings.rows'));
        $this->assertStringNotContainsString('s3cret-smtp', $cached);
        $this->assertStringContainsString('mail_password', $cached);

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
}
