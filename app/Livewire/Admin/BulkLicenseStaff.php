<?php

namespace App\Livewire\Admin;

use App\Exceptions\DisplayException;
use App\Livewire\Services\BulkLicense;
use App\Models\Audit;
use App\Models\License;
use App\Models\LicenseIp;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Licensing\BulkLicenseIps;
use App\Services\Licensing\BulkUsage;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A bulk license on its service's admin page (docs/zonecloud-bulk-license.md §7.2, mockup approved 2026-11-13): what the
 * customer sees, plus the removed IPs, who did each change, the licenses moving in and the closed periods. Read only:
 * staff change the list with the page's own actions (Add IPs, Remove IPs, Move standalone licenses in, Edit license).
 */
class BulkLicenseStaff extends Component
{
    use WithPagination;

    public const PER_PAGE = 50;

    #[Locked]
    public Service $service;

    public string $search = '';

    /** all | silent | never | removed */
    public string $filter = 'all';

    public function mount(): void
    {
        abort_unless($this->allowed(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    private function allowed(): bool
    {
        return (bool) auth('staff')->user()?->can('view', $this->service);
    }

    public function placeholder(): string
    {
        return '<div class="text-sm opacity-60 p-4">…</div>';
    }

    public function render()
    {
        abort_unless($this->allowed(), 403);
        $license = $this->service->license;
        abort_if($license === null, 404);
        $license->setRelation('service', $this->service);
        $silentSince = now()->subDays(BulkLicense::SILENT_DAYS);
        $active = $license->ips()->active();
        $search = trim($this->search);
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $rows = $license->ips()
            ->when($this->filter === 'removed', fn ($query) => $query->whereNotNull('removed_at')->orderByDesc('removed_at'),
                fn ($query) => $query->active()->orderBy('ip'))
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->whereRaw("ip like ? escape '!'", [$like])->orWhereRaw("label like ? escape '!'", [$like])))
            ->when($this->filter === 'never', fn ($query) => $query->whereNull('last_ok_at'))
            ->when($this->filter === 'silent', fn ($query) => $query->whereNotNull('last_ok_at')->where('last_ok_at', '<', $silentSince))
            ->paginate(self::PER_PAGE);

        return view('admin.licensing.bulk-license', [
            'license' => $license,
            'rows' => $rows,
            'counts' => [
                'listed' => (clone $active)->count(),
                'max' => BulkLicenseIps::maxIps($license),
                'day' => (clone $active)->where('last_ok_at', '>=', now()->subDay())->count(),
                'silent' => (clone $active)->whereNotNull('last_ok_at')->where('last_ok_at', '<', $silentSince)->count(),
                'never' => (clone $active)->whereNull('last_ok_at')->count(),
                'removed' => $license->ips()->whereNotNull('removed_at')->count(),
            ],
            'tiers' => $this->tiers($license),
            'period' => $this->period(),
            'moving' => $this->moving($license),
            'history' => $this->history($license),
            'periods' => $license->usagePeriods()->with('adjustmentItem')->orderByDesc('period_start')->limit(12)->get(),
            // Still to bill, as billing reads it (BulkUsage::owed): its invoice cancelled or its line gone (review of #851).
            'owed' => BulkUsage::owed($license)->pluck('id')->all(),
            'silentSince' => $silentSince,
        ]);
    }

    /** @return array{line: ?string, own: bool, error: ?string} its prices as read, whose, or why they cannot be read */
    private function tiers(License $license): array
    {
        try {
            return ['line' => BulkLicense::tiersLine(BulkUsage::tiers($this->service)), 'own' => filled($license->tiers), 'error' => null];
        } catch (DisplayException $e) {
            return ['line' => null, 'own' => filled($license->tiers), 'error' => $e->getMessage()];
        }
    }

    /** @return array<string, mixed>|null this paid period, as the customer sees it */
    private function period(): ?array
    {
        try {
            return BulkUsage::thisPeriod($this->service);
        } catch (DisplayException) {
            return null;
        }
    }

    /** @return array{joining: int, next: ?CarbonInterface, joined: int} standalone licenses moving in (§8.1) */
    private function moving(License $license): array
    {
        $joining = $license->joining()->whereNull('merged_at')->with('service')->get()
            ->reject(fn (License $one) => $one->service?->isEnded() ?? true);

        return [
            'joining' => $joining->count(),
            'next' => $joining->map(fn (License $one) => $one->service?->expires_at)->filter()->min(),
            'joined' => $license->joining()->whereNotNull('merged_at')->count(),
        ];
    }

    /**
     * The last 30 changes of its list: added and removed, by whom (staff by name, the customer, or the system: the cron
     * when a standalone license joined), from the rows' own dates and their audits.
     *
     * @return Collection<int, array{at: ?Carbon, ip: string, label: ?string, added: bool, by: string}>
     */
    private function history(License $license): Collection
    {
        $added = $license->ips()->latest('added_at')->limit(30)->get();
        $removed = $license->ips()->whereNotNull('removed_at')->latest('removed_at')->limit(30)->get();
        $audits = Audit::query()->where('auditable_type', (new LicenseIp)->getMorphClass())
            ->whereIn('auditable_id', $added->pluck('id')->merge($removed->pluck('id'))->unique()->all())
            ->with('user')->orderBy('id')->get()->groupBy('auditable_id');
        $by = function (LicenseIp $row, bool $added) use ($audits): string {
            $audit = ($audits->get($row->id) ?? collect())->first(fn (Audit $audit) => $added
                ? $audit->event === 'created'
                : $audit->event === 'updated' && array_key_exists('removed_at', (array) $audit->new_values) && ($audit->new_values['removed_at'] ?? null) !== null);

            return match (true) {
                $audit?->user instanceof Staff => 'staff · ' . $audit->user->name,
                $audit?->user instanceof User => 'customer',
                default => 'system',
            };
        };

        return $added->map(fn (LicenseIp $row) => ['at' => $row->added_at, 'ip' => $row->ip, 'label' => $row->label, 'added' => true, 'by' => $by($row, true)])
            ->concat($removed->map(fn (LicenseIp $row) => ['at' => $row->removed_at, 'ip' => $row->ip, 'label' => $row->label, 'added' => false, 'by' => $by($row, false)]))
            ->sortByDesc('at')->take(30)->values();
    }
}
