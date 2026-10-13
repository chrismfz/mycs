<?php

namespace App\Events\Service;

use App\Models\Service;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class Updated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * What this save changed: new values and the values before it.
     *
     * The event is dispatched after commit, when the model's originals are already synced (isDirty() is false) and a
     * later save in the same transaction has replaced getChanges(). Listeners read these instead.
     *
     * @var array<string, mixed>
     */
    public array $changes;

    /** @var array<string, mixed> */
    public array $original;

    /**
     * Create a new event instance.
     *
     * Without $changes/$original the event reads them from the model, which is right only while the save is running
     * (inside the observer's updated()); ServiceObserver always passes them.
     *
     * @param  array<string, mixed>|null  $changes
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(public Service $service, ?array $changes = null, ?array $original = null)
    {
        $this->changes = $changes ?? $service->getChanges();
        $this->original = $original ?? array_intersect_key($service->getRawOriginal(), $this->changes);
    }

    /**
     * This save cancelled the service and it is still cancelled in the database. The row, not this model: another
     * instance may have reactivated it in the same transaction.
     */
    public function cancelledBySave(): bool
    {
        return ($this->changes['status'] ?? null) === Service::STATUS_CANCELLED
            && Service::query()->whereKey($this->service->getKey())->value('status') === Service::STATUS_CANCELLED;
    }

    public function changed(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->changes)) {
                return true;
            }
        }

        return false;
    }
}
