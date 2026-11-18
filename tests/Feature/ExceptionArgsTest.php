<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Audit 2026-11-18: a trace never carries an argument (a password, a token), whatever the host's php.ini says. */
class ExceptionArgsTest extends TestCase
{
    private static function fails(string $secret): void
    {
        throw new \RuntimeException('failed');
    }

    public function test_a_trace_never_carries_the_arguments(): void
    {
        $this->assertSame('1', ini_get('zend.exception_ignore_args'));
        try {
            self::fails('hunter2-secret-password');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('hunter2', $e->getTraceAsString());
            $this->assertArrayNotHasKey('args', $e->getTrace()[0]);
        }
    }
}
