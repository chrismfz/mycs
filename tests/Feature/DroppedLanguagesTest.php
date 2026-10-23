<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The languages kept (owner 2026-10-24), and a migration that forgets the dropped ones wherever they were chosen. */
class DroppedLanguagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_kept_languages_remain(): void
    {
        $dirs = array_map('basename', glob(base_path('lang/*'), GLOB_ONLYDIR));
        sort($dirs);

        $this->assertSame(['da', 'de', 'el', 'en', 'es', 'fr', 'nl', 'pt', 'sv'], $dirs);
        $this->assertSame($dirs, array_keys(config('app.available_locales')));
    }

    public function test_the_migration_forgets_a_dropped_language_wherever_chosen(): void
    {
        $setting = fn (string $key, string $value) => DB::table('settings')->updateOrInsert(['key' => $key, 'settingable_type' => null, 'settingable_id' => null], ['value' => $value, 'type' => 'string']);
        $setting('allowed_languages', json_encode(['el', 'en', 'zh', 'it']));
        $setting('app_language', 'tr');
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['language' => 'it']);
        DB::table('clients')->where('id', $user->client()->id)->update(['language' => 'de']);

        Cache::put('settings.rows', ['stale']);

        (require database_path('migrations/2027_01_03_000000_drop_unused_languages.php'))->up();

        $this->assertFalse(Cache::has('settings.rows'), 'the cached settings are read again');

        $this->assertSame(['el', 'en'], json_decode(DB::table('settings')->where('key', 'allowed_languages')->value('value'), true));
        $this->assertSame('en', DB::table('settings')->where('key', 'app_language')->value('value'));
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('language'));
        $this->assertSame('de', DB::table('clients')->where('id', $user->client()->id)->value('language'), 'a kept language stays');
    }

    public function test_a_session_holding_a_dropped_language_forgets_it(): void
    {
        $this->withSession(['locale' => 'zh'])->get('/')->assertOk();

        $this->assertNotSame('zh', app()->getLocale());
        $this->assertFalse(session()->has('locale'));
    }
}
