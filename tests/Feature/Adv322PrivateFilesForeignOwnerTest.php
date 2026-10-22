<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use Tests\TestCase;

/**
 * Adversarial review of #322: deploy/update.sh runs as the app's user (su - mycs, docs/install.md) and calls
 * deploy/private-files.sh under `set -e`. One file under storage/ owned by another user (a log or a PDF written by
 * `php artisan …` run as root, a snapshot of a root-run update) makes `chmod -R` fail, the script exit non-zero, and
 * the update stop with the site left in maintenance mode. The app itself still works with that file; the deploy must too.
 */
#[RequiresOperatingSystemFamily('Linux')]
class Adv322PrivateFilesForeignOwnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (trim((string) shell_exec('id -u')) !== '0' || trim((string) shell_exec('command -v runuser')) === '') {
            $this->markTestSkipped('needs root (to own one file as another user) and runuser');
        }
        $this->root = sys_get_temp_dir() . '/mycs-adv322-' . bin2hex(random_bytes(4));
        foreach (['bootstrap/cache', 'storage/logs', 'storage/framework/sessions', 'storage/app/public', 'storage/app/invoices', 'public'] as $dir) {
            File::ensureDirectoryExists("{$this->root}/{$dir}", 0755);
        }
        foreach (['artisan', '.env', 'bootstrap/cache/config.php', 'storage/logs/laravel.log', 'storage/app/invoices/7.pdf', 'public/index.php'] as $file) {
            File::put("{$this->root}/{$file}", 'x');
            chmod("{$this->root}/{$file}", 0644);
        }
        chmod($this->root, 0755);
        exec('chown -R nobody:nobody ' . escapeshellarg($this->root));
        // Written by root (php artisan as root), in folders the app's user owns.
        File::put("{$this->root}/storage/logs/laravel-root.log", 'x');
        File::put("{$this->root}/storage/app/invoices/8.pdf", 'x');
        chmod("{$this->root}/storage/logs/laravel-root.log", 0644);
        chmod("{$this->root}/storage/app/invoices/8.pdf", 0644);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_a_file_owned_by_another_user_does_not_fail_the_deploy(): void
    {
        $script = sys_get_temp_dir() . '/mycs-adv322-' . bin2hex(random_bytes(4)) . '.sh';
        copy(base_path('deploy/private-files.sh'), $script);
        chmod($script, 0755);
        exec('runuser -u nobody -- bash ' . escapeshellarg($script) . ' ' . escapeshellarg($this->root) . ' 2>&1', $output, $code);
        @unlink($script);

        $this->assertSame(0, $code, "private-files.sh exited {$code} as the app's user, which fails deploy/update.sh in maintenance mode:\n" . implode("\n", $output));
    }
}
