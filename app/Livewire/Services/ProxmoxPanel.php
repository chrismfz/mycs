<?php

namespace App\Livewire\Services;

use App\Admin\Resources\ServiceResource\ProxmoxPower;
use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
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
 */
#[Lazy]
class ProxmoxPanel extends Component
{
    #[Locked]
    public Service $service;

    public string $timeframe = 'hour';

    /** A minute's read kept: a page left open, or reloaded, does not ask the cluster each time. */
    private const KEEP = 60;

    public function mount(): void
    {
        abort_unless(auth('web')->user()?->can('view', $this->service), 403);
    }

    public function power(string $action): void
    {
        abort_unless(auth('web')->user()?->can('update', $this->service), 403);
        if (!in_array($action, GuestLifecycle::POWER, true) || $this->service->status !== Service::STATUS_ACTIVE) {
            return;
        }
        // One a half minute a VM: a click held, or a script, does not hammer it.
        if (!RateLimiter::attempt('proxmox-power:' . $this->service->id, 1, fn () => true, 30)) {
            $this->notify(__('services.proxmox.too_soon'), 'error');

            return;
        }
        try {
            $this->extension()?->lifecycle($this->service)->power($this->service, $action);
        } catch (Throwable $e) {
            report($e);
            $this->notify(__('services.proxmox.not_done'), 'error');

            return;
        }
        $this->service->auditEvent = 'vm_power';
        $this->service->isCustomEvent = true;
        $this->service->auditCustomOld = [];
        $this->service->auditCustomNew = ['action' => $action, 'user_id' => auth('web')->id()];
        event(new AuditCustom($this->service));
        Cache::forget($this->key());
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
        try {
            $vm = Cache::remember($this->key(), self::KEEP, fn () => $this->extension()?->lifecycle($this->service)->overview($this->service, $this->timeframe));
        } catch (Throwable $e) {
            report($e);
            $vm = null;
        }

        return view('services.proxmox-panel', [
            'vm' => $vm,
            'canPower' => $this->service->status === Service::STATUS_ACTIVE && (bool) auth('web')->user()?->can('update', $this->service),
        ]);
    }

    private function key(): string
    {
        return "proxmox-panel.{$this->service->id}.{$this->timeframe}";
    }

    private function extension(): ?Proxmox
    {
        if (ProxmoxPower::guest($this->service) === null) {
            return null;
        }
        $server = $this->service->provisioningServer();
        $extension = $server === null ? null : ExtensionHelper::getExtension('server', $server->extension, $server->settings);

        return $extension instanceof Proxmox ? $extension : null;
    }
}
