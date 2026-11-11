<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use MyCS\Redfish\Machine;

/**
 * A physical machine (a Redfish server) as redfish:sync last read its BMC: one row a server, the last read only.
 *
 * @property array<string, mixed>|null $data
 * @property Carbon|null $read_at
 * @property Carbon|null $failed_at
 */
class RedfishMachine extends Model
{
    /** A read older than this is said to be old on the list (the cron reads every hour). */
    public const STALE_HOURS = 3;

    /** @var array{label: string, color: string, service: ?int}|null who has it, worked out once a row a request (the list asks three times) */
    public ?array $givenMemo = null;

    protected $fillable = ['server_id', 'power_state', 'health', 'watts', 'inlet_celsius', 'attention', 'data', 'read_at', 'failed_at', 'error'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    /** The last read, as the client gave it; null before the first one. */
    public function machine(): ?Machine
    {
        return is_array($this->data) ? Machine::fromArray($this->data) : null;
    }

    /** @return list<string> the parts to look at, as last read */
    public function attentionLines(): array
    {
        return array_values(array_filter(explode("\n", (string) $this->attention), fn (string $line) => $line !== ''));
    }

    /** Whether staff should look: never read, not read lately, the last read failed, or something is not OK. */
    public function needsALook(): bool
    {
        return $this->error !== null || $this->read_at === null || $this->read_at->lt(now()->subHours(self::STALE_HOURS))
            || ($this->health !== null && $this->health !== Machine::HEALTH_OK) || $this->attention !== null;
    }

    /**
     * Who has a machine: the live services on its server (the customer who rents it), none (free), or nobody ever
     * («Max Accounts» 0: our own).
     *
     * @return array{services: list<array{id: int, label: string}>, own: bool}
     */
    public static function givenTo(Server $server): array
    {
        $services = Service::query()->where('server_id', $server->id)
            ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED, Service::STATUS_PENDING])->with('client')->orderBy('id')->get()
            ->map(fn (Service $service) => ['id' => (int) $service->id,
                'label' => trim(($service->client->name ?? 'Client') . ' · service #' . $service->id . (filled($service->domain) ? ' · ' . $service->domain : ''))])
            ->values()->all();

        return ['services' => $services, 'own' => $server->max_accounts !== null && (int) $server->max_accounts === 0];
    }
}
