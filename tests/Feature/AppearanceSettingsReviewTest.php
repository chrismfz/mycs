<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Staff;
use App\Providers\SettingsProvider;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Review of refactor/remove-theme-system. */
class AppearanceSettingsReviewTest extends TestCase
{
    use RefreshDatabase;

    private Staff $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.default_currency' => 'USD', 'settings.system_email_address' => 'billing@example.test']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
    }

    /** R-1: the Reset Colors action was a hint action of the old `theme` select; color pickers have no hintActions. */
    public function test_reset_colors_is_still_offered_and_resets(): void
    {
        $page = $this->livewireAs($this->owner)->test(Settings::class)
            ->assertSee('Reset Colors');

        $field = collect($page->instance()->form->getFlatComponents(withHidden: true))
            ->first(fn ($component) => $component instanceof Field && $component->getName() === 'theme_primary');
        $page->set('data.theme_primary', 'hsl(10, 50%, 50%)')
            ->callAction(TestAction::make('resetColors')->schemaComponent(Str::after($field->getKey(), 'form.'))) // a hint action of the field
            ->assertSet('data.theme_primary', 'hsl(229, 100%, 64%)');
    }

    /** The Files field: uploads land on the public disk under brand/, stored as an array setting, and round-trip. */
    public function test_files_upload_saves_and_round_trips(): void
    {
        Storage::fake('public');

        $this->livewireAs($this->owner)->test(Settings::class)
            ->set('data.theme_assets', [UploadedFile::fake()->image('logo.png'), UploadedFile::fake()->image('hero.webp')])
            ->call('save')
            ->assertHasNoErrors();

        $row = Setting::query()->whereNull('settingable_type')->where('key', 'theme_assets')->first();
        $this->assertNotNull($row, 'theme_assets saved');
        $this->assertSame('array', $row->type);
        $stored = $row->value;
        $this->assertIsArray($stored);
        $this->assertEqualsCanonicalizing(['brand/logo.png', 'brand/hero.webp'], array_values($stored));
        Storage::disk('public')->assertExists('brand/logo.png');
        Storage::disk('public')->assertExists('brand/hero.webp');

        // Reload as after a request: the page loads the stored list and saving again changes nothing.
        Setting::create(['key' => 'system_email_address', 'value' => 'billing@example.test']); // required, not in the test seed
        SettingsProvider::flushCache();
        $this->assertEqualsCanonicalizing(['brand/logo.png', 'brand/hero.webp'], array_values(config('settings.theme_assets')));
        $page = $this->livewireAs($this->owner)->test(Settings::class);
        $this->assertCount(2, $page->get('data.theme_assets'));
        $page->call('save')->assertHasNoErrors();
        $this->assertEqualsCanonicalizing(['brand/logo.png', 'brand/hero.webp'],
            array_values(Setting::query()->whereNull('settingable_type')->where('key', 'theme_assets')->first()->value));
    }

    /** The seeder's [] default for the array setting loads and saves without touching it. */
    public function test_seeded_empty_files_setting_loads_and_saves(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertSame('[]', DB::table('settings')->where('key', 'theme_assets')->value('value'));
        config(['settings.system_email_address' => 'billing@example.test']);

        $this->livewireAs($this->owner)->test(Settings::class)
            ->set('data.theme_custom_css', '.x{}')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('[]', DB::table('settings')->where('key', 'theme_assets')->value('value'));
    }

    /** Fonts are in the accepted list: a woff2 file must be accepted (server-side MIME guess). */
    public function test_a_woff2_font_is_accepted(): void
    {
        Storage::fake('public');
        // wOF2 magic bytes, as a real font starts
        $font = UploadedFile::fake()->createWithContent('brand.woff2', "wOF2\x00\x01\x00\x00" . str_repeat("\x00", 64));

        $this->livewireAs($this->owner)->test(Settings::class)
            ->set('data.theme_assets', [$font])
            ->call('save')
            ->assertHasNoErrors();

        Storage::disk('public')->assertExists('brand/brand.woff2');
    }

    /**
     * Migration down(): the theme_default_ prefix goes back only on the settings that had it. A setting of another
     * theme that up() left alone (theme_other_x) must not become theme_default_other_x.
     */
    public function test_migration_down_leaves_other_themes_settings_alone(): void
    {
        $migration = require database_path('migrations/2026_10_25_000000_theme_settings_without_theme_name.php');
        DB::table('settings')->where('key', 'like', 'theme%')->delete();
        DB::table('settings')->insert([
            ['key' => 'theme', 'value' => 'default', 'type' => 'string'],
            ['key' => 'theme_default_primary', 'value' => 'hsl(10, 50%, 50%)', 'type' => 'string'],
            ['key' => 'theme_other_primary', 'value' => 'hsl(1, 1%, 1%)', 'type' => 'string'],
        ]);

        $migration->up();
        $migration->down();

        $keys = DB::table('settings')->whereNull('settingable_type')->pluck('value', 'key');
        $this->assertSame('hsl(10, 50%, 50%)', $keys['theme_default_primary']);
        $this->assertSame('default', $keys['theme']);
        $this->assertTrue($keys->has('theme_other_primary'), 'another theme\'s setting keeps its key across up+down');
        $this->assertFalse($keys->has('theme_default_other_primary'));
    }

    /** Migration up() over a key already present (seeded with the new code before migrate): the staff value is lost. */
    public function test_migration_up_keeps_the_staff_value_when_the_new_key_already_exists(): void
    {
        $migration = require database_path('migrations/2026_10_25_000000_theme_settings_without_theme_name.php');
        DB::table('settings')->where('key', 'like', 'theme%')->delete();
        DB::table('settings')->insert([
            ['key' => 'theme', 'value' => 'default', 'type' => 'string'],
            ['key' => 'theme_default_primary', 'value' => 'hsl(10, 50%, 50%)', 'type' => 'string'], // staff's
            ['key' => 'theme_primary', 'value' => 'hsl(229, 100%, 64%)', 'type' => 'string'],     // a default
        ]);

        $migration->up();

        $keys = DB::table('settings')->whereNull('settingable_type')->pluck('value', 'key');
        $this->assertSame('hsl(10, 50%, 50%)', $keys['theme_primary']);
    }

    /** Guard (passes): the Files field keeps the client's file name; an image named .php must not land in the web root. */
    public function test_files_are_stored_only_with_an_extension_matching_their_type(): void
    {
        Storage::fake('public');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $polyglot = UploadedFile::fake()->createWithContent('x.php', $png . '<?php echo "pwned"; ?>');

        $this->livewireAs($this->owner)->test(Settings::class)
            ->set('data.theme_assets', [$polyglot])
            ->call('save');

        $this->assertSame([], array_values(array_filter(Storage::disk('public')->allFiles(), fn ($f) => preg_match('/\\.(php|phtml|phar|html?)$/i', $f))),
            'a .php/.html file was stored under the public disk');
    }
}
