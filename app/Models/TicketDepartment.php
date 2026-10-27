<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MyCS\TicketMail\Imap\MailboxSettings;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * A ticket department (docs/tickets.md §5): WHMCS «Support», «Sales»…, with its own address and mailbox (A2). Hidden: not
 * offered to customers; clients only: no guest tickets in it. Its mailbox is read only with `polling` on; a reply joins
 * a ticket by our Message-ID, and with «Require DMARC» only when our server (`authserv_id`) vouches for the From.
 */
class TicketDepartment extends Model implements Auditable
{
    use Traits\Auditable;

    protected $fillable = ['name', 'sort', 'hidden', 'clients_only', 'email', 'imap_host', 'imap_port', 'imap_encryption', 'imap_username',
        'imap_password', 'imap_folder', 'authserv_id', 'require_dmarc', 'polling'];

    protected $hidden = ['imap_password'];

    /** Never in an audit (CLAUDE.md: no secrets). */
    protected $auditExclude = ['imap_password'];

    protected $casts = [
        'hidden' => 'boolean',
        'clients_only' => 'boolean',
        'sort' => 'integer',
        'imap_port' => 'integer',
        'imap_password' => 'encrypted',
        'require_dmarc' => 'boolean',
        'polling' => 'boolean',
    ];

    /** Its mailbox can be read: a host, a login and a password. */
    public function hasMailbox(): bool
    {
        return filled($this->imap_host) && filled($this->imap_username) && filled($this->imap_password);
    }

    public function mailboxSettings(): MailboxSettings
    {
        return new MailboxSettings((string) $this->imap_host, $this->imap_port ?: ($this->imap_encryption === 'starttls' ? 143 : 993),
            (string) $this->imap_encryption, (string) $this->imap_username, (string) $this->imap_password, $this->imap_folder ?: 'INBOX');
    }

    /** The addresses that are ours (its own and its login): a mail from them is a loop, never a ticket. */
    public function ownAddresses(): array
    {
        return array_values(array_filter(array_map(fn ($address) => strtolower(trim((string) $address)),
            [$this->email, str_contains((string) $this->imap_username, '@') ? $this->imap_username : null])));
    }

    /**
     * Staff who answer its live chats (docs/livechat.md F3): a list of its own, apart from the mail notifications. A
     * department with no one here takes no chats. @return BelongsToMany<Staff, $this>
     */
    public function chatStaff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'live_chat_department_staff');
    }

    /** Staff told of its new tickets and replies (when the ticket is assigned to no one). @return BelongsToMany<Staff, $this> */
    public function notifyStaff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class, 'ticket_department_staff');
    }

    /** @return HasMany<TicketPollRun, $this> */
    public function pollRuns(): HasMany
    {
        return $this->hasMany(TicketPollRun::class, 'department_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'department_id');
    }

    /**
     * What a customer may choose, in order: not hidden; for a guest, not clients only.
     *
     * @param  Builder<TicketDepartment>  $query
     */
    public function scopeOffered(Builder $query, bool $guest = false): void
    {
        $query->where('hidden', false)->when($guest, fn (Builder $query) => $query->where('clients_only', false))->orderBy('sort')->orderBy('id');
    }
}
