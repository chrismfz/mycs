<?php

namespace Tests\Feature;

use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Providers\SettingsProvider;
use App\Services\Ops\Health;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use MyCS\Extensions\Gateways\Cardlink\Cardlink;
use Nixpal\CardlinkEurobank\Tests\Fixtures;
use Tests\TestCase;

/**
 * Pre-launch audit, flow 8 (ops and settings, docs/prelaunch-audit.md). Each test fails on main @ 279a5a69 and
 * describes the behaviour expected once its finding (AUD8-n) is fixed. The bash findings of deploy/update.sh have no
 * test here: they are described with their lines in the PR comment.
 */
class AuditFlow8Test extends TestCase
{
    use RefreshDatabase;

    /**
     * AUD8-1 (High): during a deploy (maintenance mode) the Cardlink vPOS return gets Laravel's 503, on both of its legs
     * (browser and server), while the bank has already captured the money. Nothing is recorded, no staff mail, no
     * gateway log: the invoice stays unpaid, reminders and suspension follow a paid service. zlic.php is excepted from
     * maintenance (bootstrap/app.php:42); the vPOS return, also a one-shot machine call, is not.
     */
    public function test_aud8_1_a_cardlink_capture_during_maintenance_is_not_lost(): void
    {
        Http::preventStrayRequests();
        Cache::flush();
        $config = ['merchant_id' => Fixtures::MID, 'shared_secret' => Fixtures::SECRET, 'test_mode' => '1'];
        $gateway = Gateway::query()->firstOrCreate(['extension' => 'Cardlink'], ['name' => 'Cardlink', 'type' => 'gateway']);
        foreach ($config as $key => $value) {
            $gateway->settings()->updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $cardlink = new Cardlink($config);
        $cardlink->boot();
        app('router')->getRoutes()->refreshNameLookups();

        $user = User::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $user->client()->id, 'status' => Invoice::STATUS_UNPAID, 'currency_code' => 'EUR', 'due_at' => now()->addWeek()]);
        $invoice->items()->create(['description' => 'Hosting', 'quantity' => 1, 'price' => 25]);
        $orderId = $cardlink->pay($invoice->fresh(), 25)->getData()['fields']['orderid'];

        // deploy/update.sh: `artisan down` ... migrate, npm build ... `artisan up` (minutes).
        $down = new class implements MaintenanceMode
        {
            public bool $on = true;

            public function activate(array $payload): void
            {
                $this->on = true;
            }

            public function deactivate(): void
            {
                $this->on = false;
            }

            public function active(): bool
            {
                return $this->on;
            }

            public function data(): array
            {
                return ['retry' => 15];
            }
        };
        $this->app->instance(MaintenanceMode::class, $down);

