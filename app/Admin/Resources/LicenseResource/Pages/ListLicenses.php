<?php

namespace App\Admin\Resources\LicenseResource\Pages;

use App\Admin\Resources\LicenseResource;
use App\Models\License;
use App\Services\Licensing\LicenseTelemetry;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLicenses extends ListRecords
{
    protected static string $resource = LicenseResource::class;

    /**
     * All; active but silent (no good check in SILENT_DAYS); expired or suspended but still calling; and the ones that
     * went quiet: still running after their end (their last answer was «Expired» or «Suspended») and then stopped, in the
     * last QUIET_DAYS. Uninstalled, or something now keeps them running without asking (?tab=…).
     */
    public function getTabs(): array
    {
        $since = now()->subDays(LicenseResource::SILENT_DAYS);
        $silent = fn (Builder $query) => $query->where('status', License::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('last_ok_at')->orWhere('last_ok_at', '<', $since));
        $calling = fn (Builder $query) => $query->whereIn('status', [License::STATUS_EXPIRED, License::STATUS_SUSPENDED])->where('last_access', '>=', $since);
        $quiet = fn (Builder $query) => $query->whereIn('status', [License::STATUS_EXPIRED, License::STATUS_SUSPENDED])
            ->where('last_access', '<', $since)->where('last_access', '>=', now()->subDays(LicenseResource::QUIET_DAYS))
            // LicenseActivity writes «Expired (ip)» / «Suspended (ip)» when the answer was its end, and never clears it: an
            // older suspension followed by good checks is not «ran past its end» (review of #862, R1).
            ->where(fn (Builder $q) => $q->where('last_failure', 'like', License::STATUS_EXPIRED . ' (%')->orWhere('last_failure', 'like', License::STATUS_SUSPENDED . ' (%'))
            ->where(fn (Builder $q) => $q->whereNull('last_ok_at')->orWhereColumn('last_failure_at', '>', 'last_ok_at'))
            // A key that joined a bulk license answers as the bulk one from then on: its own row stays frozen (R2).
            ->whereNull('merged_at');

        // What the software said of itself in the last days (/licensing/v1/check): its token did not verify, it went back to
        // an older build, its clock is far from ours (a clock turned back keeps a local key valid), or a standalone license
        // runs in more than one place (two install ids of one product). Staff look: a reinstall shows as two for a few days.
        $recent = now()->subDays(LicenseResource::INSTALLS_DAYS);
        $signals = fn (Builder $query) => $query->where(fn (Builder $q) => $q
            ->whereHas('installs', fn (Builder $i) => $i->where('last_seen_at', '>=', $recent)->where(fn (Builder $flag) => $flag
                ->where('downgraded_at', '>=', $recent)->orWhere('token_check', 'bad')
                ->orWhere('clock_skew', '>', LicenseTelemetry::SKEW_FLAG)->orWhere('clock_skew', '<', -LicenseTelemetry::SKEW_FLAG)))
            ->orWhere(fn (Builder $single) => $single->whereDoesntHave('ips', fn (Builder $ips) => $ips->whereNull('removed_at'))
                ->whereExists(LicenseTelemetry::moreThanOneInstall($recent))));

        return [
            'all' => Tab::make('All'),
            'silent' => Tab::make('Silent')->modifyQueryUsing($silent)->badge($silent(License::query())->count() ?: null),
            'calling' => Tab::make('Expired but calling')->modifyQueryUsing($calling)->badge($calling(License::query())->count() ?: null),
            'quiet' => Tab::make('Went quiet after expiry')->modifyQueryUsing($quiet)->badge($quiet(License::query())->count() ?: null),
            'signals' => Tab::make('Signals')->modifyQueryUsing($signals)->badge($signals(License::query())->count() ?: null),
        ];
    }
}
