<?php

namespace App\Livewire\Services;

use App\Livewire\Component;
use App\Models\Service;
use App\Services\Licensing\Licenses;
use App\Support\CurrentClient;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * The account's services. Each with what tells it apart at a glance (owner 2026-10-23, as WHMCS's list): its IP (a
 * license's valid IPs, else its dedicated IP) and the fields its license reports (nixpal's «comments»); never staff's
 * notes. Searched by name, domain, IP or those fields: a customer with many licenses finds one.
 */
class Index extends Component
{
    use WithPagination;

    public $status = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = CurrentClient::model()->services()->orderBy('created_at', 'desc')
            ->with(['license', 'product.settings', 'properties', 'plan']);

        if ($this->status) {
            $query->where('status', $this->status);
        }
        $search = trim(mb_substr($this->search, 0, 100));
        if ($search !== '') {
            // «!» escapes, the same on SQLite and MariaDB (code review 4: a backslash is MariaDB's only).
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $like = fn (Builder $query, string $column, string $boolean = 'or') => $query->whereRaw("{$column} LIKE ? ESCAPE '!'", [$pattern], $boolean);
            $query->where(fn (Builder $query) => $like($like($like($query, 'services.label'), 'services.domain'), 'services.dedicated_ip')
                // Inside a relation the first is «and»: an «or» there would drop the relation's own constraint.
                ->orWhereHas('product', fn (Builder $query) => $like($query, 'products.name', 'and'))
                ->orWhereHas('license', fn (Builder $query) => $query->where(fn (Builder $query) => $like($like($query, 'licenses.valid_ip', 'and'), 'licenses.license_key')))
                // The fields a license reports, as the list shows them (code review 1: by each service's own product,
                // never a value it does not show).
                ->orWhereIn('services.id', self::matchingReportedFields($search)));
        }

        return view('services.index', [
            'services' => $query->paginate(config('settings.pagination')),
            'search' => $search,
        ])->layoutData([
            'title' => __('navigation.services'),
            'sidebar' => true,
        ]);
    }

    /** What tells the service apart in the list: its IP and the fields its license reports. @return array{ip: ?string, ip_is_license: bool, fields: array<string, string>} */
    public static function glance(Service $service): array
    {
        $licensed = filled($service->license?->valid_ip);
        $ip = $licensed ? str_replace(',', ', ', (string) $service->license->valid_ip) : $service->dedicated_ip;

        return ['ip' => filled($ip) ? (string) $ip : null, 'ip_is_license' => $licensed, 'fields' => Licenses::reportedFields($service)];
    }

    /** @return list<int> the account's services whose shown license fields contain $search (any case) */
    private static function matchingReportedFields(string $search): array
    {
        $needle = mb_strtolower($search);

        return CurrentClient::model()->services()->whereHas('license')->with(['license', 'product.settings', 'properties'])->get()
            ->filter(fn (Service $service) => collect(Licenses::reportedFields($service))->contains(fn (string $value) => str_contains(mb_strtolower($value), $needle)))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
