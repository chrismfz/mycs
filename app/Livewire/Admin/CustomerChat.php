<?php

namespace App\Livewire\Admin;

use App\Admin\Resources\ClientResource;
use App\Models\LiveChat;
use App\Models\Staff;
use App\Support\LiveChats;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A customer's chat open on the Live chat page (docs/livechat.md §7α, step 3α). Seen by the operators of its department
 * (F3); written in only by the one who took it. Whatever id the browser sends, the right is checked on every call (F7).
 */
class CustomerChat extends Component
{
    #[Locked]
    public int $chatId;

    public string $body = '';

    public function claim(): void
    {
        $staff = $this->me();
        $chat = $this->chat();
        if ($staff !== null && $chat !== null && !LiveChats::claim($staff, $chat)) {
            $this->addError('body', __('Someone else took this chat.'));
        }
    }

    public function send(): void
    {
        $staff = $this->me();
        $chat = $this->chat();
        if ($staff === null || $chat === null) {
            return;
        }
        $refusal = LiveChats::postAsStaff($staff, $chat, $this->body);
        if ($refusal !== null) {
            $this->addError('body', $refusal);

            return;
        }
        $this->reset('body');
    }

    public function close(): void
    {
        $staff = $this->me();
        $chat = $this->chat();
        if ($staff !== null && $chat !== null) {
            LiveChats::closeAsStaff($staff, $chat);
        }
    }

    public function render(): View
    {
        $staff = $this->me();
        $chat = $this->chat();

        return view('livewire.admin.customer-chat', [
            'chat' => $chat,
            'me' => $staff,
            'messages' => $chat ? LiveChats::messages($chat) : collect(),
            'canReply' => $chat !== null && LiveChats::canReply($staff, $chat),
            'clientUrl' => $chat?->client && $staff?->hasPermission('admin.clients.viewAny') ? ClientResource::getUrl('view', ['record' => $chat->client]) : null,
        ]);
    }

    private function me(): ?Staff
    {
        $staff = auth('staff')->user();

        return $staff instanceof Staff ? $staff : null;
    }

    /** The chat, only when this member may see it. */
    private function chat(): ?LiveChat
    {
        $chat = LiveChat::query()->with(['client', 'department:id,name', 'operator'])->find($this->chatId);

        return $chat !== null && LiveChats::canSee($this->me(), $chat) ? $chat : null;
    }
}
