<?php

namespace App\Livewire\Client;

use App\Models\LiveChat;
use App\Models\User;
use App\Support\CurrentClient;
use App\Support\LiveChats;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The «Chat» bubble on every client-area page (docs/livechat.md §7, step 3α): a panel in the page, not a popup. The
 * browser holds only whether the panel is open, the department chosen and the draft; the chat is always the account's
 * own open one, found on the server on every call (F7). Nothing polls while the panel is closed.
 */
class ChatBubble extends Component
{
    public bool $open = false;

    /** In the popup /chat (step 4): always open, a page of its own rather than a bubble on one. */
    #[Locked]
    public bool $popup = false;

    public ?int $department = null;

    public string $body = '';

    /**
     * The open chat this panel last showed: once it closes (the operator, the customer, idle), the panel says so instead of
     * silently offering a new one (step 3β). Locked, and read back only for the current account's own chat.
     */
    #[Locked]
    public ?int $shown = null;

    public function toggle(): void
    {
        $this->open = $this->popup || !$this->open;
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

    /** «Start a new chat» after the notice that the last one ended. */
    public function newChat(): void
    {
        $this->shown = null;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        if ($this->popup) {
            $this->open = true;
        }
        $may = LiveChats::customerMay($this->user());
        $chat = $may ? $this->chat() : null;
        $ended = $may && $chat === null && $this->shown !== null && LiveChats::endedChatOf(CurrentClient::id(), $this->shown) !== null;
        $this->shown = $chat !== null ? $chat->id : ($ended ? $this->shown : null);
        // Offline, the bubble hides (owner 2026-10-27), but never in the middle of the account's own open chat.
        $available = $may && $chat === null ? LiveChats::availableDepartmentIds() : [];
        $shown = $may && ($chat !== null || $ended || $available !== []);
        $departments = $shown && $this->open && $chat === null && !$ended ? LiveChats::departments()->whereIn('id', $available)->values() : collect();
        if ($this->department === null || !$departments->contains('id', $this->department)) {
            $this->department = $departments->first()?->id;
        }

        return view('livewire.client.chat-bubble', [
            // The popup says when no one is available, rather than showing nothing.
            'may' => $shown || ($this->popup && $may),
            'chat' => $chat,
            'ended' => $ended,
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
