<?php

namespace Tests\Feature;

use App\Classes\Settings;
use App\Jobs\Domains\SendDomainOwnerChangeJob;
use App\Models\Setting;
use App\Models\TaxRate;
use App\Providers\SettingsProvider;
use App\Services\Tax\EuVatRates;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sweep 2026-11-07, area «settings-ops»: adversarial verification. Each test fails on main @ 00268d7f and describes the
 * behaviour expected once its finding is fixed.
 */
class SweepSettingsOpsTest extends TestCase
{
    use RefreshDatabase;

    private const RESTART = 'illuminate:queue:restart';

    /**
     * SETTINGS-OPS-1 (booleans): `app:settings:change` on a boolean whose row does not exist yet (a release added it,
     * the seeder only runs at install) creates it as type 'string': 'false' is kept as the string, and (bool) 'false'
     * is true. The switch the operator turned off is on.
     */
    public function test_settings_ops_1_change_false_on_a_missing_boolean_row_turns_it_off(): void
    {
        Setting::query()->where('key', 'mail_disable')->whereNull('settingable_type')->delete();

        $this->artisan('app:settings:change', ['key' => 'mail_disable', 'value' => 'false']);
        SettingsProvider::reload();

        $this->assertFalse((bool) config('settings.mail_disable'), 'the operator asked for false: it must be off');
    }

