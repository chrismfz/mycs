<?php

namespace App\Exceptions;

/**
 * A saved method's charge that never reached the gateway's charge (an outage, a timeout or a rate limit before it, sweep 3
 * L-FAILURES-2): nothing was charged and nothing was declined. Kept as «not sent» (ChargeAttempt::STATUS_NOT_SENT): the
 * customer is not told the payment failed and the method's declines are not counted; the cron tries again as set.
 */
class ChargeNotSent extends DisplayException {}
