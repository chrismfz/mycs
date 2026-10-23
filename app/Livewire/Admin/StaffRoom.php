<?php

namespace App\Livewire\Admin;

use App\Models\Staff;
use App\Models\StaffThread;
use App\Support\StaffChat;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The open conversation on the Live chat page (docs/livechat.md §7γ): the staff's room, or a private thread with one
 * member. The browser holds the draft and which member it talks to; the thread is always the one of this member and
 * that one, found on the server on every call (F7), so no one can open a thread of two others.
 */
class StaffRoom extends Component
{
    public string $body = '';

    /** The other member of a private thread, or null for the room. */
    #[Url(as: 'with')]
    public ?int $with = null;

    /** The page opened with `?with=` someone who cannot be written to says so too (review of #387). */
    public function mount(): void
    {
        $this->warnIfUnreachable();
    }

    /** The warning is for the request that opened the member; the next poll or send no longer shows it (review of #387). */
    public function hydrate(): void
    {
        $this->resetErrorBag('with');
    }

    #[On('staff-chat-open')]
    public function open(?int $with = null): void
    {
        $this->with = $with;
        $this->resetErrorBag();
        $this->warnIfUnreachable();
    }

    /** Say why, instead of quietly staying in the room (owner 2026-10-23). */
    private function warnIfUnreachable(): void
    {
        $staff = $this->me();
        if ($staff !== null && $this->with !== null && $this->thread($staff)->kind === StaffThread::ROOM) {
            $this->addError('with', __('This member cannot receive messages in the staff chat.'));
        }
    }

    public function send(): void
    {
        $staff = $this->me();
        $thread = $staff ? $this->thread($staff) : null;
        if ($staff === null || $thread === null) {
            return;
        }
        $refusal = StaffChat::post($staff, $thread, $this->body);
        if ($refusal !== null) {
            $this->addError('body', $refusal);

            return;
        }
        $this->reset('body');
    }

    /**
     * The browser calls this only while the tab is in front (review of #373): a poll from a tab in the background must
     * not mark the conversation read. It moves the member's own mark only, so the browser saying so is harmless.
     */
    public function markSeen(int $threadId, int $upTo): void
    {
        $staff = $this->me();
        $thread = $staff ? $this->thread($staff) : null;
        // Message ids run across every thread: a late call from the thread just left must not mark this one (review of #377).
        if ($staff !== null && $thread !== null && $thread->id === $threadId) {
            StaffChat::markRead($staff, $thread, min($upTo, (int) $thread->messages()->max('id')));
        }
    }

    public function render(): View
    {
        $staff = $this->me();
        $thread = $staff ? $this->thread($staff) : null;
        $peer = $staff && $thread?->kind === StaffChat::DIRECT ? StaffChat::peerOf($staff, $thread) : null;
        $former = $staff && $thread?->kind === StaffChat::DIRECT && $peer === null ? Staff::query()->find($this->with) : null;

        return view('livewire.admin.staff-room', [
            'canView' => $thread !== null,
            // A thread with someone who left is read-only (§7γ): its history stays, nothing more goes in.
            'canPost' => StaffChat::canPost($staff) && $former === null,
            'former' => $former,
            'me' => $staff,
            'thread' => $thread,
            'peer' => $peer,
            'messages' => $thread ? StaffChat::messages($thread) : collect(),
        ]);
    }

    private function me(): ?Staff
    {
        $staff = auth('staff')->user();

        return $staff instanceof Staff && StaffChat::canView($staff) ? $staff : null;
    }

    /** The room, or the thread of this member and the one chosen; back to the room when that one cannot be talked to. */
    private function thread(Staff $staff): StaffThread
    {
        if ($this->with === null) {
            return StaffChat::room();
        }
        $peer = Staff::query()->find($this->with);
        $thread = $peer instanceof Staff ? (StaffChat::direct($staff, $peer) ?? StaffChat::formerDirect($staff, $peer->id)) : null;
        if ($thread === null) {
            $this->with = null;

            return StaffChat::room();
        }

        return $thread;
    }
}
