<?php

namespace App\Livewire\Admin;

use App\Models\LiveChat;
use App\Models\Staff;
use App\Support\LiveChats;
use Illuminate\View\View;
use Livewire\Component;

/**
 * «Queue» on the Live chat page (docs/livechat.md §7α, step 3α): customers' chats waiting in the member's departments,
 * and the member's own active ones. Only the departments that list the member (F3), checked on every poll.
 */
class ChatQueue extends Component
{
    public function claim(int $chatId): void
    {
        $staff = $this->me();
        $chat = LiveChat::query()->find($chatId);
        if ($staff === null || $chat === null) {
            return;
        }
        if (!LiveChats::claim($staff, $chat)) {
            $this->addError('queue', __('Someone else took this chat.'));

            return;
        }
        $this->dispatch('customer-chat-open', chat: $chat->id);
    }

    public function render(): View
    {
        $staff = $this->me();

        return view('livewire.admin.chat-queue', [
            'operator' => LiveChats::isOperator($staff),
            // Without `admin.chats.reply` the member only reads: no «Take» (review of #475).
            'reply' => (bool) $staff?->hasPermission('admin.chats.reply'),
            'queue' => $staff ? LiveChats::queue($staff) : collect(),
            'mine' => $staff ? LiveChats::mine($staff) : collect(),
        ]);
    }

    private function me(): ?Staff
    {
        $staff = auth('staff')->user();

        return $staff instanceof Staff ? $staff : null;
    }
}
