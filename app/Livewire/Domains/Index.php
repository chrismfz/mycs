<?php

namespace App\Livewire\Domains;

use App\Enums\DomainStatus;
use App\Livewire\Component;
use App\Livewire\Traits\RemembersFilter;
use App\Models\Domain;
use App\Models\Staff;
use App\Support\Actor;
use App\Support\CurrentClient;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * The customer's domains (D7-2, as WHMCS's «My Domains»): name, registration and expiry, auto renew, status; filtered
 * by active, expired (still recoverable) and ended. Each opens its page, where it is managed.
 */
class Index extends Component
{
    use RemembersFilter, WithPagination;

    public const FILTERS = [
        'active' => [DomainStatus::Active, DomainStatus::PendingRegister, DomainStatus::PendingTransfer],
        'expired' => [DomainStatus::Expired, DomainStatus::Grace, DomainStatus::Redemption],
        'ended' => [DomainStatus::Cancelled, DomainStatus::TransferredAway, DomainStatus::Deleted],
    ];

    #[Url('status', except: '')]
    public string $status = '';

    #[Url('q', except: '')]
    public string $search = '';

    /** An unknown filter (an old or crafted link, review R10) shows them all. */
    public function mount(): void
    {
        $this->recallFilter('domains');
        if (!isset(self::FILTERS[$this->status])) {
            $this->status = '';
        }
    }

    public function updatedStatus(): void
    {
        if (!isset(self::FILTERS[$this->status])) {
            $this->status = '';
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function filter(string $status): void
    {
        $this->status = isset(self::FILTERS[$status]) && $this->status !== $status ? $status : '';
        $this->rememberFilter('domains');
        $this->resetPage();
    }

    /**
     * Auto renew from its row (P4b), as on its page (Show::toggleAutoRenew): the account's own domain, with
     * `managedomains`; staff in «Login as client» need the admin permission.
     */
    public function toggleAutoRenew(int $id): void
    {
        $domain = CurrentClient::model()->domains()->whereKey($id)->firstOrFail();
        $this->authorize('update', $domain);
        abort_unless($this->staffMay(), 404);
        $domain->update(['auto_renew' => !$domain->auto_renew]);
        $this->notify(__($domain->auto_renew ? 'domains.portal.auto_renew_turned_on' : 'domains.portal.auto_renew_turned_off'), 'success');
    }

    private function staffMay(): bool
    {
        $actor = Actor::current();

        return !Actor::impersonating() || ($actor instanceof Staff && $actor->hasPermission('admin.domains.update'));
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

        $page = $query->paginate(config('settings.pagination'));
        $staffMay = $this->staffMay();

        return view('domains.index', [
            'domains' => $page,
            // Whose auto renew can be turned on or off from its row.
            'toggleable' => $page->getCollection()->filter(fn (Domain $domain) => $staffMay && Gate::allows('update', $domain))->modelKeys(),
            'counts' => $counts,
        ])->layoutData([
            'title' => __('domains.domains'),
            'sidebar' => true,
        ]);
    }
}
