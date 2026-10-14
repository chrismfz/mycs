<?php

namespace App\Redactors;

use OwenIt\Auditing\Contracts\AttributeRedactor;

/** A secret in the audit log: nothing of it, not even its length (review ADV-L8, a licensing PSK). */
class FullRedactor implements AttributeRedactor
{
    public static function redact($value): string
    {
        return '********';
    }
}
