<?php

namespace App\Models;

use App\Services\Import\WhmcsProxmoxImport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<string>|null $macs
 * @property list<string>|null $ips
 * @property list<string>|null $tags
 *
 * A VM or container of a Proxmox cluster, as proxmox:sync last read it, and what it is to us: a customer's service,
 * internal, or not mapped yet.
 */
class ProxmoxGuest extends Model
{
    protected $fillable = ['server_id', 'vmid', 'type', 'node', 'name', 'status', 'template', 'cpus', 'max_memory', 'max_disk',
        'uptime', 'macs', 'ips', 'tags', 'last_seen_at', 'gone_at', 'service_id', 'internal', 'note', 'pool', 'onboot_before_suspend'];

    protected function casts(): array
    {
        return ['template' => 'boolean', 'internal' => 'boolean', 'macs' => 'array', 'ips' => 'array', 'tags' => 'array',
            'last_seen_at' => 'datetime', 'gone_at' => 'datetime', 'onboot_before_suspend' => 'boolean'];
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

    /**
     * The service it most likely is, for staff to confirm: one whose dedicated IP is one of its addresses, or whose
     * machine details (Offline VM) hold one of its MACs, or whose domain is its name.
     */
    public function suggestedServiceId(): ?int
    {
        $live = fn () => Service::query()->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, Service::STATUS_PENDING]);
        // WHMCS's module's VMID for one live service (WhmcsProxmoxImport): a suggestion staff confirm, never a mapping.
        $byWhmcs = Property::query()->where('model_type', (new Service)->getMorphClass())->where('key', WhmcsProxmoxImport::WHMCS_VMID)
            ->where('value', (string) $this->vmid)->whereIn('model_id', $live()->select('id'))->pluck('model_id');
        if ($byWhmcs->count() === 1) {
            return (int) $byWhmcs->first();
        }
        foreach ((array) $this->ips as $ip) {
            if ($id = $live()->where('dedicated_ip', $ip)->value('id')) {
                return (int) $id;
            }
        }
        foreach ((array) $this->macs as $mac) {
            $id = Property::query()->where('model_type', (new Service)->getMorphClass())->where('key', 'machine_mac')
                ->where('value', 'like', '%' . $mac . '%')->whereIn('model_id', $live()->select('id'))->value('model_id');
            if ($id) {
                return (int) $id;
            }
        }
        if (filled($this->name) && str_contains((string) $this->name, '.')) {
            $id = $live()->where('domain', $this->name)->value('id');
        }

        return isset($id) && $id ? (int) $id : null;
    }

    /** Neither a service nor internal: still to be looked at. Templates are not guests anyone runs. */
    public function scopeUnmapped(Builder $query): void
    {
        self::whereUnmapped($query);
    }

    public static function whereUnmapped(Builder $query): Builder
    {
        return $query->whereNull('service_id')->where('internal', false)->where('template', false);
    }
}
