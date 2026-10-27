<?php

namespace App\Admin\Pages;

use App\Models\Staff;
use App\Support\LiveChats;
use App\Support\StaffChat;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * «Live chat» (docs/livechat.md §7α), step 2 of §11: the team, the staff's shared room and private threads of two. The
 * customers' queue and chats come in step 3, on this same page.
 */
class LiveChat extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Support';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-chat-3-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-chat-3-fill';

    protected static string $routePath = 'live-chat';

    protected string $view = 'admin.pages.live-chat';

    /** The customer's chat open in the middle, or null for the staff's own chat. Each component checks its own right. */
    #[Url(as: 'chat')]
    public ?int $chat = null;

    /** Who of the staff to open when the staff's chat comes back in the middle. */
    public ?int $staffWith = null;

    #[On('customer-chat-open')]
    public function openChat(?int $chat = null): void
    {
        $this->chat = $chat;
    }

    /** A click on the team while a customer's chat is open brings the staff's chat back, on that member. */
    #[On('staff-chat-open')]
    public function openStaff(?int $with = null): void
    {
        $this->chat = null;
        $this->staffWith = $with;
    }

    /** The staff's chat, the customers' (step 3α), or both. */
    public static function canAccess(): bool
    {
        $staff = Auth::user();
        $staff = $staff instanceof Staff ? $staff : null;

        return StaffChat::canView($staff) || LiveChats::isOperator($staff);
    }

    public function getTitle(): string
    {
        return __('Live chat');
    }

    public static function getNavigationLabel(): string
    {
        return __('Live chat');
    }

    /** Messages from others not yet seen (§7γ «Αδιάβαστα»), and customers waiting in the member's departments. */
    public static function getNavigationBadge(): ?string
    {
        $staff = Auth::user();
        $unread = $staff instanceof Staff ? StaffChat::unread($staff) + LiveChats::waiting($staff) : 0;

        return match (true) {
            $unread === 0 => null,
            $unread > StaffChat::UNREAD_CAP => StaffChat::UNREAD_CAP . '+',
            default => (string) $unread,
        };
    }
}
