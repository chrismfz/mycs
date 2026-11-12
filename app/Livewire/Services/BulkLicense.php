<?php

namespace App\Livewire\Services;

use App\Exceptions\DisplayException;
use App\Livewire\Component;
use App\Models\LicenseIp;
use App\Models\Service;
use App\Services\Licensing\BulkLicenseIps;
use App\Services\Licensing\BulkUsage;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
use MyCS\Pricing\Tiers;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A bulk license on the customer's service page (docs/zonecloud-bulk-license.md §7.1, mockup approved 2026-11-12): its
 * key, a summary, this month's billing, and the list of IPs to search, add (with a preview), rename, replace and remove,
 * one by one or many at once; a CSV of it and its recent changes. Who may see the service sees it; who manages it changes it.
 */
class BulkLicense extends Component
{
    use WithPagination;

    public const PER_PAGE = 50;

    /** An IP silent this long is flagged: paid for, not running (§5.1). */
    public const SILENT_DAYS = 7;

    /** Changes a minute per service: a customer's script never floods the list or its audit. */
    public const CHANGES_PER_MINUTE = 30;

    #[Locked]
    public Service $service;

    public string $search = '';

    /** all | silent | never */
    public string $filter = 'all';

    /** The add box's text, and what it would do (set by preview()). */
    public string $add = '';

    /** @var array{added: list<string>, kept: list<string>, warnings: list<string>, error: ?string, cost: ?string}|null */
    public ?array $preview = null;

    /** @var list<int> rows ticked for «Remove selected» */
    public array $selected = [];

    /** The row being renamed or replaced, and its new value. */
    public ?int $editing = null;

    public string $editLabel = '';

    public ?int $replacing = null;

    public string $replaceIp = '';

