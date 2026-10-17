<?php

namespace App\Livewire\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Component;
use App\Support\CurrentClient;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * The customer's domains (D7-2, as WHMCS's «My Domains»): name, registration and expiry, auto renew, status; filtered
 * by active, expired (still recoverable) and ended. Each opens its page, where it is managed.
 */
class Index extends Component
{
    use WithPagination;

    public const FILTERS = [
        'active' => [DomainStatus::Active, DomainStatus::PendingRegister, DomainStatus::PendingTransfer],
        'expired' => [DomainStatus::Expired, DomainStatus::Grace, DomainStatus::Redemption],
        'ended' => [DomainStatus::Cancelled, DomainStatus::TransferredAway, DomainStatus::Deleted],
    ];

    #[Url('status', except: '')]
    public string $status = '';

    #[Url('q', except: '')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function filter(string $status): void
    {
        $this->status = isset(self::FILTERS[$status]) && $this->status !== $status ? $status : '';
        $this->resetPage();
    }

    public function render()
    {
        $domains = CurrentClient::model()->domains();
        $counts = [];
        foreach (self::FILTERS as $name => $statuses) {
            $counts[$name] = (clone $domains)->whereIn('status', array_map(fn (DomainStatus $s) => $s->value, $statuses))->count();
        }
        $query = $domains->orderByRaw('expires_at is null')->orderBy('expires_at')->orderBy('fqdn')
            ->when($this->status !== '', fn ($q) => $q->whereIn('status', array_map(fn (DomainStatus $s) => $s->value, self::FILTERS[$this->status])))
            ->when(trim($this->search) !== '', fn ($q) => $q->where('fqdn', 'like', '%' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($this->search))) . '%'));

        return view('domains.index', [
            'domains' => $query->paginate(config('settings.pagination')),
            'counts' => $counts,
        ])->layoutData([
            'title' => __('domains.domains'),
            'sidebar' => true,
        ]);
    }
}