        foreach (['browser', 'modirum'] as $leg) { // the customer's browser, then the vPOS's own server call
            $fields = Fixtures::capture(['orderid' => $orderId, 'orderAmount' => '25.00', 'paymentTotal' => '25.00'], $leg);
            $this->post('/extensions/cardlink/return?o=' . $orderId, $fields);
        }
        $down->deactivate(); // the deploy is over; the vPOS does not call again

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status,
            'a payment the vPOS captured while the app was in maintenance must not be lost (it answered 503 to both legs)');
    }

    /**
     * AUD8-2 (Medium): `php artisan app:settings:change cronjob_terminate_enabled false` keeps terminations ON. The
     * command writes the string as given; the row's type (boolean) makes Setting\Saving cast it, and (bool) 'false' is
     * true. Nothing is printed back. The same for every boolean (suspensions, mail_disable, staff 2FA...).
     */
    public function test_aud8_2_settings_change_false_turns_a_boolean_off(): void
    {
        Setting::query()->updateOrCreate(['key' => 'cronjob_terminate_enabled', 'settingable_type' => null], ['value' => true, 'type' => 'boolean']);

        $this->artisan('app:settings:change', ['key' => 'cronjob_terminate_enabled', 'value' => 'false']);
        SettingsProvider::reload();

        $this->assertFalse((bool) config('settings.cronjob_terminate_enabled'), 'the operator asked for false: terminations must be off');
    }

    /**
     * AUD8-3 (Medium): the settings' rows stay cached forever (SettingsProvider::getSettings, Cache::put without TTL),
     * and flushCache() ignores whether Cache::forget() worked. With CACHE_STORE=file a cache file in a folder the app's
     * user cannot write (artisan once run as root in the site dir, as on 2026-10-29) is not deleted, silently
     * (FileStore::forget → Filesystem::delete → @unlink): staff save «terminate off», the database says off, and the
     * cron, the workers and the settings page keep reading «on» from the cache.
     */
    public function test_aud8_3_a_cache_that_cannot_forget_does_not_keep_old_settings(): void
    {
        Cache::extend('cannot-forget', fn () => Cache::repository(new class extends ArrayStore
        {
            public function forget($key)
            {
                return false; // the file stays (FileStore::forget when unlink fails)
            }
        }));
        config(['cache.stores.cannot-forget' => ['driver' => 'cannot-forget']]);
        Cache::setDefaultDriver('cannot-forget');

        Setting::query()->updateOrCreate(['key' => 'cronjob_terminate_enabled', 'settingable_type' => null], ['value' => true, 'type' => 'boolean']);
        SettingsProvider::getSettings(true); // rows cached: terminate on
        $this->assertTrue((bool) config('settings.cronjob_terminate_enabled'));

        // Staff turn it off (the Settings page: a model save, flushed when the request ends).
        Setting::query()->where('key', 'cronjob_terminate_enabled')->whereNull('settingable_type')->first()->update(['value' => false]);
        SettingsProvider::flushCache();

        $this->assertFalse((bool) config('settings.cronjob_terminate_enabled'),
            'the setting saved off is still read on from a cache entry that could not be deleted, and nobody is told');
    }

    /**
     * AUD8-4 (Medium): Health's queue check counts only the `jobs` table. With QUEUE_CONNECTION=redis (.env.example's
     * value, which docs/install.md copies) the table is always empty: «0 queued, ok» with the worker dead and every
     * provisioning job and mail waiting. The check must look at the queue the app uses, or say it cannot.
     */
    public function test_aud8_4_the_queue_check_reads_the_queue_the_app_uses(): void
    {
        config([
            'queue.default' => 'redis',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 1, // nothing there: the queue cannot be read at all
        ]);

        $queue = Health::report()['checks']['queue'];

        $this->assertNotSame(Health::OK, $queue['status'], 'the queue the app uses cannot even be reached, yet Health says: ' . $queue['detail']);
    }

    /** AUD8-5, AUD8-6: workers asked to exit again after `up`; a smoke check that fails exits, never «Deployed». */
    public function test_aud8_5_6_the_deploy_restarts_workers_after_up_and_fails_on_a_red_smoke_check(): void
    {
        $script = (string) file_get_contents(base_path('deploy/update.sh'));
        $up = strpos($script, '$ART up');
        $this->assertNotFalse($up);
        $start = strpos($script, 'start_queue_worker', $up);
        $this->assertNotFalse($start, 'the worker is started after up');
        $this->assertMatchesRegularExpression('/restart\)\s+log "Queue: workers asked to exit again[^\n]*\$ART queue:restart/', $script);

        $smoke = substr($script, (int) strpos($script, '_code="$(curl'));
        $this->assertMatchesRegularExpression('/if \[\[ "\$_code" != "200" \]\]; then.*?exit 1.*?fi/s', $smoke);
        $this->assertLessThan(strpos($smoke, 'ok "Deployed'), strpos($smoke, 'exit 1'), '«Deployed» only after the smoke check passed');
        $this->assertStringContainsString('tr -d', $script, 'a quoted APP_URL is read without its quotes');
    }
}
