<?php

namespace App\Livewire\Client;

use App\Models\LiveChat;
use App\Models\User;
use App\Support\CurrentClient;
use App\Support\LiveChats;
use Illuminate\View\View;
use Livewire\Component;

/**
 * The «Chat» bubble on every client-area page (docs/livechat.md §7, step 3α): a panel in the page, not a popup. The
 * browser holds only whether the panel is open, the department chosen and the draft; the chat is always the account's
 * own open one, found on the server on every call (F7). Nothing polls while the panel is closed.
 */
class ChatBubble extends Component
{
    public bool $open = false;

    public ?int $department = null;

    public string $body = '';

    public function toggle(): void
    {
        $this->open = !$this->open;
        $this->resetErrorBag();
    }

    public function start(): void
    {
        $user = $this->user();
        $refusal = $user === null ? __('Live chat is not available.') : LiveChats::start($user, (int) $this->department, $this->body);
        if ($refusal !== null) {
            $this->addError('body', $refusal);

            return;
        }
        $this->reset('body');
    }

    public function send(): void
    {
        $user = $this->user();
        $chat = $this->chat();
        if ($user === null || $chat === null) {
            return;
        }
        $refusal = LiveChats::postAsCustomer($user, $chat, $this->body);
        if ($refusal !== null) {
            $this->addError('body', $refusal);

            return;
        }
        $this->reset('body');
    }

    public function end(): void
    {
        $user = $this->user();
        $chat = $this->chat();
        if ($user !== null && $chat !== null) {
            LiveChats::endAsCustomer($user, $chat);
        }
    }

    public function render(): View
    {
        $may = LiveChats::customerMay($this->user());
        $chat = $may ? $this->chat() : null;
        // Offline, the bubble hides (owner 2026-10-27), but never in the middle of the account's own open chat.
        $available = $may && $chat === null ? LiveChats::availableDepartmentIds() : [];
        $shown = $may && ($chat !== null || $available !== []);
        $departments = $shown && $this->open && $chat === null ? LiveChats::departments()->whereIn('id', $available)->values() : collect();
        if ($this->department === null || !$departments->contains('id', $this->department)) {
            $this->department = $departments->first()?->id;
        }

        return view('livewire.client.chat-bubble', [
            'may' => $shown,
            'chat' => $chat,
            // The staff's notes (a take-over) are not the customer's (F20).
            'messages' => $chat ? LiveChats::customerMessages($chat) : collect(),
            'departments' => $departments,
            // Asked again on start: the cached list may be up to AVAILABILITY_TTL seconds old.
            'available' => $this->open && $chat === null && $this->department !== null && in_array($this->department, $available, true),
            'ticketsUrl' => $may && CurrentClient::allows('tickets') ? route('tickets.create') : null,
        ]);
    }

    private function user(): ?User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : null;
    }

    private function chat(): ?LiveChat
    {
        return LiveChats::customerMay($this->user()) ? LiveChats::openChatOf(CurrentClient::id()) : null;
    }
}
