<?php

namespace App\Livewire\Services;

use App\Models\MailcowDomain;
use App\Models\Service;
use App\Services\Mailcow\MailSetup as Check;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A mail service's setup (owner 2026-10-28), for the customer on their service page and for staff on its admin page:
 * the DNS its domain needs, checked (✓/✗, with what to set), and its mailboxes with their quota and use as mailcow:sync
 * last read them. Reads only.
 */
class MailSetup extends Component
{
    #[Locked]
    public Service $service;

    /** Staff's (admin panel) or the customer's view. */
    #[Locked]
    public bool $admin = false;

    public function mount(): void
    {
        abort_unless($this->allowed(), 403);
    }

    /** Check the DNS now, not the one kept: once in 20 seconds per service. */
    public function recheck(): void
    {
        abort_unless($this->allowed(), 403);
        $domain = MailcowDomain::forService($this->service);
        if ($domain !== null && RateLimiter::attempt('mailcow-dns:' . $this->service->id, 1, fn () => true, 20)) {
            Check::check($domain, fresh: true);
        }
    }

    private function allowed(): bool
    {
        $user = $this->admin ? auth('staff')->user() : auth('web')->user();

        return $user !== null && $user->can('view', $this->service);
    }

    public function render()
    {
        $domain = MailcowDomain::forService($this->service);
        $check = $domain !== null ? Check::check($domain) : null;

        return view($this->admin ? 'admin.mailcow.mail-setup' : 'services.mail-setup', [
            'domain' => $domain,
            'check' => $check,
            'mailboxes' => $domain?->mailboxList()->get() ?? collect(),
        ]);
    }
}