    public function mount(): void
    {
        $this->authorize('view', $this->service);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAdd(): void
    {
        $this->preview = null;
    }

    /** What «Add» would do: new, already there, the first bad entry, and the new monthly cost. */
    public function previewAdd(): void
    {
        $this->authorize('update', $this->service);
        $license = $this->license();
        try {
            $result = BulkLicenseIps::preview($license, $this->add, BulkLicenseIps::maxIps($license));
            $count = $license->ips()->active()->count() + count($result['added']);
            $this->preview = $result + ['error' => null, 'cost' => $this->cost($count), 'count' => $count];
        } catch (DisplayException $e) {
            $this->preview = ['added' => [], 'kept' => [], 'warnings' => [], 'error' => $e->getMessage(), 'cost' => null, 'count' => null];
        }
    }

    public function confirmAdd(): void
    {
        $this->authorize('update', $this->service);
        if (!$this->allowedNow()) {
            return;
        }
        $license = $this->license();
        try {
            $result = BulkLicenseIps::add($license, $this->add, BulkLicenseIps::maxIps($license));
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        $this->reset('add', 'preview');
        $this->notify(trans_choice('services.bulk_license.added', count($result['added']), ['count' => count($result['added'])]));
    }

    /** @param  list<int>|int  $ids */
    public function remove(array|int $ids): void
    {
        $this->authorize('update', $this->service);
        if (!$this->allowedNow()) {
            return;
        }
        $removed = BulkLicenseIps::remove($this->license(), array_map('intval', (array) $ids));
        $this->selected = [];
        $this->notify(trans_choice('services.bulk_license.removed', $removed, ['count' => $removed]));
    }

    public function removeSelected(): void
    {
        $this->remove($this->selected);
    }

    public function startEdit(int $id): void
    {
        $this->authorize('update', $this->service);
        $this->editing = $id;
        $this->editLabel = (string) $this->license()->ips()->whereKey($id)->value('label');
    }

    public function saveEdit(): void
    {
        $this->authorize('update', $this->service);
        if ($this->editing === null || !$this->allowedNow()) {
            return;
        }
        try {
            BulkLicenseIps::rename($this->license(), $this->editing, $this->editLabel);
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');
        }
        $this->reset('editing', 'editLabel');
    }

    public function startReplace(int $id): void
    {
        $this->authorize('update', $this->service);
        $this->replacing = $id;
        $this->replaceIp = '';
    }

    public function saveReplace(): void
    {
        $this->authorize('update', $this->service);
        if ($this->replacing === null || !$this->allowedNow()) {
            return;
        }
        $license = $this->license();
        try {
            BulkLicenseIps::replace($license, $this->replacing, $this->replaceIp, BulkLicenseIps::maxIps($license));
        } catch (DisplayException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        $this->reset('replacing', 'replaceIp');
        $this->notify(__('services.bulk_license.replaced'));
    }

    public function cancelEdit(): void
    {
        $this->reset('editing', 'editLabel', 'replacing', 'replaceIp');
    }

    /** The list as a CSV: IP, name, since, last good check. */
    public function csv(): StreamedResponse
    {
        $this->authorize('view', $this->service);
        $rows = $this->license()->ips()->active()->orderBy('ip')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ip', 'name', 'since', 'last_good_check'], escape: '');
            foreach ($rows as $row) {
                // A name typed by the customer opens as text in a spreadsheet, never as a formula.
                $label = (string) $row->label;
                fputcsv($out, [$row->ip, preg_match('/^[=+\-@\t\r]/', $label) ? "'" . $label : $label, $row->added_at->toDateTimeString(),
                    $row->last_ok_at?->toDateTimeString() ?? ''], escape: '');
            }
            fclose($out);
        }, 'license-' . $this->service->id . '-ips.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function license()
    {
        $license = $this->service->license;
        abort_if($license === null, 404);
        $license->setRelation('service', $this->service);

        return $license;
    }

    private function allowedNow(): bool
    {
        if (!in_array($this->service->status, [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED], true)) {
            $this->notify(__('services.bulk_license.not_running'), 'error');

            return false;
        }
        if (!RateLimiter::attempt('bulk-license:' . $this->service->id, self::CHANGES_PER_MINUTE, fn () => true, 60)) {
            $this->notify(__('services.bulk_license.too_many'), 'error');

            return false;
        }

        return true;
    }

    /** The monthly cost of $count IPs at its tiers, or null without tiers. */
    private function cost(int $count): ?string
    {
        try {
            $tiers = BulkUsage::tiers($this->service);
        } catch (DisplayException) {
            return null;
        }

        return (string) $tiers->cost($count)->toScale(2);
    }

    /** @return Collection<int, array{at: CarbonInterface, ip: string, label: ?string, added: bool}> the last changes */
    private function history(): Collection
    {
        $rows = $this->license()->ips()->latest('updated_at')->limit(40)->get();

        return $rows->flatMap(fn (LicenseIp $row) => array_filter([
            ['at' => $row->added_at, 'ip' => $row->ip, 'label' => $row->label, 'added' => true],
            $row->removed_at ? ['at' => $row->removed_at, 'ip' => $row->ip, 'label' => $row->label, 'added' => false] : null,
        ]))->sortByDesc('at')->take(20)->values();
    }

    public function render()
    {
        $license = $this->license();
        $silentSince = now()->subDays(self::SILENT_DAYS);
        $active = $license->ips()->active();
        $query = $license->ips()->active()->orderBy('ip')
            ->when(trim($this->search) !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('ip', 'like', '%' . addcslashes(trim($this->search), '\\%_') . '%')
                ->orWhere('label', 'like', '%' . addcslashes(trim($this->search), '\\%_') . '%')))
            ->when($this->filter === 'never', fn ($query) => $query->whereNull('last_ok_at'))
            ->when($this->filter === 'silent', fn ($query) => $query->whereNotNull('last_ok_at')->where('last_ok_at', '<', $silentSince));
        try {
            $tiers = BulkUsage::tiers($this->service);
        } catch (DisplayException) {
            $tiers = null;
        }
        try {
            $period = $tiers === null ? null : BulkUsage::thisPeriod($this->service);
        } catch (DisplayException) {
            $period = null;
        }

        return view('services.bulk-license', [
            'license' => $license,
            'rows' => $query->paginate(self::PER_PAGE),
            'counts' => [
                'listed' => (clone $active)->count(),
                'max' => BulkLicenseIps::maxIps($license),
                'day' => (clone $active)->where('last_ok_at', '>=', now()->subDay())->count(),
                'silent' => (clone $active)->whereNotNull('last_ok_at')->where('last_ok_at', '<', $silentSince)->count(),
                'never' => (clone $active)->whereNull('last_ok_at')->count(),
            ],
            'period' => $period,
            'tiers' => $tiers === null ? null : self::tiersLine($tiers),
            'history' => $this->history(),
            'silentSince' => $silentSince,
            'canManage' => auth()->user()?->can('update', $this->service) ?? false,
        ]);
    }

    /** «1.80 from the 1st · 1.50 from the 51st»: each step's first IP and price, read from a very long breakdown. */
    private static function tiersLine(Tiers $tiers): string
    {
        $parts = [];
        $from = 1;
        foreach ($tiers->breakdown(1_000_000) as [$units, $price]) {
            $parts[] = __('services.bulk_license.tier', ['price' => (string) $price, 'from' => $from]);
            $from += $units;
        }

        return implode(' · ', $parts);
    }
}
