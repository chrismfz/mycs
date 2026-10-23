<?php

namespace Tests\Feature;

use App\Services\Billing\RenewalBilling;
use Tests\TestCase;

/**
 * Adversarial review of the paid-not-applied setting (BIL-9). A value that is not a number (a row saved empty or by
 * hand with app:settings:change, which validates nothing; the Saved event puts the raw string into config) must fall
 * back to the default 30 days, not shrink the protection to a single day: that fails unsafe, suspending customers who
 * paid the next night.
 */
class PaidNotAppliedDaysSettingAdversarialTest extends TestCase
{
    public function test_an_empty_setting_keeps_the_default_protection(): void
    {
        config(['settings.cronjob_paid_not_applied_days' => '']);
        $this->assertSame(RenewalBilling::PAID_NOT_APPLIED_DAYS, RenewalBilling::paidNotAppliedDays(), 'empty string');

        config(['settings.cronjob_paid_not_applied_days' => null]);
        $this->assertSame(RenewalBilling::PAID_NOT_APPLIED_DAYS, RenewalBilling::paidNotAppliedDays(), 'null');
    }

    public function test_a_value_that_is_not_a_number_keeps_the_default_protection(): void
    {
        config(['settings.cronjob_paid_not_applied_days' => 'thirty']);
        $this->assertSame(RenewalBilling::PAID_NOT_APPLIED_DAYS, RenewalBilling::paidNotAppliedDays());
    }
}
