<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * PHP runs only for public/index.php and public/zlic.php (public/.htaccess, deploy/nginx.conf.example). A route that
 * ends in .php (the old PayPal IPN) is a route of the application, never a file of its own: a file there would be
 * refused by the web server, and would see the wrong base path.
 */
class PublicPhpFilesTest extends TestCase
{
    public function test_public_has_no_php_file_but_the_front_controller_and_the_licence_endpoint(): void
    {
        $files = [];
        foreach (Finder::create()->files()->in(public_path())->exclude(['build', 'storage'])->name('/\.ph(p[0-9]?|tml|ar|t|ps)$/i')->ignoreDotFiles(false) as $file) {
            $files[] = $file->getRelativePathname();
        }
        sort($files);

        $this->assertSame(['index.php', 'zlic.php'], $files);
    }

    /**
     * Apache/LiteSpeed: refused by the file a request maps to, in any case. A rule on the URI misses /x.php/anything
     * (PATH_INFO) and x.PHP, and Apache runs both (review of #317).
     */
    public function test_the_apache_rules_look_at_the_file_in_any_case(): void
    {
        foreach (['public/.htaccess', 'storage/app/public/.htaccess'] as $file) {
            $rules = (string) file_get_contents(base_path($file));
            $this->assertMatchesRegularExpression('~RewriteCond %\{REQUEST_FILENAME\} \\\.\(?ph.*\[NC\]~', $rules, $file);
        }
        $this->assertStringContainsString('cgi|pl|py|sh|shtml', (string) file_get_contents(base_path('storage/app/public/.htaccess')));
    }
}
