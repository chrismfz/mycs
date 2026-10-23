<?php

namespace App\Admin\Search;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * A global search result's title with its status as the lists show it (owner 2026-10-23, as WHMCS: «cdnet.gr ACTIVE»,
 * and two cdnet.gr told apart by it). The text is escaped; the badge is Filament's own.
 */
class SearchTitle
{
    public static function make(string $text, ?string $status = null, string $color = 'gray'): Htmlable
    {
        $badge = $status === null || $status === '' ? ''
            : Blade::render('<x-filament::badge :color="$color" size="sm" class="mycs-search-badge">{{ $status }}</x-filament::badge>',
                ['color' => $color, 'status' => $status]);

        return new HtmlString('<span class="mycs-search-title"><span class="mycs-search-text">' . e($text) . '</span>' . $badge . '</span>');
    }
}
