<?php

namespace App\Support;

use OwenIt\Auditing\Contracts\Audit;

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

        // Invalid UTF-8 in a value prints as null: for display only, never an error.
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * An audit's changes as the views list them, each key with its «old» and «new». The package casts the stored values
     * back through the model's casts, which fails on a value kept redacted where the column is encrypted (a service's
     * password, «********»: review of #352): then the values as stored, never decrypted.
     *
     * @return array<string, array{old?: mixed, new?: mixed}>
     */
    public static function modified(Audit $audit): array
    {
        try {
            return $audit->getModified();
        } catch (\Throwable) {
            $old = (array) ($audit->old_values ?? []);
            $new = (array) ($audit->new_values ?? []);
            $modified = [];
            foreach (array_unique([...array_keys($new), ...array_keys($old)]) as $key) {
                if (array_key_exists($key, $new)) {
                    $modified[$key]['new'] = $new[$key];
                }
                if (array_key_exists($key, $old)) {
                    $modified[$key]['old'] = $old[$key];
                }
            }

            return $modified;
        }
    }
}
