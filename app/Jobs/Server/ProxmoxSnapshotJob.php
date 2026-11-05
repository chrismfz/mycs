<?php

namespace App\Jobs\Server;

use App\Helpers\ExtensionHelper;
use App\Livewire\Services\ProxmoxPanel;
use App\Models\Service;
use App\Services\Proxmox\SnapshotOutdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use RuntimeException;
use Throwable;

/**
 * The customer's snapshot of a VPS (owner 2026-11-06), taken, rolled back to or deleted in the queue: the cluster's tasks
 * are waited for, longer than a page should. Tried once: the panel tells the customer how it went, and a failure is
 * reported for staff, never retried behind the customer's back (a rollback an hour late loses that hour's work).
 */
class ProxmoxSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public $tries = 1;

    public function __construct(public Service $service, public string $action, public bool $byCustomer = true) {}

    public function handle(): void
    {
        $outcome = 'failed';
        try {
            $server = $this->service->provisioningServer();
            $extension = $server === null ? null : ExtensionHelper::getExtension('server', $server->extension, $server->settings);
            if (!$extension instanceof Proxmox) {
                throw new RuntimeException("Service #{$this->service->id} is not on a Proxmox server any more.");
            }
            $extension->lifecycle($this->service)->snapshot($this->service, $this->action, $this->byCustomer);
            $outcome = 'done';
        } catch (SnapshotOutdated) {
            $outcome = 'changed'; // the customer's to know, not a fault
        } catch (Throwable $e) {
            report($e);
        } finally {
            $this->settle($outcome);
        }
    }

    /** Ended by the worker (its timeout, a restart): the panel is told, never left waiting on it (ADV-SNAP-5). */
    public function failed(?Throwable $e = null): void
    {
        $this->settle('failed');
    }

    /** Only the customer's own job tells their panel: staff's never ends a customer's job still running (ADV-SNAP-3). */
    private function settle(string $outcome): void
    {
        if ($this->byCustomer) {
            ProxmoxPanel::snapshotSettled($this->service, $this->action, $outcome);
        } else {
            ProxmoxPanel::refresh($this->service);
        }
    }
}
