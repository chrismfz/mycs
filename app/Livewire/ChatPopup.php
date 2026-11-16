<?php

namespace App\Livewire;

use App\Models\LiveChat;
use App\Models\User;
use App\Services\Captcha\Altcha;
use App\Support\Actor;
use App\Support\ChatVisitor;
use App\Support\LiveChats;
use App\Support\VisitorChats;
use App\Support\VisitorChatTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The popup /chat that the launcher on our sites opens (docs/livechat.md §4, §7 «Επισκέπτης», step 4), on our own
 * origin: never framed, a window of its own (ChatPopupHeaders). Opening it changes nothing; a chat starts only from
 * the form, a Livewire call with its CSRF token (F17).
 *
 * - A customer signed in: their account's chat, the client area's own component (§3 Δ, §7), under who they are (G4).
 * - Staff in «Login as client»: nothing (F9).
 * - A visitor: «Sign in» or «Continue as a visitor»; then the form (department, optional name and email, message,
 *   ALTCHA), the chat, «The chat has ended». No one available: the guest ticket form, the message already in it.
 *
 * The visitor's chat is found from the token in the server session on every call (ChatVisitor): no chat id, token or
 * department the browser holds decides anything (F7). src and page are labels for the staff, checked when kept.
 */
class ChatPopup extends Component
{
    /** The launcher's labels and the department it asks for: read once, checked again when kept. */
    #[Locked]
    public ?string $src = null;

    #[Locked]
    public ?string $page = null;

    #[Locked]
    public ?int $wanted = null;

    /** Only which screen a visitor looks at: «choose» or «guest». Nothing is decided by it. */
    public string $screen = 'choose';

    public ?int $department = null;

    public string $name = '';

    public string $email = '';

    public string $message = '';

    public string $body = '';

    /** The ALTCHA widget's payload, checked once when the form is sent. */
    public string $captcha = '';

    public function mount(Request $request): void
    {
        $this->src = ChatVisitor::site(is_string($request->query('src')) ? $request->query('src') : null);
        $this->page = ChatVisitor::page(is_string($request->query('page')) ? $request->query('page') : null);
        $dept = $request->query('dept');
        $this->wanted = is_string($dept) && ctype_digit($dept) && strlen($dept) < 10 ? (int) $dept : null;
        $this->department = VisitorChats::departmentFor($this->wanted)?->id;
    }

    public function asGuest(): void
    {
        $this->screen = 'guest';
        $this->resetErrorBag();
    }

    /** «Sign in»: the normal login, back to this popup after it (G8: always this fixed address, never one from input). */
    public function login(): void
    {
        session()->put('url.intended', route('chat'));
        // Never «remember me» for a login begun here (G5).
        session()->put(ChatVisitor::LOGIN_KEY, true);
        $this->redirect(route('login'));
    }

    public function start(Altcha $altcha): void
    {
        if (!VisitorChats::may()) {
            return;
        }
        // Always our ALTCHA, whatever captcha the other forms use (F14); a solution counts once.
        if (!$altcha->verify($this->captcha)) {
            $this->captcha = '';
            $this->addError('captcha', __('auth.captcha_invalid'));

            return;
        }
        $this->captcha = '';
        $refusal = VisitorChats::start(['department' => $this->department, 'name' => $this->name, 'email' => $this->email,
            'message' => $this->message, 'src' => $this->src, 'page' => $this->page]);
        if ($refusal !== null) {
            $this->addError('message', $refusal);

            return;
        }
        // The session was renewed (F8), with a new CSRF token: the page loads again with it.
        $this->redirect(route('chat'));
    }

    public function send(): void
    {
        $chat = $this->visitorChat();
        if ($chat === null) {
            return;
        }
        $refusal = VisitorChats::post($chat, $this->body);
        if ($refusal !== null) {
            $this->addError('body', $refusal);

            return;
        }
        $this->reset('body');
    }

    public function end(): void
    {
        $chat = $this->visitorChat();
        if ($chat !== null) {
            VisitorChats::end($chat);
        }
    }

    /** After a login in this session: «Continue with the same operator as …» (§3 Δ), a POST. */
    public function continueAsCustomer(): void
    {
        $user = Auth::guard('web')->user();
        if (!$user instanceof User || Actor::impersonating()) {
            return;
        }
        $refusal = VisitorChatTools::confirm($user);
        if ($refusal !== null) {
            $this->addError('handoff', $refusal);
        }
    }

    public function declineHandoff(): void
    {
        $user = Auth::guard('web')->user();
        if ($user instanceof User && !Actor::impersonating()) {
            VisitorChatTools::decline($user);
        }
    }

    /** «New chat» after the notice that the last one ended: this session lets go of the old one. */
    public function newChat(): void
    {
        if ($this->visitorChat() === null) {
            ChatVisitor::forget();
        }
        $this->reset('message', 'body');
        $this->screen = 'guest';
    }

    public function render(): View
    {
        $user = Auth::guard('web')->user();
        $chat = null;
        $departments = collect();
        $handoff = $user instanceof User && !Actor::impersonating() ? VisitorChatTools::pending($user) : null;
        $mode = match (true) {
            Actor::impersonating() => 'impersonating',
            // Only the question of the move while it waits (G8): nothing is written as a visitor any more.
            $handoff !== null => 'handoff',
            $user instanceof User => LiveChats::customerMay($user) ? 'customer' : 'unavailable',
            // Off, or this address or session blocked (§3 Ε): only «not available», nothing to learn from.
            !VisitorChats::may() => 'unavailable',
            default => null,
        };
        if ($mode === null) {
            $chat = $this->visitorChat();
            if ($chat !== null) {
                $mode = 'chat';
            } elseif (ChatVisitor::ended()) {
                // στ: only that it ended, nothing of it (the token went with it, §3 Γ).
                $mode = 'ended';
            } elseif ($this->screen !== 'guest') {
                $mode = 'choose';
            } else {
                $departments = VisitorChats::availableDepartments();
                // δ: the guest ticket form, unless tickets are off (then only that no one is there).
                $mode = $departments->isNotEmpty() ? 'form' : (config('settings.tickets_disabled') ? 'nobody' : 'ticket');
                if (!$departments->contains('id', $this->department)) {
                    $this->department = VisitorChats::departmentFor($this->wanted)?->id;
                }
            }
        }

        return view('livewire.chat-popup', [
            'mode' => $mode,
            'user' => $user instanceof User ? $user : null,
            'chat' => $chat,
            'messages' => ($chat ?? $handoff) !== null ? ChatVisitor::messages($chat ?? $handoff) : collect(),
            'handoff' => $handoff,
            'departments' => $departments,
        ])->layout('layouts.popup', ['title' => __('Live chat')]);
    }

    private function visitorChat(): ?LiveChat
    {
        return VisitorChats::may() ? ChatVisitor::chat() : null;
    }
}
