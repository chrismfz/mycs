<?php

namespace App\Livewire\Admin;

use App\Admin\Pages\LiveChat;
use App\Enums\StaffPresenceStatus;
use App\Models\Staff;
use App\Support\LiveChats;
use App\Support\StaffChat;
use App\Support\StaffPresence;
use Illuminate\View\View;
use Livewire\Component;

/**
 * On every page of the admin (docs/livechat.md §7α, §7γ): every 10 seconds, also from a tab in the background, whether
 * the member has new messages in the staff chat. The browser then plays a short sound, shows a notification of the
 * operating system (who wrote, never what: F21), and puts the count in the tab's title. Nothing of it when the Live chat
 * page is in front, where the member sees the messages themselves.
 */
class StaffChatNotifier extends Component
{
    public function render(): View
    {
        $staff = auth('staff')->user();
        $news = $staff instanceof Staff ? StaffChat::news($staff) : ['total' => 0, 'latest' => 0, 'title' => null, 'with' => null];

        return view('livewire.admin.staff-chat-notifier', [
            'staffId' => $staff instanceof Staff ? $staff->id : 0,
            'news' => $news,
            'url' => LiveChat::getUrl($news['with'] !== null ? ['with' => $news['with']] : []),
            'page' => LiveChat::getUrl(),
            'customers' => $staff instanceof Staff ? LiveChats::alerts($staff) : LiveChats::NO_ALERTS,
            // The reminder of a waiting chat rings for an «Available» member only.
            'available' => $staff instanceof Staff && StaffPresence::status($staff) === StaffPresenceStatus::Available,
        ]);
    }
}
