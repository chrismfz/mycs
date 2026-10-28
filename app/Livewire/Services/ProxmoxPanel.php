<?php

namespace App\Livewire\Services;

use App\Admin\Resources\ServiceResource\ProxmoxPower;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\ProxmoxGuest;
use App\Models\Service;
use App\Services\Proxmox\GuestLifecycle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use MyCS\Extensions\Servers\Proxmox\Proxmox;
use MyCS\Proxmox\ProxmoxClient;
use OwenIt\Auditing\Events\AuditCustom;
use Throwable;

/**
 * A VPS's own panel for the customer (owner 2026-10-28, Proxmox's client area): its state, CPU, memory and disk, its
 * addresses, its graph for an hour, a day or a week, and start, shut down, reboot and hard stop. Never while it is
 * suspended (its unsuspension starts it); the customer never reaches the cluster itself. Lazy: the page never waits on it.
 * The customer may start a VM staff stopped while the service is active (owner 2026-10-28: abuse is a suspension).
 */
#[Lazy]
class ProxmoxPanel extends Component
{
    #[Locked]
    public Service $service;

    public string $timeframe = 'hour';

    /** The state kept this long, the graph a minute, a refusal a minute (read once, told once: ADV-502-5). */
    private const KEEP_STATE = 15;

    private const KEEP_GRAPH = 60;

    private const KEEP_REFUSAL = 60;

    /** After an action the panel reads its state afresh, every 5 seconds, this long (the cluster does it meanwhile). */
    private const PENDING = 20;

    public function mount(): void
    {
        abort_unless($this->allowed('view'), 403);
    }

    public function power(string $action): void
    {
        abort_unless($this->allowed('update'), 403);
        if (!in_array($action, GuestLifecycle::POWER, true) || $this->service->fresh()?->status !== Service::STATUS_ACTIVE) {
            return;
        }
        $key = 'proxmox-power:' . $this->service->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->notify(__('services.proxmox.too_soon'), 'error');

            return;
        }
        $guest = ProxmoxPower::guest($this->service);
        $extension = $guest === null ? null : $this->extension();
        try {
            // No guest (unmapped since the page opened) is «not done», never told sent nor audited (ADV-502-2).
            $extension ?? throw new \RuntimeException("Service #{$this->service->id} has no Proxmox guest to act on.");
            $extension->lifecycle($this->service)->power($this->service, $action);
        } catch (Throwable $e) {
            report($e);
            RateLimiter::hit($key, 10); // a failure may be tried again soon, not hammered
            $this->notify(__('services.proxmox.not_done'), 'error');

            return;
        }
        RateLimiter::hit($key, 30);
        $this->service->auditEvent = 'vm_power';
        $this->service->isCustomEvent = true;
        $this->service->auditCustomOld = [];
        $this->service->auditCustomNew = ['action' => $action, 'user_id' => auth('web')->id(), 'vmid' => $guest->vmid];
        event(new AuditCustom($this->service));
        $this->forget($guest);
        Cache::put($this->pendingKey(), $action, self::PENDING);
        $this->notify(__('services.proxmox.sent.' . $action), 'success');
    }

    public function placeholder(): string
    {
        return '<div class="text-sm opacity-60 p-4">…</div>';
    }

    public function render()
    {
        if (!in_array($this->timeframe, ProxmoxClient::TIMEFRAMES, true)) {
            $this->timeframe = 'hour';
        }
        $vm = null;
        $pending = Cache::get($this->pendingKey());
        // Each render asks again who may see it (a grant revoked, a session ended: ADV-502-1), and which VM is the service's
        // now (a VM re-mapped is not shown to the service it left: ADV-502-4).
        $guest = $this->allowed('view') ? ProxmoxPower::guest($this->service) : null;
        if ($guest !== null && !Cache::has($this->refusalKey($guest)) && ($extension = $this->extension()) !== null) {
            try {
                $lifecycle = $extension->lifecycle($this->service);
                $state = $pending !== null ? $lifecycle->state($this->service)
                    : Cache::remember($this->stateKey($guest), self::KEEP_STATE, fn () => $lifecycle->state($this->service));
                $graph = Cache::remember($this->graphKey($guest, $this->timeframe), self::KEEP_GRAPH, fn () => $lifecycle->graph($this->service, $this->timeframe));
                $vm = $state + ['graph' => $graph];
            } catch (Throwable $e) {
                report($e);
                Cache::put($this->refusalKey($guest), true, self::KEEP_REFUSAL);
            }
        }

        return view('services.proxmox-panel', [
            'vm' => $vm,
            'pending' => $pending,
            'canPower' => $this->service->status === Service::STATUS_ACTIVE && $this->allowed('update'),
        ]);
    }

    private function allowed(string $ability): bool
    {
        return (bool) auth('web')->user()?->can($ability, $this->service);
    }

    /** After an action: every timeframe's read, not only the one shown (ADV-502-3). */
    private function forget(?ProxmoxGuest $guest): void
    {
        if ($guest === null) {
            return;
        }
        Cache::forget($this->stateKey($guest));
        Cache::forget($this->refusalKey($guest));
        foreach (ProxmoxClient::TIMEFRAMES as $timeframe) {
            Cache::forget($this->graphKey($guest, $timeframe));
        }
    }

    /** Keyed on the VM as well: a VM mapped to another service is not read from the one it left. */
    private function stateKey(ProxmoxGuest $guest): string
    {
        return "proxmox-panel.{$this->service->id}.{$guest->id}.{$guest->vmid}.state";
    }

    private function graphKey(ProxmoxGuest $guest, string $timeframe): string
    {
        return "proxmox-panel.{$this->service->id}.{$guest->id}.{$guest->vmid}.graph.{$timeframe}";
    }

    private function refusalKey(ProxmoxGuest $guest): string
    {
        return "proxmox-panel.{$this->service->id}.{$guest->id}.{$guest->vmid}.refused";
    }

    private function pendingKey(): string
    {
        return "proxmox-panel.{$this->service->id}.pending";
    }

    private function extension(): ?Proxmox
    {
        $server = $this->service->provisioningServer();
        $extension = $server === null ? null : ExtensionHelper::getExtension('server', $server->extension, $server->settings);

        return $extension instanceof Proxmox ? $extension : null;
    }
}
