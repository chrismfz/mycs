<?php

namespace App\Support;

/** A graph's line as an SVG polyline's points, drawn on the server (no chart library on the customer's pages). */
final class Sparkline
{
    /**
     * @param  list<float|int|null>  $values  in time order; a missing point is skipped
     * @param  float|null  $max  the top of the graph (a limit: 100 for a percent); its highest value when none
     */
    public static function points(array $values, int $width = 300, int $height = 60, ?float $max = null): string
    {
        $count = count($values);
        $max ??= max([0.0, ...array_map('floatval', array_filter($values, fn ($v) => $v !== null))]);
        if ($count < 2 || $max <= 0) {
            return '';
        }
        $points = [];
        foreach ($values as $i => $value) {
            if ($value === null) {
                continue;
            }
            $x = round($i * $width / ($count - 1), 1);
            $y = round($height - min(1, max(0, (float) $value / $max)) * $height, 1);
            $points[] = "{$x},{$y}";
        }

        return implode(' ', $points);
    }
}
