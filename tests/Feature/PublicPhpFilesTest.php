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
}
