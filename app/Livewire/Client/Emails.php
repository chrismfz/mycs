<?php

namespace App\Livewire\Client;

use App\Livewire\Component;
use App\Models\EmailLog;
use App\Support\CurrentClient;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * «My emails» (WHMCS's client area «Emails», permission `emails`; owner 2026-10-22): what we sent the account, newest
 * first, each opened as it was sent, its secrets masked as the log keeps them (docs/mail.md). Sent ones only: one that
 * failed or still waits never reached anyone. Only rows of the mail audit (a template_key): older ones were logged
 * before secrets were masked (review of #311). A copy staff resent elsewhere than the original's address is not shown.
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
            ->whereNotNull('template_key')->where('template_key', '!=', 'system')
            ->where(fn (Builder $query) => $query->whereNull('resent_from_id')->orWhereExists(fn ($original) => $original->from('email_logs as original')
                ->whereColumn('original.id', 'email_logs.resent_from_id')->whereColumn('original.to', 'email_logs.to')));
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
            // Its links open in a new tab: the <base> after its doctype, not before it (quirks mode otherwise).
            'srcdoc' => $email === null ? null : (preg_match('/^\s*<!doctype[^>]*>/i', (string) $email->body, $doctype) === 1
                ? $doctype[0] . '<base target="_blank">' . substr((string) $email->body, strlen($doctype[0]))
                : '<base target="_blank">' . $email->body),
        ])->layoutData([
            'sidebar' => true,
            'title' => __('account.emails.title'),
        ]);
    }
}
