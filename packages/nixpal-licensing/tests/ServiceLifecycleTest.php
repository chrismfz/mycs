<?php

namespace Nixpal\Licensing\Tests;

use Nixpal\Licensing\ServiceLifecycle;
use PHPUnit\Framework\TestCase;

final class ServiceLifecycleTest extends TestCase
{
    public function test_only_active_or_suspended_services_have_a_license_row()
    {
        $this->assertSame('Active', ServiceLifecycle::rowStatus('active'));
        $this->assertSame('Suspended', ServiceLifecycle::rowStatus('suspended'));
        $this->assertNull(ServiceLifecycle::rowStatus('pending'));
        $this->assertNull(ServiceLifecycle::rowStatus('in_review'));
        $this->assertNull(ServiceLifecycle::rowStatus('canceled'));
    }

    public function test_activating_a_pending_service_keeps_its_key()
    {
        $this->assertSame('ZC-KEY1', ServiceLifecycle::keyForActivation('pending', ['status' => 'active'], 'ZC-KEY1'));
        $this->assertSame('ZC-KEY1', ServiceLifecycle::keyForActivation('in_review', ['status' => 'active'], 'ZC-KEY1'));
    }

    public function test_other_edits_are_left_alone()
    {
        // not an activation
        $this->assertNull(ServiceLifecycle::keyForActivation('active', ['status' => 'active'], 'ZC-KEY1'));
        $this->assertNull(ServiceLifecycle::keyForActivation('pending', ['status' => 'canceled'], 'ZC-KEY1'));
        $this->assertNull(ServiceLifecycle::keyForActivation('pending', [], 'ZC-KEY1'));
        // staff typed a key on activation: theirs wins
        $this->assertNull(ServiceLifecycle::keyForActivation('pending', ['status' => 'active', 'license_key' => 'MINE'], 'ZC-KEY1'));
        // no key yet (not one of our services, or created before the module)
        $this->assertNull(ServiceLifecycle::keyForActivation('pending', ['status' => 'active'], ''));
    }
}
