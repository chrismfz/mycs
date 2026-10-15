<?php

namespace App\Admin\Resources\LicenseResource\Pages;

use App\Admin\Resources\LicenseResource;
use App\Models\License;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLicenses extends ListRecords
{
    protected static string $resource = LicenseResource::class;

    /** All; active but silent (no good check in SILENT_DAYS); expired or suspended but still calling (?tab=…). */
    public function getTabs(): array
    {
        $since = now()->subDays(LicenseResource::SILENT_DAYS);
        $silent = fn (Builder $query) => $query->where('status', License::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('last_ok_at')->orWhere('last_ok_at', '<', $since));
        $calling = fn (Builder $query) => $query->whereIn('status', [License::STATUS_EXPIRED, License::STATUS_SUSPENDED])->where('last_access', '>=', $since);

        return [
            'all' => Tab::make('All'),
            'silent' => Tab::make('Silent')->modifyQueryUsing($silent)->badge($silent(License::query())->count() ?: null),
            'calling' => Tab::make('Expired but calling')->modifyQueryUsing($calling)->badge($calling(License::query())->count() ?: null),
        ];
    }
}
