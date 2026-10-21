<?php

namespace App\Livewire\Client;

use App\Livewire\Component;
use App\Models\EmailLog;
use App\Models\Staff;
use App\Support\CurrentClient;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * «My emails» (WHMCS's client area «Emails», permission `emails`; owner 2026-10-22): what we sent the account, newest
 * first, each opened as it was sent, its secrets masked as the log keeps them (docs/mail.md). Sent ones only: one that
 * failed or still waits never reached anyone. Not staff's own notices, nor a copy staff resent to themselves.
 */
class Emails extends Component
{
    use WithPagination;

    /** The open one, by its id; only one of this account's is ever shown. */
    #[Url(as: 'open', history: true)]
    public ?int $open = null;

    /** @return Builder<EmailLog> */
    public static function forAccount(int $clientId): Builder
    {
        return EmailLog::query()->where('client_id', $clientId)->where('status', EmailLog::STATUS_SENT)
            ->where(fn (Builder $query) => $query->whereNull('template_key')->orWhere('template_key', '!=', 'system'))
            ->whereNotIn('to', Staff::query()->select('email'));
    }

    public function show(int $id): void
    {
        $this->open = $id;
    }

    public function close(): void
    {
        $this->open = null;
    }

    public function render()
    {
        $mails = self::forAccount(CurrentClient::id());
        $email = $this->open === null ? null : (clone $mails)->whereKey($this->open)->first();

        return view('client.account.emails', [
            'emails' => $mails->select(['id', 'subject', 'to', 'sent_at', 'created_at'])->latest('id')
                ->paginate(config('settings.pagination', 15)),
            'email' => $email,
        ])->layoutData([
            'sidebar' => true,
            'title' => __('account.emails.title'),
        ]);
    }
}
