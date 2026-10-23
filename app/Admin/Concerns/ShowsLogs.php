<?php

namespace App\Admin\Concerns;

use App\Admin\Logs;
use Filament\Pages\Enums\SubNavigationPosition;

/** A log page: all the logs on its left (App\Admin\Logs). */
trait ShowsLogs
{
    public function getSubNavigation(): array
    {
        return Logs::navigation();
    }

    public static function getSubNavigationPosition(): SubNavigationPosition
    {
        return SubNavigationPosition::Start;
    }
}
