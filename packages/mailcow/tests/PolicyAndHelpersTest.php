<?php

namespace Nixpal\Mailcow\Tests;

use Nixpal\Mailcow\Credentials;
use Nixpal\Mailcow\DomainLimits;
use Nixpal\Mailcow\Policy;
use Nixpal\Mailcow\Sso;
use Nixpal\Mailcow\Usage;
use PHPUnit\Framework\TestCase;

final class PolicyAndHelpersTest extends TestCase
{
    public function test_suspend_locks_a_single_domain_admin()
    {
        $policy = Policy::fromMeta(['lock_on_suspend' => 'true']);

        $this->assertTrue($policy->lockOnSuspend(['example.org']));
        $this->assertTrue($policy->lockOnSuspend([]));
    }

    public function test_multi_domain_admin_is_not_locked_unless_forced()
    {
        $this->assertFalse(Policy::fromMeta(['lock_on_suspend' => 'true'])->lockOnSuspend(['a.org', 'b.org']));
        $this->assertTrue(Policy::fromMeta(['lock_on_suspend' => 'true', 'force_lock_multi' => 'true'])->lockOnSuspend(['a.org', 'b.org']));
    }

    public function test_suspend_lock_can_be_turned_off()
    {
        $this->assertFalse(Policy::fromMeta(['lock_on_suspend' => 'false'])->lockOnSuspend(['a.org']));
    }

    public function test_terminate_defaults_to_lock_only()
    {
        $policy = Policy::fromMeta(['terminate_mode' => 'something']);

        $this->assertFalse($policy->deletesOnTerminate());
        $this->assertTrue($policy->lockOnTerminate(['a.org']));
        $this->assertFalse($policy->lockOnTerminate(['a.org', 'b.org']));
        $this->assertTrue(Policy::fromMeta(['terminate_mode' => 'delete_all'])->deletesOnTerminate());
        $this->assertFalse(Policy::fromMeta(['terminate_mode' => 'delete_all'])->lockOnTerminate(['a.org']));
    }

    public function test_limits_fall_back_to_defaults_and_report_problems()
    {
        $limits = DomainLimits::fromMeta(['aliases' => 'x', 'defquota' => 4096, 'maxquota' => 2048, 'quota' => 1024, 'rl_frame' => 'y']);

        $this->assertSame(100, $limits->aliases);
        $this->assertSame('s', $limits->rateFrame);
        $this->assertSame(['defquota_over_maxquota', 'maxquota_over_quota'], $limits->problems());
        $this->assertSame([], DomainLimits::fromMeta([])->problems());
    }

    public function test_sso_token_shapes()
    {
        $this->assertSame('tok', Sso::extractToken(['token' => ' tok ']));
        $this->assertSame('tok', Sso::extractToken([['msg' => ['token' => 'tok']]]));
        $this->assertSame('abcdef0123456789', Sso::extractToken([['type' => 'success', 'msg' => 'abcdef0123456789']]));
        $this->assertSame('', Sso::extractToken([['type' => 'success', 'msg' => 'sso token created']]));
        $this->assertSame('', Sso::extractToken('nope'));
    }

    public function test_sso_login_url()
    {
        $this->assertSame('https://m.example/domainadmin/?sso_token=a%2Bb', Sso::loginUrl('https://m.example/', '', 'a+b'));
        $this->assertSame('https://m.example/SOGo/?sso_token=t', Sso::loginUrl('https://m.example', 'SOGo/', 't'));
        $this->assertSame('https://m.example/x?y=1&sso_token=t', Sso::loginUrl('https://m.example', '/x?y=1', 't'));
    }

    public function test_username_from_domain()
    {
        $this->assertSame('example', Credentials::username('Example.org'));
        $this->assertSame('mc1and1', Credentials::username('1and1.gr'));
        $this->assertSame('ab00', Credentials::username('a-b.gr'));
        $this->assertSame('verylong', Credentials::username('verylongdomainname.com'));
        $taken = Credentials::username('example.org', function ($u) {
            return $u !== 'example';
        });
        $this->assertMatchesRegularExpression('/^example\d{4}$/', $taken);
    }

    public function test_password_has_every_character_class()
    {
        for ($i = 0; $i < 20; $i++) {
            $p = Credentials::password();
            $this->assertSame(16, strlen($p));
            $this->assertMatchesRegularExpression('/[a-z]/', $p);
            $this->assertMatchesRegularExpression('/[A-Z]/', $p);
            $this->assertMatchesRegularExpression('/[0-9]/', $p);
            $this->assertMatchesRegularExpression('/[^A-Za-z0-9]/', $p);
        }
    }

    public function test_usage_formatting_and_totals()
    {
        $this->assertSame('0 B', Usage::format(0));
        $this->assertSame('1.50 KiB', Usage::format(1536));
        $this->assertSame('2.00 GiB', Usage::format('2,147,483,648'));
        $this->assertSame(50.0, Usage::percent(512, 1024));
        $this->assertNull(Usage::percent(5, 0));
        $this->assertSame(
            ['used' => 30, 'quota' => 100, 'allocated' => 60, 'mailboxes' => 3, 'aliases' => 1],
            Usage::totals([
                ['domain' => ['bytes_total' => 10, 'max_quota_for_domain' => 40, 'quota_used_in_domain' => 20], 'mailboxes' => [1, 2], 'aliases' => [1]],
                ['domain' => ['bytes_total' => 20, 'max_quota_for_domain' => 60, 'quota_used_in_domain' => 40], 'mailboxes' => [1], 'aliases' => []],
            ])
        );
    }
}
