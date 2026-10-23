<?php

namespace App\Livewire\Services;

use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\Service;
use App\Services\Licensing\Licenses;
use App\Services\Licensing\MycsLicenseRepository;
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
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(fn (Builder $query) => $query->where('domain', 'like', $like)->orWhere('dedicated_ip', 'like', $like)
                ->orWhereHas('product', fn (Builder $query) => $query->where('name', 'like', $like))
                ->orWhereHas('license', fn (Builder $query) => $query->where('valid_ip', 'like', $like)->orWhere('license_key', 'like', $like))
                // Only the values a license reports may match: the others are not shown to the customer.
                ->orWhereHas('properties', fn (Builder $query) => $query->where('value', 'like', $like)
                    ->whereIn('key', self::reportedKeys())));
        }

        return view('services.index', [
            'services' => $query->paginate(config('settings.pagination')),
            'search' => $search,
        ])->layoutData([
            'title' => __('navigation.services'),
            'sidebar' => true,
        ]);
    }

    /** What tells the service apart in the list: its IP and the fields its license reports. @return array{ip: ?string, fields: array<string, string>} */
    public static function glance(Service $service): array
    {
        $ip = filled($service->license?->valid_ip) ? str_replace(',', ', ', (string) $service->license->valid_ip) : $service->dedicated_ip;

        return ['ip' => filled($ip) ? (string) $ip : null, 'fields' => Licenses::reportedFields($service)];
    }

    /** @return list<string> the field keys any license product of this account reports */
    private static function reportedKeys(): array
    {
        return CurrentClient::model()->services()->whereHas('license')->with('product.settings')->get()
            ->flatMap(fn (Service $service) => $service->product === null ? [] : MycsLicenseRepository::fieldNames(
                (string) (ExtensionHelper::settingsToArray($service->product->settings)['custom_fields'] ?? '')))
            ->unique()->values()->all();
    }
}
