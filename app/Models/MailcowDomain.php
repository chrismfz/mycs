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
     * The one live service (active or suspended: an unpaid order claims nothing, review of #492) this domain is on this
     * server: its domain the service's (or its provisioned «domain» property), the service on this server or its
     * product's, and, when mailcow names the domain's admins and the service knows its own, that admin among them (the
     * link at mailcow). None when there is none or more than one, for staff to choose.
     */
    public function suggestedServiceId(): ?int
    {
        $services = Service::query()->with('properties')
            ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
            ->where(fn ($q) => $q->where('domain', $this->domain)
                ->orWhereHas('properties', fn ($q) => $q->where('key', 'domain')->where('value', $this->domain)))
            ->where(fn ($q) => $q->where('server_id', $this->server_id)
                ->orWhere(fn ($q) => $q->whereNull('server_id')->whereHas('product', fn ($q) => $q->where('server_id', $this->server_id))))
            ->limit(10)->get()
            ->filter(function (Service $service) {
                $admin = $service->properties->firstWhere('key', 'mailcow_username')->value ?? $service->username;

                return blank($admin) || blank($this->admins) || in_array($admin, (array) $this->admins, true);
            });

        return $services->count() === 1 ? (int) $services->first()->id : null;
    }
}
