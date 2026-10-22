<?php

namespace Tests\Feature;

use App\Admin\Pages\Settings;
use App\Models\Role;
use App\Models\Staff;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Adversarial review of #317 (uploads: stricter file handling). */
class Adv317UploadsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The server-level locations of deploy/nginx.conf.example, in file order: [modifier, pattern, body].
     *
     * @return list<array{string, string, string}>
     */
    private function nginxLocations(): array
    {
        $lines = file(base_path('deploy/nginx.conf.example'), FILE_IGNORE_NEW_LINES) ?: [];
        $locations = [];
        for ($i = 0; $i < count($lines); $i++) {
            if (preg_match('#^ {4}location\s+(~\*|~|\^~|=)?\s*(\S+)\s*\{(.*)$#', $lines[$i], $m) !== 1) {
                continue;
            }
            $body = $m[3];
            $depth = substr_count($lines[$i], '{') - substr_count($lines[$i], '}');
            while ($depth > 0 && ++$i < count($lines)) {
                $body .= "\n" . $lines[$i];
                $depth += substr_count($lines[$i], '{') - substr_count($lines[$i], '}');
            }
            $locations[] = [$m[1], $m[2], $body];
        }

        return $locations;
    }

    /** nginx's choice for a URI, simplified: a matching `^~` prefix wins, else the first matching regex, else `location /`. */
    private function nginxLocationFor(string $uri): array
    {
        $locations = $this->nginxLocations();
        foreach ($locations as $location) {
            if ($location[0] === '^~' && str_starts_with($uri, $location[1])) {
                return $location;
            }
        }
        foreach ($locations as $location) {
            if (in_array($location[0], ['~', '~*'], true)
                && preg_match('~' . str_replace('~', '\~', $location[1]) . '~' . ($location[0] === '~*' ? 'i' : ''), $uri) === 1) {
                return $location;
            }
        }
        foreach ($locations as $location) {
            if ($location[0] === '' && $location[1] === '/') {
                return $location;
            }
        }
        $this->fail('no location for ' . $uri);
    }

    public function test_every_route_ending_in_php_still_reaches_the_application_under_nginx(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn (Route $route) => '/' . ltrim($route->uri(), '/'))
            ->filter(fn (string $uri) => str_ends_with($uri, '.php'))
            ->values()->all();
        $this->assertContains('/modules/gateways/callback/paypal.php', $uris);

        foreach ($uris as $uri) {
            [, , $body] = $this->nginxLocationFor($uri);
            $this->assertTrue(
                str_contains($body, 'fastcgi_pass') || str_contains($body, '/index.php'),
                "{$uri} (a route of the application) is answered by nginx itself, never by PHP: {$body}",
            );
        }
    }

    public function test_the_storage_location_keeps_the_sites_security_headers(): void
    {
        $config = (string) file_get_contents(base_path('deploy/nginx.conf.example'));
        preg_match_all('~^ {4}add_header\s+(\S+)~m', $config, $server);
        $storage = collect($this->nginxLocations())->first(fn (array $location) => $location[1] === '/storage/');
        $this->assertNotNull($storage);

        // nginx: a location with an add_header of its own inherits none of the server's.
        if (str_contains($storage[2], 'add_header')) {
            foreach ($server[1] as $header) {
                $this->assertStringContainsString($header, $storage[2], "{$header} is not sent for /storage/ responses");
            }
        }
    }

    public function test_two_theme_files_uploaded_together_are_both_kept(): void
    {
        config(['mail.default' => 'array', 'settings.mail_from_address' => 'noreply@myip.test', 'settings.default_currency' => 'USD',
            'settings.system_email_address' => 'billing@example.test']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $owner = Staff::factory()->create(['role_id' => Role::create(['name' => 'Owner', 'permissions' => ['*']])->id]);
        Storage::fake('public');
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        $this->livewireAs($owner)->test(Settings::class)
            ->set('data.theme_assets', [
                UploadedFile::fake()->createWithContent('Hero.png', $png . 'first'),
                UploadedFile::fake()->createWithContent('hero.png', $png . 'second'),
            ])
            ->call('save')
            ->assertHasNoErrors();

        $contents = array_map(fn (string $file) => Storage::disk('public')->get($file), Storage::disk('public')->allFiles('brand'));
        $this->assertCount(2, $contents, 'two different files, one name: the second overwrote the first');
    }
}
