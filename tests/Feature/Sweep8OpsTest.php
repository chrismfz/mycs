<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFromWhmcs;
use App\Jobs\Server\CreateJob;
use App\Jobs\Server\SuspendJob;
use App\Models\Service;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Sweep 8, «ops» (adversarial verifier, main @ 6f4b6e29). What the instance shares with its host and what outlives the
 * database: the Redis/cache prefixes, the queue and the cache across the import's reset, the file cache's expired files.
 */
class Sweep8OpsTest extends TestCase
{
    use RefreshDatabase;

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        $this->scratch = sys_get_temp_dir() . '/sweep8-ops-' . getmypid() . '-' . bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->scratch);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);
        parent::tearDown();
    }

    /** config/<file>.php as an install with this environment computes it (APP_NAME as .env.example ships it). */
    private function configWith(string $file, array $env): array
    {
        $keys = ['APP_NAME', 'DB_DATABASE', 'APP_URL', 'REDIS_PREFIX', 'CACHE_PREFIX', 'REDIS_DB', 'REDIS_CACHE_DB'];
        $before = [];
        foreach ($keys as $key) {
            $before[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
        foreach ($env as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
        try {
            return require base_path("config/{$file}.php");
        } finally {
            foreach ($before as $key => [$env_, $server, $put]) {
                unset($_ENV[$key], $_SERVER[$key]);
                putenv($key);
                if ($env_ !== null) {
                    $_ENV[$key] = $env_;
                }
                if ($server !== null) {
                    $_SERVER[$key] = $server;
                }
                if ($put !== false) {
                    putenv("{$key}={$put}");
                }
            }
        }
    }

    /**
     * S8-OPS-1: two installs made as docs/install.md says (.env.example, then only DB_*, APP_URL, MAIL_*) on one host's
     * Redis: the same key prefix for the queue, the settings cache, the cron's lock and the scheduler's mutexes.
     */
    public function test_ops1_two_installs_from_the_example_env_do_not_share_their_redis_and_cache_keys(): void
    {
        $example = [];
        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m)) {
                $example[$m[1]] = $m[2];
            }
        }
        $this->assertSame('redis', $example['CACHE_STORE'] ?? null, 'the example ships Redis for the cache');
        $this->assertSame('redis', $example['QUEUE_CONNECTION'] ?? null, 'and for the queue');
        $shipped = array_intersect_key($example, array_flip(['APP_NAME', 'REDIS_PREFIX', 'CACHE_PREFIX', 'REDIS_DB', 'REDIS_CACHE_DB']));

        $one = $shipped + ['DB_DATABASE' => 'mycs_one', 'APP_URL' => 'https://one.example.test'];
        $two = $shipped + ['DB_DATABASE' => 'mycs_two', 'APP_URL' => 'https://two.example.test'];

        $redis = fn (array $env) => ($config = $this->configWith('database', $env))['redis']['options']['prefix']
            . '|db' . $config['redis']['default']['database'];
        $cache = fn (array $env) => $this->configWith('cache', $env)['prefix'] . '|db' . $this->configWith('database', $env)['redis']['cache']['database'];

        // The scheduler's mutex of the daily run: the same in both (no path in it), so only the prefix keeps them apart.
        $mutex = collect(app(Schedule::class)->events())->first(fn ($event) => str_ends_with((string) $event->command, 'app:cron-job'));
        $this->assertNotNull($mutex);
        $this->assertStringNotContainsString(base_path(), (string) $mutex->command, 'the mutex name carries nothing of the install');

        $this->assertNotSame($redis($one), $redis($two), 'the queue (queues:default) of one install is not the other\'s');
        $this->assertNotSame($cache($one), $cache($two), 'settings.rows, the app:cron-job lock and the schedule mutexes of one install are not the other\'s');
    }

    private function resetAsTheImportDoes(): void
    {
        $command = app(ImportFromWhmcs::class);
        $command->setLaravel($this->app);
        $kernel = $this->app->make(Kernel::class);
        $command->setApplication((new ReflectionMethod($kernel, 'getArtisan'))->invoke($kernel));
        $command->setInput(new ArrayInput(['dbname' => 'whmcs_copy'], $command->getDefinition()));
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        (new ReflectionMethod($command, 'prepareDatabase'))->invoke($command);
    }

    /** A queue that is not in the instance's database, as Redis (.env.example) is: its own SQLite file. */
    private function queueOutsideTheDatabase(): void
    {
        touch($file = $this->scratch . '/queue.sqlite');
        config(['database.connections.outside' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false],
            'queue.connections.outside' => ['driver' => 'database', 'connection' => 'outside', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]]);
        Schema::connection('outside')->create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    /**
     * S8-OPS-2: a create of a test service waits in the queue (a retry's backoff, a worker that was off). The final import
     * empties the database and keeps WHMCS's ids: the job now names a customer's imported service, a pending order nobody
     * paid, and the worker starts it.
     */
    public function test_ops2_a_job_queued_before_the_import_does_not_run_on_the_imported_row_with_its_id(): void
    {
        $this->queueOutsideTheDatabase();

        $product = $this->createProduct();
        $test = Service::factory()->create(['client_id' => User::factory()->create()->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => Service::STATUS_PENDING, 'price' => 10, 'quantity' => 1]);
        $id = (int) $test->id;
        CreateJob::dispatch($test)->onConnection('outside');
        $this->assertSame(1, Queue::connection('outside')->size(), 'the test service\'s create waits in the queue');

        $this->resetAsTheImportDoes();
        $this->assertSame(0, Service::query()->count(), 'the database is empty');

        // What the import then brings under the same id: another customer's order, pending and unpaid in WHMCS.
        $product = $this->createProduct();
        $imported = Service::factory()->create(['id' => $id, 'client_id' => User::factory()->create()->client()->id, 'product_id' => $product->product->id,
            'plan_id' => $product->plan->id, 'status' => Service::STATUS_PENDING, 'price' => 10, 'quantity' => 1]);
        $this->assertSame($id, (int) $imported->id);

        $waiting = Queue::connection('outside')->size();
        Artisan::call('queue:work', ['connection' => 'outside', '--once' => true, '--tries' => 1]);

        $this->assertSame(Service::STATUS_PENDING, $imported->fresh()->status,
            "the imported unpaid order #{$id} was started by a create queued for the service that had its id before the import");
        $this->assertSame(0, $waiting, 'no job of the database that was emptied is left in the queue after the import\'s reset');
    }

    /** S8-OPS-2: the cache's marks keyed by id outlive the reset too, and now speak of the imported rows. */
    public function test_ops2_the_cache_marks_of_the_emptied_database_do_not_outlive_the_imports_reset(): void
    {
        Cache::put(SuspendJob::neverSuspendedKey(120), true, now()->addDay());
        Cache::put('ticket-notice:120', true, now()->addDay());

        $this->resetAsTheImportDoes();

        $this->assertFalse(Cache::has(SuspendJob::neverSuspendedKey(120)),
            '«service #120 was never suspended on its server» was said of the service the import just replaced');
        $this->assertFalse(Cache::has('ticket-notice:120'));
    }

    /**
     * S8-OPS-3: on the file store (the devbox's, HANDOFF) a throttle's two files per visitor are deleted only when the
     * same visitor comes back after they expired. Nothing the scheduler runs removes them.
     */
    public function test_ops3_an_expired_throttle_key_of_a_visitor_who_never_returns_leaves_the_file_cache(): void
    {
        $path = $this->scratch . '/cache';
        File::ensureDirectoryExists($path);
        config(['cache.default' => 'file', 'cache.stores.file.path' => $path, 'cache.stores.file.lock_path' => $path, 'cache.limiter' => 'file']);
        Cache::forgetDriver('file');
        // The limiter as a process booted on the file store has it (its named limits are kept).
        (new \ReflectionProperty(RateLimiter::class, 'cache'))->setValue(app(RateLimiter::class), Cache::store('file'));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/clients/cart.php');
        $written = count($files = array_filter(File::allFiles($path), fn ($file) => !str_contains((string) file_get_contents($file->getPathname()), 'App\\Models')));
        $this->assertGreaterThan(0, $written, 'the throttle of the old WHMCS addresses wrote this visitor\'s keys to the file cache');
        foreach ($files as $file) {
            $this->assertLessThanOrEqual(now()->addMinutes(2)->getTimestamp(), (int) substr((string) file_get_contents($file->getPathname()), 0, 10), 'a key of a minute\'s window');
        }

        $this->travel(2)->hours();
        // Everything the scheduler has that tidies up (none reaches outside: they delete rows and files of this instance).
        foreach (app(Schedule::class)->events() as $event) {
            if (preg_match('/[a-z]+:[a-z-]*prune[a-z-]*|[a-z:-]*(?:retention|cleanup|clean-up|gc)[a-z:-]*/i', (string) $event->command, $m)) {
                Artisan::call(trim($m[0], "'\" "));
            }
        }

        $this->assertSame(0, count(array_filter(File::allFiles($path), fn ($file) => !str_contains((string) file_get_contents($file->getPathname()), 'App\\Models'))),
            "{$written} file(s) of a throttle window that ended two hours ago are still in the cache folder, and no scheduled task removes them");
    }
}
