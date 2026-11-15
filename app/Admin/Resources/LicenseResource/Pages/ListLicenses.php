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
     * The tabs' filters, one place for the list and the MCP («licenses»), so both count the same. Live (the default):
     * active, or still calling in the last QUIET_DAYS, what staff look at; Dormant: the rest, ended and not heard of for
     * QUIET_DAYS, kept (history, an answer of «Expired» rather than «Invalid» if one calls again) but out of the way
     * (owner 2026-11-15: no deleting). Silent: active but no good check in SILENT_DAYS. Expired but calling; went quiet
     * after expiry (ran past its end, then stopped, in the last QUIET_DAYS); signals (what the software said of itself).
     *
     * @return array<string, \Closure(Builder): Builder>
     */
    public static function filters(): array
    {
        $since = now()->subDays(LicenseResource::SILENT_DAYS);
        $heard = now()->subDays(LicenseResource::QUIET_DAYS);
        $live = fn (Builder $query) => $query->where(fn (Builder $q) => $q
            ->whereIn('status', [License::STATUS_ACTIVE, License::STATUS_REISSUED])->orWhere('last_access', '>=', $heard));
        $silent = fn (Builder $query) => $query->where('status', License::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('last_ok_at')->orWhere('last_ok_at', '<', $since));
        $calling = fn (Builder $query) => $query->whereIn('status', [License::STATUS_EXPIRED, License::STATUS_SUSPENDED])->where('last_access', '>=', $since);
        $quiet = fn (Builder $query) => $query->whereIn('status', [License::STATUS_EXPIRED, License::STATUS_SUSPENDED])
            ->where('last_access', '<', $since)->where('last_access', '>=', $heard)
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
            'live' => $live,
            'silent' => $silent,
            'calling' => $calling,
            'quiet' => $quiet,
            'signals' => $signals,
            // Written out rather than «not live»: NOT over a NULL last_access is NULL in SQL, and a license that never
            // called would be in neither tab.
            'dormant' => fn (Builder $query) => $query->whereNotIn('status', [License::STATUS_ACTIVE, License::STATUS_REISSUED])
                ->where(fn (Builder $q) => $q->whereNull('last_access')->orWhere('last_access', '<', $heard)),
        ];
    }

    public function getTabs(): array
    {
        $tab = fn (string $label, \Closure $filter, bool $badge = true) => Tab::make($label)->modifyQueryUsing($filter)
            ->badge($badge ? ($filter(License::query())->count() ?: null) : null);
        $filters = self::filters();

        return [
            'live' => $tab('Live', $filters['live'], false),
            'silent' => $tab('Silent', $filters['silent']),
            'calling' => $tab('Expired but calling', $filters['calling']),
            'quiet' => $tab('Went quiet after expiry', $filters['quiet']),
            'signals' => $tab('Signals', $filters['signals']),
            'dormant' => $tab('Dormant', $filters['dormant'], false),
            'all' => Tab::make('All'),
        ];
    }

    /** Live: what staff look at; the ended ones not heard of in QUIET_DAYS wait in «Dormant». */
    public function getDefaultActiveTab(): string|int|null
    {
        return 'live';
    }
}
