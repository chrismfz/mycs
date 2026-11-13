<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Review of #830: deploy/orion.sh, read only (it deploys to production, so it is never run here).
 * Each test names the finding it pins (REV-830-n).
 */
class Rev830Test extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/deploy/orion.sh');
    }

    /**
     * REV-830-1: `artisan down` runs in the same ssh call as the remote `git fetch` and `git checkout --detach $C`. A
     * commit the remote cannot reach (a local sha or branch of the build tree, not on GitHub), GitHub unreachable, or a
     * dirty tracked file the target changes (MultiPHP writes public/.htaccess, install.md) fails the checkout after
     * the site is down: set -e stops the script and the site stays in maintenance, with no message saying so.
     * The remote should fetch and check the commit (`git cat-file -e $C^{commit}`) before it goes down.
     */
    public function test_the_remote_has_the_commit_before_the_site_goes_down(): void
    {
        $s = $this->script();
        $down = strpos($s, 'artisan down');
        $check = strpos($s, 'cat-file -e');

        $this->assertNotFalse($down);
        $this->assertNotFalse($check, 'the remote never checks that it has the commit before `artisan down`');
        $this->assertLessThan($down, $check, 'the commit is checked only after the site is down');
    }

    /**
     * REV-830-2: `artisan migrate --force 2>&1 | tail -3` runs in the remote `bash -c` without pipefail: the pipe's
     * status is tail's (0), so a failed migration goes on to optimize, queue:restart and `up` (new code on the old
     * schema), and the script prints "deployed". The local `set -o pipefail` does not reach the remote shell.
     */
    public function test_a_failed_migration_stops_the_deploy(): void
    {
        foreach (explode("\n", $this->script()) as $line) {
            if (!str_contains($line, 'artisan migrate')) {
                continue;
            }
            $piped = (bool) preg_match('/artisan migrate.*?\s\|\s/', $line);
            $this->assertFalse($piped && !str_contains($line, 'pipefail'), 'migrate is piped without pipefail: its failure is lost');
        }
    }

    /**
     * REV-830-3: vendor is built on the devbox and copied, so composer's post-autoload-dump (package:discover, and
     * the clearing of bootstrap/cache/packages.php) never runs on orion. The previous release's packages.php stays,
     * and Laravel rebuilds it only when it is missing: after a dependency with a service provider is added or removed,
     * the app boots with a stale package list (a removed provider: class not found on every request and artisan call).
     */
    public function test_the_package_manifest_is_rebuilt_on_the_remote(): void
    {
        $s = $this->script();

        $this->assertTrue(
            str_contains($s, 'package:discover') || str_contains($s, 'bootstrap/cache/packages.php'),
            'the remote keeps the previous release\'s bootstrap/cache/packages.php',
        );
    }
}
