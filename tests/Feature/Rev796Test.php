<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Models\ClientUser;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;
use Throwable;

/** Independent code review of #796 (small safety fixes of sweep 8): failing until fixed. */
class Rev796Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
    }

    /**
     * REV-796-1: EnsureClientAccount::onInvitation() reads Livewire::originalPath(), which believes any request that
     * carries an `X-Livewire` header (HandleRequests::isLivewireRequest) and then takes the path from the request's own
     * `components.0.snapshot`, before anything checks that snapshot's checksum. So «I am on /invite» is the caller's word,
     * on any page of the client area, not only on Livewire's update route: a login without an account keeps itself
     * without one everywhere, and the pages that count on «the client area always works on an account» (the middleware's
     * own docblock) are reached with none. The dashboard answers 500 (Summary::__construct(null)).
     */
    public function test_rev_796_1_a_request_that_only_claims_to_come_from_the_invitation_still_gets_its_account(): void
    {
        $user = User::factory()->withoutClient()->create();
        $claim = http_build_query(['components' => [['snapshot' => json_encode(['memo' => ['path' => 'invite']])]]]);

        $response = $this->actingAs($user)->withSession($this->loginUser($user))
            ->withHeaders(['X-Livewire' => '1'])->get(route('dashboard') . '?' . $claim);

        $this->assertSame(1, ClientUser::query()->where('user_id', $user->id)->where('owner', true)->count(),
            'the dashboard was served to a login without an account: the page it «came from» was taken from the request itself');
        $response->assertOk();
    }

    /**
     * REV-796-2: the queue and the cache are cleared between migrate:fresh and the seed. The application boots with its
     * cache store down (SettingsProvider, sweep SETTINGS-OPS-2: «Redis not up yet at a reboot»), so the import gets as far
     * as emptying the database and then stops on the first call to Redis: the instance's own settings, read before the
     * reset to be put back after the seed (INSTANCE_SETTINGS, F-CUTOVER-DAY-2), are gone with the process, and the run
     * after it finds none to keep (the seed's UTC and http://localhost then override .env). Before this PR nothing
     * outside the database could fail between the two.
     */
    public function test_rev_796_2_a_cache_that_cannot_be_flushed_does_not_cost_the_instance_its_own_settings(): void
    {
        foreach (['app_url' => 'https://billing.example.test', 'timezone' => 'Europe/Athens', 'company_name' => 'Example Hosting'] as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key, 'settingable_type' => null, 'settingable_id' => null],
                ['value' => $value, 'type' => 'string', 'encrypted' => false, 'updated_at' => now()]);
        }
        // The instance's cache store, unreachable when it is asked to empty itself (Redis down, a wrong REDIS_CACHE_DB).
        Cache::extend('down', fn () => Cache::repository(new class extends ArrayStore
        {
            public function flush()
            {
                throw new RuntimeException('Connection refused');
            }
        }));
        config(['cache.stores.down' => ['driver' => 'down'], 'cache.default' => 'down']);

        $command = app(ImportFromWhmcs::class);
        $command->setLaravel($this->app);
        $kernel = $this->app->make(Kernel::class);
        $command->setApplication((new ReflectionMethod($kernel, 'getArtisan'))->invoke($kernel));
        $command->setInput(new ArrayInput(['dbname' => 'whmcs_copy'], $command->getDefinition()));
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        try {
            (new ReflectionMethod($command, 'prepareDatabase'))->invoke($command);
        } catch (Throwable) {
            // Stopping is fine: what it leaves behind is the question.
        }
        config(['cache.default' => 'array']);

        // Either nothing was emptied (cleared first, or not at all), or the reset went on and put them back.
        $kept = Schema::hasTable('settings')
            ? DB::table('settings')->whereNull('settingable_type')->whereIn('key', ['app_url', 'timezone', 'company_name'])->pluck('value', 'key')->all()
            : [];
        $this->assertSame(['app_url' => 'https://billing.example.test', 'company_name' => 'Example Hosting', 'timezone' => 'Europe/Athens'],
            collect($kept)->sortKeys()->all(),
            'the database was emptied, then the cache could not be flushed: the instance\'s own settings are lost and the next run seeds the defaults');
    }
}
