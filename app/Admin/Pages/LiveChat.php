<?php

namespace App\Admin\Pages;

use App\Models\Staff;
use App\Support\StaffChat;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * «Live chat» (docs/livechat.md §7α), step 2 of §11: the team and the staff's shared room. The customers' queue and
 * chats come in step 3, on this same page.
 */
class LiveChat extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Support';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-chat-3-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-chat-3-fill';

    protected static string $routePath = 'live-chat';

    protected string $view = 'admin.pages.live-chat';

    public static function canAccess(): bool
    {
        $staff = Auth::user();

        return StaffChat::canView($staff instanceof Staff ? $staff : null);
    }

    public function getTitle(): string
    {
        return __('Live chat');
    }

    public static function getNavigationLabel(): string
    {
        return __('Live chat');
    }

    /** The room's messages from others not yet seen (§7γ «Αδιάβαστα»). */
    public static function getNavigationBadge(): ?string
    {
        $staff = Auth::user();
        $unread = $staff instanceof Staff ? StaffChat::unread($staff) : 0;

        return $unread > 0 ? (string) $unread : null;
    }
}
