<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property list<string>|null $admins
 *
 * A domain of a mailcow server, as mailcow:sync last read it, and what it is to us: a customer's service, internal, or not
 * mapped yet.
 */
class MailcowDomain extends Model
{
    protected $fillable = ['server_id', 'domain', 'active', 'mailboxes', 'mailbox_limit', 'aliases', 'alias_limit', 'quota_bytes', 'used_bytes',
        'messages', 'admins', 'last_seen_at', 'gone_at', 'service_id', 'internal', 'note'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'internal' => 'boolean', 'admins' => 'array', 'last_seen_at' => 'datetime', 'gone_at' => 'datetime'];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return HasMany<MailcowMailbox, $this> */
    public function mailboxList(): HasMany
    {
        return $this->hasMany(MailcowMailbox::class)->orderBy('address');
    }

    /**
     * The one live service this domain is on this server (its domain, the service on this server or its product's); none
     * when there is none or more than one, for staff to choose.
     */
    public function suggestedServiceId(): ?int
    {
        $ids = Service::query()->where('domain', $this->domain)
            ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, Service::STATUS_PENDING])
            ->where(fn ($q) => $q->where('server_id', $this->server_id)
                ->orWhere(fn ($q) => $q->whereNull('server_id')->whereHas('product', fn ($q) => $q->where('server_id', $this->server_id))))
            ->limit(2)->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }
}
