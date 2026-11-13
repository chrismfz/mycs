<?php

namespace App\Rules;

use App\Exceptions\DisplayException;
use App\Services\Licensing\BulkUsage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A bulk product's or service's tiers, «1=1.80, 51=1.50», read as the bills read them (or empty). */
class BulkTiers implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (trim((string) $value) === '') {
            return;
        }
        try {
            BulkUsage::parseTiers((string) $value);
        } catch (DisplayException $e) {
            $fail($e->getMessage());
        }
    }
}
