<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Providers\SettingsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A saved setting flushes the settings (and restarts the queue workers) when the app terminates, unless they were read
 * from the database again meanwhile. It flushed once per PHP process (a `static`), on every request of the app that
 * saved: the seeder's saves, already flushed by the seeder itself, were flushed again by the first test's first request,
 * over its config() (73 tests failed alone, #379).
 */
class SettingsFlushTest extends TestCase
{
    use RefreshDatabase;

    private const RESTART = 'illuminate:queue:restart';

    private function save(string $value): void
    {
        Setting::query()->updateOrCreate(['key' => 'company_name', 'settingable_type' => null], ['value' => $value]);
    }

    public function test_a_saved_setting_is_flushed_when_the_app_terminates(): void
    {
        Cache::forget(self::RESTART);
        $this->save('New Co');
        $this->assertTrue(SettingsProvider::flushPending());
        config(['settings.company_name' => 'stale']);

        app()->terminate();

        $this->assertSame('New Co', config('settings.company_name'));
        $this->assertNotNull(Cache::get(self::RESTART), 'the queue workers are restarted');
        $this->assertFalse(SettingsProvider::flushPending());
    }

    public function test_a_later_save_in_the_same_app_is_flushed_too(): void
    {
        $this->save('First');
        app()->terminate();
        $this->save('Second');
        config(['settings.company_name' => 'stale']);

        app()->terminate();

        $this->assertSame('Second', config('settings.company_name'));
    }

    public function test_nothing_saved_nothing_flushed(): void
    {
        $this->save('Saved');
        app()->terminate();
        Cache::forget(self::RESTART);
        config(['settings.company_name' => 'set by the test']);

        app()->terminate();

        $this->assertSame('set by the test', config('settings.company_name'));
        $this->assertNull(Cache::get(self::RESTART));
    }

    public function test_a_flush_meanwhile_is_not_repeated(): void
    {
        $this->save('Seeded');
        SettingsProvider::flushCache(); // what the seeder does at its end
        Cache::forget(self::RESTART);
        config(['settings.company_name' => 'set by the test']);

        $this->get('/'); // the first request terminates

        $this->assertSame('set by the test', config('settings.company_name'));
        $this->assertNull(Cache::get(self::RESTART), 'the queue workers are not restarted twice');
    }

    public function test_a_reload_meanwhile_cancels_it(): void
    {
        $this->save('Seeded');
        SettingsProvider::reload();
        config(['settings.company_name' => 'set by the test']);

        app()->terminate();

        $this->assertSame('set by the test', config('settings.company_name'));
    }
}
