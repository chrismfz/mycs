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
     * @param  array<string, mixed>|null  $changes
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(public Service $service, ?array $changes = null, ?array $original = null)
    {
        $this->changes = $changes ?? $service->getChanges();
        $this->original = $original ?? array_intersect_key($service->getRawOriginal(), $this->changes);
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
