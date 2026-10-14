<?php

namespace App\Exceptions;

/** The coupon cannot be used for this order (expired, used up, not for these products): the order is not placed. */
class CouponUnusableException extends DisplayException {}