    /**
     * SETTINGS-OPS-1 (secrets): `app:settings:change ekdosi_secret …` on a missing row stores the bridge HMAC secret in
     * clear (encrypted=false), and the console audit keeps it in clear too (redacted only when encrypted).
     */
    public function test_settings_ops_1_change_of_a_missing_secret_row_is_stored_encrypted_and_not_audited_in_clear(): void
    {
        $plain = 'sweep-test-not-a-real-secret-0123456789';
        Setting::query()->where('key', 'ekdosi_secret')->whereNull('settingable_type')->delete();

        $this->artisan('app:settings:change', ['key' => 'ekdosi_secret', 'value' => $plain]);

        $row = DB::table('settings')->where('key', 'ekdosi_secret')->whereNull('settingable_type')->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->encrypted, 'ekdosi_secret is an encrypted setting: stored encrypted');
        $this->assertNotSame($plain, $row->value, 'the secret is in the settings table in clear');
        $audits = DB::table('audits')->where('auditable_type', Setting::class)->pluck('new_values')->implode("\n");
        $this->assertStringNotContainsString($plain, $audits, 'the secret is in the audits table in clear');
    }

    /**
     * SETTINGS-OPS-2: the settings cache failing at boot (Redis not up yet) is neither fatal nor worked around: the
     * rows are not read from the database, config('settings') stays empty, and a long-lived worker runs on defaults.
     */
    public function test_settings_ops_2_a_cache_failure_at_boot_still_loads_the_settings_from_the_database(): void
    {
        Setting::query()->updateOrCreate(['key' => 'company_name', 'settingable_type' => null], ['value' => 'Sweep DB Co']);
        Cache::extend('sweep-down', fn () => Cache::repository(new class extends ArrayStore
        {
            public function get($key)
            {
                throw new \RuntimeException('Connection refused'); // what phpredis throws while Redis is down
            }
        }));
        config(['cache.stores.sweep-down' => ['driver' => 'sweep-down']]);
        Cache::setDefaultDriver('sweep-down');
        config(['settings' => []]);

        SettingsProvider::getSettings(true);

        $this->assertSame('Sweep DB Co', config('settings.company_name'), 'the worker runs on code defaults');
    }

    /**
     * SETTINGS-OPS-3: `eu-vat:seed` writes tax_home_country, then calls the tests-only reload(), which cancels the
     * pending flush: the queue workers are never restarted and keep tax_home_country = null (0% for an account without
     * a country, now that there are many rates).
     */
    public function test_settings_ops_3_seed_keeping_the_home_country_restarts_the_queue_workers(): void
    {
        Setting::query()->whereIn('key', ['tax_home_country', 'eu_vat_home_country'])->whereNull('settingable_type')->delete();
        SettingsProvider::reload();
        TaxRate::query()->create(['name' => 'VAT GR', 'rate' => 24, 'country' => 'GR']);
        Cache::forget(self::RESTART);

        $rates = app(EuVatRates::class);
        $rates->seed();
        app()->terminate();

        $this->assertSame('GR', $rates->keptHomeCountry, 'precondition: the seed kept the home country');
        $this->assertNotNull(Cache::get(self::RESTART), 'the queue workers are restarted to read the kept home country');
    }

    /**
     * SETTINGS-OPS-4: the owner-change job's timeout (660 s) is above the queue's retry_after (300 s): a run past 300 s
     * is reserved again by another worker and failed (MaxAttemptsExceeded, tries = 1) while the first still runs.
     */
    public function test_settings_ops_4_owner_change_job_timeout_is_under_the_queue_retry_after(): void
    {
        $this->markTestIncomplete('SETTINGS-OPS-4, open for the owner: a timeout under 300 s kills a slow registry call'
            . ' mid-send (left «sending», then «unknown», its log unwritten), and a retry_after above 660 s delays every'
            . ' job a dead worker held; today the worst is a spurious failed_jobs row, never a second send.');
        $job = new SendDomainOwnerChangeJob(1);
        foreach (['database', 'redis'] as $connection) {
            $this->assertLessThan((int) config("queue.connections.$connection.retry_after"), $job->timeout,
                "$connection: retry_after must be above the job's timeout, or the job is taken again while it runs");
        }
    }

    /**
     * SETTINGS-OPS-6: deploy/update.sh runs composer's artisan scripts and `migrate --force` under the previous release's
     * cached config and events; the caches are only rebuilt at step 9 (`optimize`). They must be cleared before.
     */
    public function test_settings_ops_6_deploy_clears_the_old_caches_before_composer_and_migrate(): void
    {
        $script = (string) file_get_contents(base_path('deploy/update.sh'));
        $composer = strpos($script, '$COMPOSER install');
        $this->assertNotFalse($composer);
        $before = substr($script, 0, $composer);

        $this->assertSame(1, preg_match('/\$ART\s+(optimize:clear|config:clear)/', $before),
            'the old release\'s bootstrap/cache config/events are still in place while composer scripts and migrations run');
    }

    /** SETTINGS-OPS-1 (fixer): the command changes the global setting only, never an extension's of the same key. */
    public function test_settings_ops_1_change_leaves_an_extension_setting_of_the_same_key_alone(): void
    {
        $extension = Setting::query()->create(['key' => 'company_name', 'value' => 'Extension own', 'type' => 'string',
            'settingable_type' => 'App\\Models\\Extension', 'settingable_id' => 1]);

        $this->artisan('app:settings:change', ['key' => 'company_name', 'value' => 'Global Co']);

        $this->assertSame('Extension own', $extension->fresh()->value);
        $this->assertSame('Global Co', Setting::query()->where('key', 'company_name')->whereNull('settingable_type')->value('value'));
    }

    /** SETTINGS-OPS-1 (fixer): a key with no definition keeps working as before (a string, not encrypted). */
    public function test_settings_ops_1_change_of_an_unknown_key_is_stored_as_given(): void
    {
        $this->artisan('app:settings:change', ['key' => 'sweep_unknown_key', 'value' => 'plain']);

        $row = DB::table('settings')->where('key', 'sweep_unknown_key')->whereNull('settingable_type')->first();
        $this->assertSame('plain', $row->value);
        $this->assertFalse((bool) $row->encrypted);
    }

    /**
     * SETTINGS-OPS-5 (unproven, guarded): under the lock the stored pair is read again, so a visit whose config was read
     * before another visit stored a pair keeps that pair instead of writing half of a second one.
     */
    public function test_settings_ops_5_a_pair_stored_meanwhile_is_kept(): void
    {
        $this->assertTrue(Settings::validateOrCreateVapidKeys());
        $stored = Setting::query()->whereNull('settingable_type')->whereIn('key', ['vapid_public_key', 'vapid_private_key'])->get()->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value]);
        $this->assertCount(2, $stored);
        $this->assertTrue((bool) DB::table('settings')->where('key', 'vapid_private_key')->value('encrypted'));

        config(['settings.vapid_public_key' => null, 'settings.vapid_private_key' => null]); // this process read before the write
        $this->assertTrue(Settings::validateOrCreateVapidKeys());

        $again = Setting::query()->whereNull('settingable_type')->whereIn('key', ['vapid_public_key', 'vapid_private_key'])->get()->mapWithKeys(fn (Setting $setting) => [$setting->key => $setting->value]);
        $this->assertSame($stored->all(), $again->all(), 'the pair stored meanwhile was replaced');
    }

    /** SETTINGS-OPS-2 (fixer): the cache store failing on put after a database read still leaves the settings loaded. */
    public function test_settings_ops_2_a_cache_failing_on_write_still_loads_the_settings(): void
    {
        Setting::query()->updateOrCreate(['key' => 'company_name', 'settingable_type' => null], ['value' => 'Sweep Put Co']);
        Cache::extend('sweep-put-down', fn () => Cache::repository(new class extends ArrayStore
        {
            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('Connection refused');
            }

            public function forever($key, $value)
            {
                throw new \RuntimeException('Connection refused');
            }
        }));
        config(['cache.stores.sweep-put-down' => ['driver' => 'sweep-put-down']]);
        Cache::setDefaultDriver('sweep-put-down');
        config(['settings' => []]);

        SettingsProvider::getSettings(true);

        $this->assertSame('Sweep Put Co', config('settings.company_name'));
    }
}
