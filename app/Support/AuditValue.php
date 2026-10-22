<?php

namespace App\Support;

/**
 * An audited value as the audit views print it (always through Blade's escaping): an enum by its value, a list or an
 * object as JSON, nothing for null and false, the rest as before. A list printed as text made the views fail (review of
 * #351): roles' permissions, coupons' cycles, domains' meta and every custom audit with a list.
 */
final class AuditValue
{
    public static function text(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }
        if ($value === null || is_scalar($value)) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
