<?php

namespace Tests\Feature;

use App\Http\Middleware\AdminLocale;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

/** The Greek client area (lang/el) and the separate admin language (docs/glossary-el.md). */
class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function strings(string $locale, string $file): array
    {
        return Arr::dot(require base_path("lang/{$locale}/{$file}.php"));
    }

    public function test_greek_has_every_english_key_and_no_stray_ones(): void
    {
        foreach (glob(base_path('lang/en/*.php')) as $path) {
            $file = basename($path, '.php');
            $this->assertFileExists(base_path("lang/el/{$file}.php"));

            $en = array_keys(Arr::where($this->strings('en', $file), fn ($v, $k) => !str_starts_with($k, 'attributes')));
            // Greek field names for validation messages have no English counterpart.
            $el = array_keys(Arr::where($this->strings('el', $file), fn ($v, $k) => !str_starts_with($k, 'attributes')));

            $this->assertSame([], array_values(array_diff($en, $el)), "lang/el/{$file}.php: missing keys");
            $this->assertSame([], array_values(array_diff($el, $en)), "lang/el/{$file}.php: keys not in English");
        }
    }

    public function test_greek_keeps_every_placeholder_and_plural_form(): void
    {
        foreach (glob(base_path('lang/en/*.php')) as $path) {
            $file = basename($path, '.php');
            $el = $this->strings('el', $file);
            foreach ($this->strings('en', $file) as $key => $english) {
                if (!is_string($english)) {
                    continue; // e.g. the empty validation 'attributes'
                }
                preg_match_all('/:[a-z_]+/', (string) $english, $m);
                preg_match_all('/:[a-z_]+/', (string) $el[$key], $g);
                $this->assertEqualsCanonicalizing($m[0], $g[0], "lang/el/{$file}.php [{$key}]: placeholders differ");
                $this->assertSame(substr_count((string) $english, '|'), substr_count((string) $el[$key], '|'), "lang/el/{$file}.php [{$key}]: plural forms differ");
            }
        }
    }

    public function test_every_client_area_sentence_has_a_greek_translation(): void
    {
        $greek = json_decode(file_get_contents(base_path('lang/el.json')), true, flags: JSON_THROW_ON_ERROR);
        $missing = [];
        foreach (['app/Livewire', 'app/Http', 'resources/views'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'\\)/", file_get_contents($file->getPathname()), $m);
                foreach ($m[1] as $raw) {
                    $text = stripslashes($raw);
                    // A sentence (JSON key), not a group key like services.cancel.
                    if ((str_contains($text, ' ') || ctype_upper($text[0] ?? '')) && !isset($greek[$text])) {
                        $missing[] = $text;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Add these to lang/el.json');
    }

    public function test_the_login_page_renders_in_greek(): void
    {
        $this->withSession(['locale' => 'el'])->get(route('login'))
            ->assertOk()
            ->assertSee('Συνδεθείτε στον λογαριασμό σας');
    }

    public function test_greek_is_offered_to_customers(): void
    {
        $this->assertSame('Ελληνικά', config('app.available_locales.el'));
    }

    public function test_the_admin_uses_its_own_language_not_the_visitors(): void
    {
        App::setLocale('el'); // e.g. the client-area default or the visitor's choice
        config(['settings.admin_language' => 'en']);

        (new AdminLocale)->handle(Request::create('/sysadmin'), fn () => response('ok'));
        $this->assertSame('en', App::getLocale());

        config(['settings.admin_language' => 'el']);
        (new AdminLocale)->handle(Request::create('/sysadmin'), fn () => response('ok'));
        $this->assertSame('el', App::getLocale());
    }

    public function test_the_admin_language_also_covers_livewire_updates(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertContains(AdminLocale::class, $panel->getMiddleware());
        $this->assertContains(AdminLocale::class, Livewire::getPersistentMiddleware());
    }
}
