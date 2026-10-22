<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use Tests\TestCase;

/** deploy/private-files.sh: secrets and customer data to the app's own user, the web server's way to public/ kept. */
#[RequiresOperatingSystemFamily('Linux')]
class PrivateFilesScriptTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mycs-private-' . bin2hex(random_bytes(4));
        foreach (['bootstrap/cache', 'storage/logs', 'storage/framework/sessions', 'storage/app/public/brand', 'storage/app/invoices',
            'storage/app/tickets/uploads', 'storage/app/db-snapshots', 'public'] as $dir) {
            File::ensureDirectoryExists("{$this->root}/{$dir}", 0755);
        }
        foreach (['artisan', '.env', 'bootstrap/cache/config.php', 'storage/logs/laravel.log', 'storage/framework/sessions/s1',
            'storage/app/public/brand/logo.png', 'storage/app/invoices/7.pdf', 'storage/app/tickets/uploads/a.txt', 'public/index.php'] as $file) {
            File::put("{$this->root}/{$file}", 'x');
            chmod("{$this->root}/{$file}", 0644);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function mode(string $path): string
    {
        clearstatcache();

        return substr(sprintf('%o', fileperms("{$this->root}/{$path}")), -3);
    }

    public function test_secrets_and_customer_data_are_the_apps_alone_and_public_stays_served(): void
    {
        exec('bash ' . escapeshellarg(base_path('deploy/private-files.sh')) . ' ' . escapeshellarg($this->root) . ' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));

        foreach (['.env', 'bootstrap/cache/config.php'] as $secret) {
            $this->assertSame('600', $this->mode($secret), $secret);
        }
        foreach (['bootstrap/cache', 'storage/logs', 'storage/framework', 'storage/app/invoices', 'storage/app/tickets', 'storage/app/db-snapshots'] as $private) {
            $this->assertSame('700', $this->mode($private), $private);
        }
        foreach (['.', 'storage', 'storage/app'] as $passage) {
            $this->assertSame('711', $this->mode($passage), $passage);
        }
        // What the web server serves is as it was.
        $this->assertSame('755', $this->mode('storage/app/public/brand'));
        $this->assertSame('644', $this->mode('storage/app/public/brand/logo.png'));
        $this->assertSame('644', $this->mode('public/index.php'));
    }
}
