<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** mycs:install: first install from the CLI (docs/install.md). */
class InstallTest extends TestCase
{
    use RefreshDatabase;

    private const OPTIONS = [
        '--company' => 'Test Hosting',
        '--url' => 'https://my.example.test/',
        '--first-name' => 'Test',
        '--last-name' => 'Admin',
        '--email' => 'admin@example.test',
        '--no-interaction' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // A fresh instance: the suite's seed is undone inside this test's transaction.
        Setting::query()->delete();
        Currency::query()->delete();
        Role::query()->delete();
        putenv('MYCS_ADMIN_PASSWORD=Secret123');
    }

    protected function tearDown(): void
    {
        putenv('MYCS_ADMIN_PASSWORD');
        parent::tearDown();
    }

    private function setting(string $key): mixed
    {
        return Setting::query()->where('key', $key)->whereNull('settingable_type')->first()?->value;
    }

    public function test_a_fresh_install_creates_the_admin_and_the_company_settings(): void
    {
        $this->artisan('mycs:install', self::OPTIONS)->assertSuccessful();

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->assertSame('admin', $admin->role?->name);
        $this->assertTrue(Hash::check('Secret123', $admin->password));
        $this->assertNotNull($admin->email_verified_at);

        $this->assertSame('Test Hosting', $this->setting('company_name'));
        $this->assertSame('https://my.example.test', $this->setting('app_url'));
        $this->assertSame('el', $this->setting('app_language'));
        $this->assertSame(['el', 'en'], $this->setting('allowed_languages'));
        $this->assertSame('en', $this->setting('admin_language'));
        $this->assertSame('Europe/Athens', $this->setting('timezone'));
        $this->assertSame('EUR', $this->setting('default_currency'));
        // The chosen currency replaces the seed's USD default.
        $this->assertSame(['EUR'], Currency::query()->pluck('code')->all());
        $this->assertSame('1.000,00', Currency::query()->find('EUR')?->format);
    }

    public function test_an_installed_instance_is_refused_without_force(): void
    {
        $this->artisan('mycs:install', self::OPTIONS)->assertSuccessful();

        $this->artisan('mycs:install', ['--email' => 'second@example.test'] + self::OPTIONS)->assertFailed();
        $this->assertSame(1, User::query()->count());

        $this->artisan('mycs:install', ['--email' => 'second@example.test', '--force' => true] + self::OPTIONS)->assertSuccessful();
        $this->assertSame(2, User::query()->count());
    }

    public function test_force_never_resets_an_existing_admins_password(): void
    {
        $this->artisan('mycs:install', self::OPTIONS)->assertSuccessful();
        putenv('MYCS_ADMIN_PASSWORD=Another456');

        $this->artisan('mycs:install', ['--force' => true] + self::OPTIONS)->assertSuccessful();

        $this->assertTrue(Hash::check('Secret123', User::query()->firstOrFail()->password));
    }

    public function test_a_weak_or_missing_password_is_refused(): void
    {
        putenv('MYCS_ADMIN_PASSWORD=short');
        $this->artisan('mycs:install', self::OPTIONS)->assertFailed();

        putenv('MYCS_ADMIN_PASSWORD');
        $this->artisan('mycs:install', self::OPTIONS)->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_bad_input_is_refused_before_anything_is_written(): void
    {
        $this->artisan('mycs:install', ['--url' => 'my.example.test'] + self::OPTIONS)->assertFailed();
        $this->artisan('mycs:install', ['--currency' => 'GBP'] + self::OPTIONS)->assertFailed();
        $this->artisan('mycs:install', ['--language' => 'xx'] + self::OPTIONS)->assertFailed();

        $this->assertSame(0, User::query()->count());
        $this->assertNull($this->setting('company_name'));
    }
}
