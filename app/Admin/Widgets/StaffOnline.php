<?php

namespace App\Admin\Widgets;

use App\Models\Staff;
use App\Support\StaffPresence;
use Filament\Widgets\Widget;
use Illuminate\View\View;

/**
 * «Staff online» on the dashboard (docs/livechat.md §7β), as in the WHMCS admin: each member with their avatar
 * (initials, never Gravatar), status and «now» or when last seen. Never an address, a location or a device.
 */
class StaffOnline extends Widget
{
    protected string $view = 'admin.widgets.staff-online';

    // Near the end, the width of the page, before Active users (the dashboard's two columns stay as they are, #279).
    protected static ?int $sort = 29;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public function render(): View
    {
        return view($this->view, ['roster' => StaffPresence::roster()]);
    }

    public static function canView(): bool
    {
        $staff = auth('staff')->user();

        return StaffPresence::canView($staff instanceof Staff ? $staff : null);
    }
}
